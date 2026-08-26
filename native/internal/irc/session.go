package irc

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"regexp"
	"strings"
)

type Channel struct {
	Name     string
	Password string
}

type SessionConfig struct {
	Nickname      string
	Username      string
	RealName      string
	Password      string
	Channels      []Channel
	MaxLines      int
	MaxCandidates int
}

type SessionReport struct {
	Lines      int  `json:"lines"`
	Messages   int  `json:"messages"`
	Candidates int  `json:"candidates"`
	Ignored    int  `json:"ignored"`
	Unmatched  int  `json:"unmatched"`
	Pings      int  `json:"pings"`
	Joins      int  `json:"joins"`
	LoggedIn   bool `json:"logged_in"`
}

var (
	pingPattern       = regexp.MustCompile(`^PING\s*:(.+?)$`)
	loginReadyPattern = regexp.MustCompile(`^:(.*?)\s+001\s+`)
)

func RunSession(ctx context.Context, connection io.ReadWriter, cfg SessionConfig, opts ParseOptions) (SessionReport, []Candidate, error) {
	if err := validateSessionConfig(cfg); err != nil {
		return SessionReport{}, nil, err
	}
	if cfg.Password != "" {
		if err := writeIRCLine(connection, "PASS "+cfg.Password); err != nil {
			return SessionReport{}, nil, err
		}
	}
	if err := writeIRCLine(connection, "NICK "+cfg.Nickname); err != nil {
		return SessionReport{}, nil, err
	}
	if err := writeIRCLine(connection, "USER "+cfg.Username+" 0 * :"+cfg.RealName); err != nil {
		return SessionReport{}, nil, err
	}

	scanner := bufio.NewScanner(connection)
	scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	var report SessionReport
	candidates := []Candidate{}
	joined := false
	for scanner.Scan() {
		select {
		case <-ctx.Done():
			return report, candidates, ctx.Err()
		default:
		}

		report.Lines++
		line := strings.TrimSpace(stripControlCharacters(scanner.Text()))
		if line == "" {
			continue
		}

		if matches := pingPattern.FindStringSubmatch(line); matches != nil {
			report.Pings++
			if err := writeIRCLine(connection, "PONG "+matches[1]); err != nil {
				return report, candidates, err
			}
			continue
		}

		if loginReadyPattern.MatchString(line) {
			report.LoggedIn = true
			if !joined {
				for _, channel := range cfg.Channels {
					command := "JOIN " + channel.Name
					if channel.Password != "" {
						command += " " + channel.Password
					}
					if err := writeIRCLine(connection, command); err != nil {
						return report, candidates, err
					}
					report.Joins++
				}
				joined = true
			}
			continue
		}

		message := line
		if matches := namedMatches(rawPrivmsgPattern, line); len(matches) > 0 {
			message = matches["message"]
			report.Messages++
		}

		candidate, ignored, ok, err := ParseMessage(message, opts)
		if err != nil {
			return report, candidates, err
		}
		if ignored {
			report.Ignored++
		} else if ok {
			report.Candidates++
			candidates = append(candidates, candidate)
		} else {
			report.Unmatched++
		}

		if cfg.MaxCandidates > 0 && report.Candidates >= cfg.MaxCandidates {
			return report, candidates, nil
		}
		if cfg.MaxLines > 0 && report.Lines >= cfg.MaxLines {
			return report, candidates, nil
		}
	}
	if err := scanner.Err(); err != nil {
		return report, candidates, fmt.Errorf("read irc session: %w", err)
	}

	return report, candidates, nil
}

func validateSessionConfig(cfg SessionConfig) error {
	if strings.TrimSpace(cfg.Nickname) == "" {
		return fmt.Errorf("irc nickname is required")
	}
	if strings.TrimSpace(cfg.Username) == "" {
		return fmt.Errorf("irc username is required")
	}
	if strings.TrimSpace(cfg.RealName) == "" {
		return fmt.Errorf("irc real name is required")
	}
	for _, channel := range cfg.Channels {
		if strings.TrimSpace(channel.Name) == "" {
			return fmt.Errorf("irc channel name is required")
		}
	}

	return nil
}

func writeIRCLine(writer io.Writer, line string) error {
	if _, err := fmt.Fprintf(writer, "%s\r\n", line); err != nil {
		return fmt.Errorf("write irc command: %w", err)
	}

	return nil
}
