package irc

import (
	"bufio"
	"bytes"
	"fmt"
	"io"
	"regexp"
	"strconv"
	"strings"
	"time"

	"nntmux-native/internal/worker"
)

type Plan struct {
	Commands             int                  `json:"commands"`
	NetworkRequired      bool                 `json:"network_required"`
	ParserReady          bool                 `json:"parser_ready"`
	SessionReady         bool                 `json:"session_ready"`
	ReplacementReady     bool                 `json:"replacement_ready"`
	ReplacementReadiness ReplacementReadiness `json:"replacement_readiness"`
	Sample               *SampleReport        `json:"sample,omitempty"`
	Writes               int                  `json:"writes"`
}

type ReplacementReadiness struct {
	Blockers []string `json:"blockers"`
}

type SampleReport struct {
	Lines      int `json:"lines"`
	Messages   int `json:"messages"`
	Candidates int `json:"candidates"`
	Ignored    int `json:"ignored"`
	Unmatched  int `json:"unmatched"`
}

type ParseOptions struct {
	IgnoredSources map[string]bool
	CategoryIgnore *regexp.Regexp
	TitleIgnore    *regexp.Regexp
}

type Candidate struct {
	Action     string
	Predate    time.Time
	Title      string
	Source     string
	Category   string
	RequestID  int
	GroupName  string
	Size       string
	Files      string
	Filename   string
	NukeStatus int
	NukeReason string
}

const (
	PreNoNuke  = 0
	PreUnnuked = 1
	PreNuked   = 2
	PreModNuke = 3
	PreRenuked = 4
	PreOldNuke = 5
)

var (
	rawPrivmsgPattern = regexp.MustCompile(`^:(?P<nickname>.+?)!.+?\s+PRIVMSG\s+(?P<channel>#.+?)\s+:\s*(?P<message>.+?)\s*$`)
	preMessagePattern = regexp.MustCompile(`(?i)^(NEW|UPD|NUK): \[DT: (?P<time>.+?)\]\s?\[TT: (?P<title>.+?)\]\s?\[SC: (?P<source>.+?)\]\s?\[CT: (?P<category>.+?)\]\s?\[RQ: (?P<req>.+?)\]\s?\[SZ: (?P<size>.+?)\]\s?\[FL: (?P<files>.+?)\]\s?(\[FN: (?P<filename>.+?)\]\s?)?(\[(?P<nuked>(UN|MOD|RE|OLD)?NUKED?): (?P<reason>.+?)\])?$`)
	requestPattern    = regexp.MustCompile(`^(?P<request>\d+):(?P<group>.+)$`)
)

func BuildPlan(plan worker.Plan) (Plan, error) {
	if plan.Job.Name != "irc" {
		return Plan{}, fmt.Errorf("irc planner requires job %q", "irc")
	}

	for _, command := range plan.Commands {
		if command.Command != "irc:scrape" {
			return Plan{}, fmt.Errorf("unsupported irc command %q in native dry-run planner", command.Command)
		}
		if !emptyArguments(command.Arguments) {
			return Plan{}, fmt.Errorf("irc command arguments must be empty")
		}
	}

	return Plan{
		Commands:         len(plan.Commands),
		NetworkRequired:  true,
		ParserReady:      true,
		SessionReady:     true,
		ReplacementReady: false,
		ReplacementReadiness: ReplacementReadiness{
			Blockers: []string{
				"native IRC replacement still requires live deployment verification",
			},
		},
		Writes: 0,
	}, nil
}

func DryRunSummary(plan Plan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "irc dry-run")
	fmt.Fprintf(&buffer, "commands=%d\n", plan.Commands)
	fmt.Fprintf(&buffer, "network-required=%t\n", plan.NetworkRequired)
	fmt.Fprintf(&buffer, "parser-ready=%t\n", plan.ParserReady)
	fmt.Fprintf(&buffer, "session-ready=%t\n", plan.SessionReady)
	if plan.Sample != nil {
		fmt.Fprintf(
			&buffer,
			"sample lines=%d messages=%d candidates=%d ignored=%d unmatched=%d\n",
			plan.Sample.Lines,
			plan.Sample.Messages,
			plan.Sample.Candidates,
			plan.Sample.Ignored,
			plan.Sample.Unmatched,
		)
	}
	fmt.Fprintf(&buffer, "replacement-ready=%t\n", plan.ReplacementReady)
	fmt.Fprintf(&buffer, "writes=%d\n", plan.Writes)

	return buffer.String()
}

