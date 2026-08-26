package irc

import (
	"encoding/json"
	"os"
	"regexp"
	"strings"
	"testing"
	"time"

	"nntmux-native/internal/worker"
)

func TestBuildPlanSummarizesIrcCatalogWithoutNetwork(t *testing.T) {
	file, err := os.Open("../../../tests/Fixtures/native-worker/catalog/irc.json")
	if err != nil {
		t.Fatalf("open fixture: %v", err)
	}
	defer file.Close()

	nativePlan, err := worker.DecodePlan(file)
	if err != nil {
		t.Fatalf("decode fixture: %v", err)
	}

	plan, err := BuildPlan(nativePlan)
	if err != nil {
		t.Fatalf("BuildPlan: %v", err)
	}

	if plan.Commands != 1 || !plan.NetworkRequired || !plan.ParserReady || !plan.SessionReady || plan.ReplacementReady || plan.Writes != 0 {
		t.Fatalf("plan = %#v", plan)
	}
	if len(plan.ReplacementReadiness.Blockers) == 0 || !strings.Contains(strings.Join(plan.ReplacementReadiness.Blockers, "; "), "native IRC replacement still requires live deployment verification") {
		t.Fatalf("blockers = %#v, want live deployment blocker", plan.ReplacementReadiness.Blockers)
	}

	summary := DryRunSummary(plan)
	for _, want := range []string{
		"irc dry-run",
		"commands=1",
		"network-required=true",
		"parser-ready=true",
		"session-ready=true",
		"replacement-ready=false",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("summary = %q, missing %q", summary, want)
		}
	}
}

func TestBuildPlanRejectsUnsupportedIrcCommand(t *testing.T) {
	_, err := BuildPlan(worker.Plan{
		Job: worker.Job{Name: "irc"},
		Commands: []worker.Command{{
			Command:   "predb:refresh-external-metadata",
			Arguments: []any{},
		}},
	})
	if err == nil {
		t.Fatalf("BuildPlan succeeded with unsupported command")
	}
	if !strings.Contains(err.Error(), `unsupported irc command "predb:refresh-external-metadata"`) {
		t.Fatalf("error = %v, want unsupported irc command", err)
	}
}

func TestBuildPlanRejectsIrcCommandArguments(t *testing.T) {
	_, err := BuildPlan(worker.Plan{
		Job: worker.Job{Name: "irc"},
		Commands: []worker.Command{{
			Command: "irc:scrape",
			Arguments: map[string]any{
				"--debug": true,
			},
		}},
	})
	if err == nil {
		t.Fatalf("BuildPlan succeeded with command arguments")
	}
	if !strings.Contains(err.Error(), "irc command arguments must be empty") {
		t.Fatalf("error = %v, want empty arguments rejection", err)
	}
}

func TestParseMessageMatchesPHPPredbFields(t *testing.T) {
	candidate, ignored, ok, err := ParseMessage("NEW: [DT: 2026-06-17 12:34:56] [TT: Movie.Name.2026.1080p.BluRay.x264-GRP] [SC: #a.b.movies] [CT: MOVIE] [RQ: 12345:alt.binaries.movies] [SZ: 8.1 GB] [FL: 12F] [FN: movie.name.r00]", ParseOptions{})
	if err != nil {
		t.Fatalf("ParseMessage: %v", err)
	}
	if ignored || !ok {
		t.Fatalf("ignored=%t ok=%t, want parsed candidate", ignored, ok)
	}

	if candidate.Action != "NEW" ||
		candidate.Title != "Movie.Name.2026.1080p.BluRay.x264-GRP" ||
		candidate.Source != "#a.b.movies" ||
		candidate.Category != "MOVIE" ||
		candidate.RequestID != 12345 ||
		candidate.GroupName != "alt.binaries.movies" ||
		candidate.Size != "8.1 GB" ||
		candidate.Files != "12F" ||
		candidate.Filename != "movie.name.r00" ||
		candidate.NukeStatus != PreNoNuke {
		t.Fatalf("candidate = %#v", candidate)
	}
	wantTime := time.Date(2026, 6, 17, 12, 34, 56, 0, time.UTC)
	if !candidate.Predate.Equal(wantTime) {
		t.Fatalf("predate = %s, want %s", candidate.Predate, wantTime)
	}
}

