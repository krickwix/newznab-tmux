package irc

import (
	"bufio"
	"context"
	"errors"
	"io"
	"net"
	"reflect"
	"strings"
	"testing"
)

func TestRunSessionLogsInJoinsPongsAndParsesCandidates(t *testing.T) {
	client, server := net.Pipe()
	defer client.Close()

	serverErr := make(chan error, 1)
	serverCommands := make(chan []string, 1)
	go func() {
		defer server.Close()

		reader := bufio.NewReader(server)
		commands := []string{}
		for len(commands) < 3 {
			line, err := reader.ReadString('\n')
			if err != nil {
				serverErr <- err
				return
			}
			commands = append(commands, strings.TrimSpace(line))
		}

		if _, err := io.WriteString(server, ":irc.example 001 native :welcome\r\n"); err != nil {
			serverErr <- err
			return
		}
		line, err := reader.ReadString('\n')
		if err != nil {
			serverErr <- err
			return
		}
		commands = append(commands, strings.TrimSpace(line))

		if _, err := io.WriteString(server, "PING :irc.example\r\n"); err != nil {
			serverErr <- err
			return
		}
		line, err = reader.ReadString('\n')
		if err != nil {
			serverErr <- err
			return
		}
		commands = append(commands, strings.TrimSpace(line))

		if _, err := io.WriteString(server, ":prebot!bot@example PRIVMSG #PreNNTmux :NEW: [DT: 2026-06-17 12:34:56] [TT: Movie.Name.2026-GRP] [SC: #a.b.movies] [CT: MOVIE] [RQ: N/A] [SZ: 8 GB] [FL: 10F]\r\n"); err != nil {
			serverErr <- err
			return
		}

		serverCommands <- commands
		serverErr <- nil
	}()

	report, candidates, err := RunSession(context.Background(), client, SessionConfig{
		Nickname:      "native",
		Username:      "native-user",
		RealName:      "Native User",
		Password:      "secret",
		Channels:      []Channel{{Name: "#PreNNTmux", Password: "channel-secret"}},
		MaxCandidates: 1,
	}, ParseOptions{})
	if err != nil {
		t.Fatalf("RunSession: %v", err)
	}

	commands := <-serverCommands
	if want := []string{
		"PASS secret",
		"NICK native",
		"USER native-user 0 * :Native User",
		"JOIN #PreNNTmux channel-secret",
		"PONG irc.example",
	}; !reflect.DeepEqual(commands, want) {
		t.Fatalf("commands = %#v, want %#v", commands, want)
	}
	if err := <-serverErr; err != nil {
		t.Fatalf("fake server: %v", err)
	}

	if !report.LoggedIn || report.Joins != 1 || report.Pings != 1 || report.Messages != 1 || report.Candidates != 1 {
		t.Fatalf("report = %#v", report)
	}
	if len(candidates) != 1 || candidates[0].Title != "Movie.Name.2026-GRP" {
		t.Fatalf("candidates = %#v", candidates)
	}
}

func TestRunSessionValidatesRequiredIdentity(t *testing.T) {
	_, _, err := RunSession(context.Background(), nopReadWriter{}, SessionConfig{
		Nickname: "",
		Username: "native",
		RealName: "native",
	}, ParseOptions{})
	if err == nil || !strings.Contains(err.Error(), "irc nickname is required") {
		t.Fatalf("error = %v, want nickname validation", err)
	}
}

func TestRunSessionStopsOnContextCancellation(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	_, _, err := RunSession(ctx, readWriter{Reader: strings.NewReader(":irc.example 001 native :welcome\n"), Writer: io.Discard}, SessionConfig{
		Nickname: "native",
		Username: "native",
		RealName: "native",
	}, ParseOptions{})
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("error = %v, want context canceled", err)
	}
}

type nopReadWriter struct{}

func (nopReadWriter) Read([]byte) (int, error) {
	return 0, io.EOF
}

func (nopReadWriter) Write(p []byte) (int, error) {
	return len(p), nil
}

type readWriter struct {
	io.Reader
	io.Writer
}
