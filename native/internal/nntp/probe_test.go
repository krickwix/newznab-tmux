package nntp

import (
	"bufio"
	"context"
	"net"
	"strings"
	"testing"
	"time"
)

func TestProbeGroupsAuthenticatesAndChecksUniqueGroups(t *testing.T) {
	server := newFakeNNTPServer(t, func(line string) string {
		switch line {
		case "AUTHINFO USER native-user":
			return "381 password required"
		case "AUTHINFO PASS native-pass":
			return "281 authentication accepted"
		case "GROUP alt.binaries.movies":
			return "211 99 1 99 alt.binaries.movies"
		case "GROUP alt.binaries.tv":
			return "211 42 100 141 alt.binaries.tv"
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	report, err := ProbeGroups(context.Background(), Config{
		Server:         host,
		Port:           port,
		Username:       "native-user",
		Password:       "native-pass",
		ConnectTimeout: time.Second,
		SocketTimeout:  time.Second,
	}, []string{"alt.binaries.movies", "alt.binaries.movies", "alt.binaries.tv"})
	if err != nil {
		t.Fatalf("ProbeGroups: %v", err)
	}
	if report.Groups != 3 || report.Successful != 2 || report.Failed != 0 {
		t.Fatalf("report = %#v", report)
	}
	if report.TotalCount != 141 || report.LowestLow != 1 || report.HighestHigh != 141 {
		t.Fatalf("probe aggregate stats = %#v", report)
	}
	if len(report.Stats) != 2 {
		t.Fatalf("probe stats = %#v", report.Stats)
	}
	if report.Stats[0].Count != 99 || report.Stats[0].Low != 1 || report.Stats[0].High != 99 {
		t.Fatalf("first probe stat = %#v", report.Stats[0])
	}
	if report.Stats[1].Count != 42 || report.Stats[1].Low != 100 || report.Stats[1].High != 141 {
		t.Fatalf("second probe stat = %#v", report.Stats[1])
	}
}

func TestProbeGroupsReturnsSanitizedFailure(t *testing.T) {
	server := newFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.missing":
			return "411 no such group"
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	report, err := ProbeGroups(context.Background(), Config{
		Server:         host,
		Port:           port,
		ConnectTimeout: time.Second,
		SocketTimeout:  time.Second,
	}, []string{"alt.binaries.missing"})
	if err == nil {
		t.Fatal("ProbeGroups succeeded, want sanitized failure")
	}
	if strings.Contains(err.Error(), "alt.binaries.missing") || strings.Contains(err.Error(), host) || strings.Contains(err.Error(), port) {
		t.Fatalf("error leaked group or endpoint detail: %v", err)
	}
	if report.Groups != 1 || report.Successful != 0 || report.Failed != 1 {
		t.Fatalf("report = %#v", report)
	}
	if report.TotalCount != 0 || report.LowestLow != 0 || report.HighestHigh != 0 || len(report.Stats) != 0 {
		t.Fatalf("failed probe stats = %#v", report)
	}
}

func TestSampleOverviewFetchesBoundedRows(t *testing.T) {
	server := newFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.movies":
			return "211 1000 1 1000 alt.binaries.movies"
		case "OVER 10-11":
			return "224 overview follows\r\n10\t\"Movie.One.mkv\" yEnc (1/2)\tposter@example.test\t17 Jun 2026 10:00:00 +0000\t<10@example.test>\t\t1234\t45\r\n11\t\"Movie.One.mkv\" yEnc (2/2)\tposter@example.test\t17 Jun 2026 10:01:00 +0000\t<11@example.test>\t\t1235\t46\r\n."
		case "GROUP alt.binaries.tv":
			return "211 1000 1 1000 alt.binaries.tv"
		case "OVER 20-20":
			return "224 overview follows\r\n20\tShow.One\tposter@example.test\t17 Jun 2026 11:00:00 +0000\t<20@example.test>\t\t2234\t55\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	report, err := SampleOverview(context.Background(), Config{
		Server:         host,
		Port:           port,
		ConnectTimeout: time.Second,
		SocketTimeout:  time.Second,
	}, []OverviewRange{
		{Group: "alt.binaries.movies", Start: 10, End: 99},
		{Group: "alt.binaries.tv", Start: 20, End: 20},
	}, 2)
	if err != nil {
		t.Fatalf("SampleOverview: %v", err)
	}
	if report.Ranges != 2 || report.Requested != 3 || report.Received != 3 || report.Parsed != 3 || report.Malformed != 0 || report.Bytes != 4703 || report.Lines != 146 || report.HeaderCandidates != 3 || report.PartCandidates != 3 || report.UniqueMessageIDs != 3 || report.DuplicateMessageIDs != 0 || report.Failed != 0 {
		t.Fatalf("report = %#v", report)
	}
	if len(report.Candidates) != 3 {
		t.Fatalf("candidates = %d, want 3", len(report.Candidates))
	}
	if report.Candidates[0].BinaryName != "Movie.One.mkv" || report.Candidates[0].PartNumber != 1 || report.Candidates[0].TotalParts != 2 {
		t.Fatalf("first parsed candidate = %#v", report.Candidates[0])
	}
	if report.Candidates[1].BinaryName != "Movie.One.mkv" || report.Candidates[1].PartNumber != 2 || report.Candidates[1].TotalParts != 2 {
		t.Fatalf("second parsed candidate = %#v", report.Candidates[1])
	}
}

func TestSampleOverviewAggregatesMalformedRows(t *testing.T) {
	server := newFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.mixed":
			return "211 1000 1 1000 alt.binaries.mixed"
		case "OVER 30-31":
			return "224 overview follows\r\n30\tMixed.One\tposter@example.test\t17 Jun 2026 11:30:00 +0000\t<30@example.test>\t\t3333\t66\r\n31\tbroken-row\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	report, err := SampleOverview(context.Background(), Config{
		Server:         host,
		Port:           port,
		ConnectTimeout: time.Second,
		SocketTimeout:  time.Second,
	}, []OverviewRange{{Group: "alt.binaries.mixed", Start: 30, End: 31}}, 2)
	if err != nil {
		t.Fatalf("SampleOverview: %v", err)
	}
	if report.Ranges != 1 || report.Requested != 2 || report.Received != 2 || report.Parsed != 1 || report.Malformed != 1 || report.Bytes != 3333 || report.Lines != 66 || report.HeaderCandidates != 1 || report.PartCandidates != 1 || report.UniqueMessageIDs != 1 || report.DuplicateMessageIDs != 0 || report.Failed != 0 {
		t.Fatalf("report = %#v", report)
	}
}