func TestParseMessageMatchesNukeStatusesAndTruncatesLegacyFields(t *testing.T) {
	longFiles := strings.Repeat("F", 60)
	longReason := strings.Repeat("bad", 100)
	message := "NUK: [DT: 2026-06-17T12:34:56Z] [TT: Nuked.Release-GRP] [SC: srrdb] [CT: N/A] [RQ: N/A] [SZ: N/A] [FL: " + longFiles + "] [FN: N/A] [MODNUKED: " + longReason + "]"

	candidate, ignored, ok, err := ParseMessage(message, ParseOptions{})
	if err != nil {
		t.Fatalf("ParseMessage: %v", err)
	}
	if ignored || !ok {
		t.Fatalf("ignored=%t ok=%t, want parsed candidate", ignored, ok)
	}
	if candidate.Category != "" || candidate.RequestID != 0 || candidate.Size != "" || candidate.Filename != "" {
		t.Fatalf("candidate optional fields = %#v, want N/A omitted", candidate)
	}
	if candidate.NukeStatus != PreModNuke {
		t.Fatalf("nuke status = %d, want %d", candidate.NukeStatus, PreModNuke)
	}
	if len(candidate.Files) != 50 {
		t.Fatalf("files len = %d, want 50", len(candidate.Files))
	}
	if len(candidate.NukeReason) != 255 {
		t.Fatalf("nuke reason len = %d, want 255", len(candidate.NukeReason))
	}
}

func TestParseMessageAppliesSourceCategoryAndTitleIgnores(t *testing.T) {
	message := "NEW: [DT: 2026-06-17 12:34:56] [TT: German.Movie-GRP] [SC: #ignored] [CT: TV] [RQ: N/A] [SZ: 1 GB] [FL: 1F]"

	_, ignored, ok, err := ParseMessage(message, ParseOptions{IgnoredSources: map[string]bool{"#ignored": true}})
	if err != nil {
		t.Fatalf("source ParseMessage: %v", err)
	}
	if !ignored || ok {
		t.Fatalf("source ignored=%t ok=%t, want ignored", ignored, ok)
	}

	_, ignored, ok, err = ParseMessage(message, ParseOptions{CategoryIgnore: regexp.MustCompile(`^(TV)$`)})
	if err != nil {
		t.Fatalf("category ParseMessage: %v", err)
	}
	if !ignored || ok {
		t.Fatalf("category ignored=%t ok=%t, want ignored", ignored, ok)
	}

	_, ignored, ok, err = ParseMessage(message, ParseOptions{TitleIgnore: regexp.MustCompile(`German`)})
	if err != nil {
		t.Fatalf("title ParseMessage: %v", err)
	}
	if !ignored || ok {
		t.Fatalf("title ignored=%t ok=%t, want ignored", ignored, ok)
	}
}

func TestParseSampleAcceptsRawPrivmsgLinesAndReportsCounts(t *testing.T) {
	sample := strings.Join([]string{
		":prebot!bot@example PRIVMSG #PreNNTmux :NEW: [DT: 2026-06-17 12:34:56] [TT: Movie.Name.2026-GRP] [SC: #a.b.movies] [CT: MOVIE] [RQ: N/A] [SZ: 8 GB] [FL: 10F]",
		":prebot!bot@example PRIVMSG #PreNNTmux :NEW: [DT: 2026-06-17 12:35:56] [TT: Ignored.Source-GRP] [SC: #ignored] [CT: MOVIE] [RQ: N/A] [SZ: 1 GB] [FL: 1F]",
		":other!bot@example PRIVMSG #PreNNTmux :hello world",
		"UPD: [DT: 2026-06-17 12:36:56] [TT: Direct.Message-GRP] [SC: srrdb] [CT: N/A] [RQ: N/A] [SZ: N/A] [FL: N/A]",
	}, "\n")

	report, candidates, err := ParseSample(strings.NewReader(sample), ParseOptions{IgnoredSources: map[string]bool{"#ignored": true}})
	if err != nil {
		t.Fatalf("ParseSample: %v", err)
	}
	if report.Lines != 4 || report.Messages != 3 || report.Candidates != 2 || report.Ignored != 1 || report.Unmatched != 1 {
		t.Fatalf("report = %#v", report)
	}
	if len(candidates) != 2 || candidates[0].Title != "Movie.Name.2026-GRP" || candidates[1].Title != "Direct.Message-GRP" {
		t.Fatalf("candidates = %#v", candidates)
	}
}

func TestIrcPlanJSONDoesNotExposeRawCommandDetails(t *testing.T) {
	plan := Plan{
		Commands:         1,
		NetworkRequired:  true,
		ParserReady:      true,
		SessionReady:     true,
		ReplacementReady: false,
		ReplacementReadiness: ReplacementReadiness{
			Blockers: []string{"native IRC replacement still requires live deployment verification"},
		},
		Writes: 0,
	}

	encoded, err := json.Marshal(plan)
	if err != nil {
		t.Fatalf("marshal Plan: %v", err)
	}

	for _, forbidden := range []string{"arguments", "redis_key", "nntmux_database", "irc:scrape", "--debug", "server", "password", "channel"} {
		if strings.Contains(string(encoded), forbidden) {
			t.Fatalf("Plan JSON exposed %q: %s", forbidden, encoded)
		}
	}
}