func ParseSample(reader io.Reader, opts ParseOptions) (SampleReport, []Candidate, error) {
	scanner := bufio.NewScanner(reader)
	scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	var report SampleReport
	candidates := []Candidate{}
	for scanner.Scan() {
		report.Lines++
		line := strings.TrimSpace(stripControlCharacters(scanner.Text()))
		if line == "" {
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
			continue
		}
		if !ok {
			report.Unmatched++
			continue
		}

		report.Candidates++
		candidates = append(candidates, candidate)
	}
	if err := scanner.Err(); err != nil {
		return report, candidates, fmt.Errorf("read irc sample: %w", err)
	}

	return report, candidates, nil
}

func ParseMessage(message string, opts ParseOptions) (Candidate, bool, bool, error) {
	matches := namedMatches(preMessagePattern, strings.TrimSpace(message))
	if len(matches) == 0 {
		return Candidate{}, false, false, nil
	}

	source := matches["source"]
	if opts.IgnoredSources != nil && opts.IgnoredSources[source] {
		return Candidate{}, true, false, nil
	}

	category := ""
	if matches["category"] != "N/A" {
		category = matches["category"]
	}
	if opts.CategoryIgnore != nil && opts.CategoryIgnore.MatchString(matches["category"]) {
		return Candidate{}, true, false, nil
	}
	if opts.TitleIgnore != nil && opts.TitleIgnore.MatchString(matches["title"]) {
		return Candidate{}, true, false, nil
	}

	predate, err := parsePredate(matches["time"])
	if err != nil {
		return Candidate{}, false, false, err
	}

	candidate := Candidate{
		Action:   strings.ToUpper(matches["1"]),
		Predate:  predate,
		Title:    matches["title"],
		Source:   source,
		Category: category,
	}
	if matches["req"] != "N/A" {
		req := namedMatches(requestPattern, matches["req"])
		if len(req) > 0 {
			requestID, err := strconv.Atoi(req["request"])
			if err != nil {
				return Candidate{}, false, false, fmt.Errorf("parse irc request id %q: %w", req["request"], err)
			}
			candidate.RequestID = requestID
			candidate.GroupName = req["group"]
		}
	}
	if matches["size"] != "N/A" {
		candidate.Size = matches["size"]
	}
	if matches["files"] != "N/A" {
		candidate.Files = truncate(matches["files"], 50)
	}
	if matches["filename"] != "" && matches["filename"] != "N/A" {
		candidate.Filename = matches["filename"]
	}
	if matches["nuked"] != "" {
		candidate.NukeStatus = nukeStatus(matches["nuked"])
		candidate.NukeReason = truncate(matches["reason"], 255)
	}

	return candidate, false, true, nil
}

func parsePredate(value string) (time.Time, error) {
	for _, layout := range []string{
		"2006-01-02 15:04:05",
		"2006-01-02 15:04",
		time.RFC3339,
		time.RFC1123,
		time.RFC1123Z,
	} {
		if parsed, err := time.ParseInLocation(layout, value, time.UTC); err == nil {
			return parsed.UTC(), nil
		}
	}

	return time.Time{}, fmt.Errorf("parse irc predate %q", value)
}

func nukeStatus(value string) int {
	switch strings.ToUpper(value) {
	case "NUKED":
		return PreNuked
	case "UNNUKED":
		return PreUnnuked
	case "MODNUKED":
		return PreModNuke
	case "RENUKED":
		return PreRenuked
	case "OLDNUKE":
		return PreOldNuke
	default:
		return PreNoNuke
	}
}

func namedMatches(pattern *regexp.Regexp, value string) map[string]string {
	match := pattern.FindStringSubmatch(value)
	if match == nil {
		return nil
	}

	result := map[string]string{}
	for i, name := range pattern.SubexpNames() {
		if i == 0 {
			continue
		}
		if name == "" {
			result[strconv.Itoa(i)] = match[i]
			continue
		}
		result[name] = match[i]
	}

	return result
}

func stripControlCharacters(value string) string {
	return strings.Map(func(r rune) rune {
		if r < 32 && r != '\t' {
			return -1
		}
		return r
	}, value)
}

func truncate(value string, max int) string {
	if len(value) <= max {
		return value
	}
	return value[:max]
}

func emptyArguments(arguments any) bool {
	switch value := arguments.(type) {
	case nil:
		return true
	case []any:
		return len(value) == 0
	case map[string]any:
		return len(value) == 0
	default:
		return false
	}
}