func TestSampleOverviewAggregatesDuplicateMessageIDs(t *testing.T) {
	server := newFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.duplicates":
			return "211 1000 1 1000 alt.binaries.duplicates"
		case "OVER 50-51":
			return "224 overview follows\r\n50\tDuplicate.One\tposter@example.test\t17 Jun 2026 12:30:00 +0000\t<dupe@example.test>\t\t4000\t70\r\n51\tDuplicate.Two\tposter@example.test\t17 Jun 2026 12:31:00 +0000\t<dupe@example.test>\t\t4100\t71\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	report, err := SampleOverview(context.Background(), Config{
		Server:         host,
		Port:           port,
		ConnectTimeout: time.Second,
		SocketTimeout:  time.Second,
	}, []OverviewRange{{Group: "alt.binaries.duplicates", Start: 50, End: 51}}, 2)
	if err != nil {
		t.Fatalf("SampleOverview: %v", err)
	}
	if report.Ranges != 1 || report.Requested != 2 || report.Received != 2 || report.Parsed != 2 || report.HeaderCandidates != 2 || report.PartCandidates != 2 || report.UniqueMessageIDs != 1 || report.DuplicateMessageIDs != 1 {
		t.Fatalf("report = %#v", report)
	}
}

func TestSampleOverviewFallsBackToXOVER(t *testing.T) {
	server := newFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.legacy":
			return "211 1000 1 1000 alt.binaries.legacy"
		case "OVER 42-42":
			return "500 unknown command"
		case "XOVER 42-42":
			return "224 overview follows\r\n42\tLegacy.One\tposter@example.test\t17 Jun 2026 12:00:00 +0000\t<42@example.test>\t\t3234\t65\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	report, err := SampleOverview(context.Background(), Config{
		Server:         host,
		Port:           port,
		ConnectTimeout: time.Second,
		SocketTimeout:  time.Second,
	}, []OverviewRange{{Group: "alt.binaries.legacy", Start: 42, End: 42}}, 1)
	if err != nil {
		t.Fatalf("SampleOverview: %v", err)
	}
	if report.Ranges != 1 || report.Requested != 1 || report.Received != 1 || report.Parsed != 1 || report.Malformed != 0 || report.Bytes != 3234 || report.Lines != 65 || report.HeaderCandidates != 1 || report.PartCandidates != 1 || report.UniqueMessageIDs != 1 || report.DuplicateMessageIDs != 0 || report.Failed != 0 {
		t.Fatalf("report = %#v", report)
	}
}

func TestSampleOverviewFallsBackToXOVERForUnrecognizedOver(t *testing.T) {
	server := newFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.legacy":
			return "211 1000 1 1000 alt.binaries.legacy"
		case "OVER 42-42":
			return "400 unrecognized command"
		case "XOVER 42-42":
			return "224 overview follows\r\n42\tLegacy.One\tposter@example.test\t17 Jun 2026 12:00:00 +0000\t<42@example.test>\t\t3234\t65\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	report, err := SampleOverview(context.Background(), Config{
		Server:         host,
		Port:           port,
		ConnectTimeout: time.Second,
		SocketTimeout:  time.Second,
	}, []OverviewRange{{Group: "alt.binaries.legacy", Start: 42, End: 42}}, 1)
	if err != nil {
		t.Fatalf("SampleOverview: %v", err)
	}
	if report.Ranges != 1 || report.Requested != 1 || report.Received != 1 || report.Parsed != 1 || report.Failed != 0 {
		t.Fatalf("report = %#v", report)
	}
}

func TestSampleOverviewReportsSparseRangeWithoutFailing(t *testing.T) {
	server := newFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.sparse":
			return "211 1000 1 1000 alt.binaries.sparse"
		case "OVER 900-901":
			return "423 no articles in selected range"
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	report, err := SampleOverview(context.Background(), Config{
		Server:         host,
		Port:           port,
		ConnectTimeout: time.Second,
		SocketTimeout:  time.Second,
	}, []OverviewRange{{Group: "alt.binaries.sparse", Start: 900, End: 901}}, 2)
	if err != nil {
		t.Fatalf("SampleOverview: %v", err)
	}
	if report.Ranges != 1 || report.Requested != 2 || report.Received != 0 || report.Empty != 1 || report.Parsed != 0 || report.Failed != 0 {
		t.Fatalf("report = %#v", report)
	}
}

func TestSampleOverviewReportsSingleMissingArticleAsSparseRange(t *testing.T) {
	server := newFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.sparse":
			return "211 1000 1 1000 alt.binaries.sparse"
		case "OVER 900-900":
			return "430 no article"
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	report, err := SampleOverview(context.Background(), Config{
		Server:         host,
		Port:           port,
		ConnectTimeout: time.Second,
		SocketTimeout:  time.Second,
	}, []OverviewRange{{Group: "alt.binaries.sparse", Start: 900, End: 900}}, 1)
	if err != nil {
		t.Fatalf("SampleOverview: %v", err)
	}
	if report.Ranges != 1 || report.Requested != 1 || report.Received != 0 || report.Empty != 1 || report.Parsed != 0 || report.Failed != 0 {
		t.Fatalf("report = %#v", report)
	}
}

func TestSampleOverviewReturnsSanitizedFailure(t *testing.T) {
	server := newFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.secret":
			return "211 1000 1 1000 alt.binaries.secret"
		case "OVER 10-10":
			return "502 permission denied"
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	report, err := SampleOverview(context.Background(), Config{
		Server:         host,
		Port:           port,
		ConnectTimeout: time.Second,
		SocketTimeout:  time.Second,
	}, []OverviewRange{{Group: "alt.binaries.secret", Start: 10, End: 10}}, 1)
	if err == nil {
		t.Fatal("SampleOverview succeeded, want sanitized failure")
	}
	if strings.Contains(err.Error(), "alt.binaries.secret") || strings.Contains(err.Error(), host) || strings.Contains(err.Error(), port) {
		t.Fatalf("error leaked group or endpoint detail: %v", err)
	}
	if report.Ranges != 1 || report.Requested != 1 || report.Received != 0 || report.Failed != 1 {
		t.Fatalf("report = %#v", report)
	}
}

func TestConfigFromEnvUsesAlternateServerWithoutLeakingSecretValidation(t *testing.T) {
	t.Setenv("USE_ALTERNATE_NNTP_SERVER", "true")
	t.Setenv("NNTP_SERVER_A", "reader-a.example.test")
	t.Setenv("NNTP_PORT_A", "563")
	t.Setenv("NNTP_SSLENABLED_A", "1")
	t.Setenv("NNTP_USERNAME_A", "alternate-user")
	t.Setenv("NNTP_PASSWORD_A", "alternate-secret")
	t.Setenv("NNTP_CONNECT_TIMEOUT_A", "7")
	t.Setenv("NNTP_SOCKET_TIMEOUT_A", "11")

	config := ConfigFromEnv()
	if config.Server != "reader-a.example.test" || config.Port != "563" || !config.SSL {
		t.Fatalf("alternate config = %#v", config)
	}
	if config.Username != "alternate-user" || config.Password != "alternate-secret" {
		t.Fatalf("alternate credentials not selected: %#v", config)
	}
	if config.ConnectTimeout != 7*time.Second || config.SocketTimeout != 11*time.Second {
		t.Fatalf("timeouts = %s/%s", config.ConnectTimeout, config.SocketTimeout)
	}
}

func newFakeNNTPServer(t *testing.T, handler func(string) string) net.Listener {
	t.Helper()

	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}

	done := make(chan struct{})
	t.Cleanup(func() {
		_ = listener.Close()
		<-done
	})

	go func() {
		defer close(done)
		conn, err := listener.Accept()
		if err != nil {
			return
		}
		defer conn.Close()

		reader := bufio.NewReader(conn)
		writer := bufio.NewWriter(conn)
		writeLine(t, writer, "200 fake nntp ready")
		for {
			line, err := reader.ReadString('\n')
			if err != nil {
				return
			}
			line = strings.TrimRight(line, "\r\n")
			response := handler(line)
			writeLine(t, writer, response)
			if line == "QUIT" {
				return
			}
		}
	}()

	return listener
}

func writeLine(t *testing.T, writer *bufio.Writer, line string) {
	t.Helper()
	if _, err := writer.WriteString(line + "\r\n"); err != nil {
		t.Fatalf("write fake NNTP line: %v", err)
	}
	if err := writer.Flush(); err != nil {
		t.Fatalf("flush fake NNTP line: %v", err)
	}
}
