package main

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"slices"
	"strings"
	"testing"

	"nntmux-native/internal/safety"
	"nntmux-native/internal/worker"
)

func tempFileWithContents(t *testing.T, pattern string, contents string) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), strings.ReplaceAll(pattern, "*", "sample"))
	if err := os.WriteFile(path, []byte(contents), 0o600); err != nil {
		t.Fatalf("write temp file: %v", err)
	}

	return path
}

func TestRunDryRunPrintsSummary(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/metadata-refresh-plan.json",
		"--dry-run",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	if strings.HasPrefix(strings.TrimSpace(output), "{") {
		t.Fatalf("default dry-run output is JSON, want text: %q", output)
	}
	for _, want := range []string{
		"job=metadata-refresh",
		"lock=nntmux:distributed-worker:metadata-refresh seconds=42",
		"commands=3",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}
}

func TestRunDryRunReadsPlanFromStdin(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--dry-run",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "metadata-refresh",
			"description": "Refresh external release-name evidence and run strong fix-name passes",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 900
		},
		"lock": {
			"name": "nntmux:distributed-worker:metadata-refresh",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:metadata-refresh",
			"seconds": 42
		},
		"commands": [
			{
				"command": "predb:refresh-external-metadata",
				"arguments": {
					"--source": ["all"],
					"--limit": 25,
					"--sleep-ms": 250
				}
			}
		]
	}`), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	if !strings.Contains(stdout.String(), "job=metadata-refresh") {
		t.Fatalf("dry-run output = %q, want stdin plan summary", stdout.String())
	}
}

func TestRunRejectsUnsupportedPostAdditionalDeferredFixNameCommand(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--dry-run",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "post-additional",
			"description": "Run additional and/or NFO post-processing",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:post-additional",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:post-additional",
			"seconds": 42
		},
		"commands": [
			{"command": "multiprocessing:postprocess", "arguments": {"type": "add"}},
			{
				"command": "releases:fix-names",
				"arguments": {
					"method": "6",
					"--update": true,
					"--category": "other",
					"--set-status": true
				}
			}
		]
	}`), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run succeeded with unsupported post-additional fix-name command, stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), `unsupported post-additional command "releases:fix-names"`) {
		t.Fatalf("stderr = %q, want unsupported post-additional command", stderr.String())
	}
}

func TestRunRejectsUnsupportedPostAdditionalHashedFixNameMethod(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--dry-run",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "post-additional",
			"description": "Run additional and/or NFO post-processing",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:post-additional",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:post-additional",
			"seconds": 42
		},
		"commands": [
			{"command": "multiprocessing:postprocess", "arguments": {"type": "add"}},
			{
				"command": "releases:fix-names",
				"arguments": {
					"method": "18",
					"--update": true,
					"--category": "hashed",
					"--set-status": true
				}
			}
		]
	}`), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run succeeded with unsupported post-additional hashed fix-name method, stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), `unsupported post-additional command "releases:fix-names"`) {
		t.Fatalf("stderr = %q, want unsupported post-additional command", stderr.String())
	}
}

func TestRunRejectsPostAdditionalNativeLaneWithoutDeferredGuard(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--run-lane",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
		"--redis-addr", "redis:6379",
		"--lock-owner", "laravel-post-additional-owner",
		"--lock-mode", "held",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "post-additional",
			"description": "Run additional and/or NFO post-processing",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:post-additional",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:post-additional",
			"seconds": 42
		},
		"commands": [
			{"command": "multiprocessing:postprocess", "arguments": {"type": "add"}},
			{"command": "multiprocessing:postprocess", "arguments": {"type": "nfo"}},
			{"command": "predb:refresh-external-metadata", "arguments": {"--limit": 7}},
			{"command": "releases:fix-names", "arguments": {"method": "20", "--category": "hashed"}}
		]
	}`), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run succeeded without post-additional deferred guard, stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), "--allow-deferred-post-additional is required for post-additional lane execution") {
		t.Fatalf("stderr = %q, want deferred guard requirement", stderr.String())
	}
}

func TestRunRejectsPostAdditionalWriteCommitWithoutDeferredGuard(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--commit-lane-writes",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
		"--redis-addr", "redis:6379",
		"--lock-owner", "post-additional-commit-owner",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "post-additional",
			"description": "Run additional and/or NFO post-processing",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:post-additional",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:post-additional",
			"seconds": 42
		},
		"commands": [
			{"command": "multiprocessing:postprocess", "arguments": {"type": "add"}},
			{"command": "multiprocessing:postprocess", "arguments": {"type": "nfo"}},
			{"command": "predb:refresh-external-metadata", "arguments": {"--limit": 7}},
			{"command": "releases:fix-names", "arguments": {"method": "20", "--category": "hashed"}}
		]
	}`), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run succeeded without post-additional deferred guard, stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), "--allow-deferred-post-additional is required for post-additional lane write commit") {
		t.Fatalf("stderr = %q, want deferred guard requirement", stderr.String())
	}
}

func TestRunRejectsMetadataRefreshNativeLaneWithoutRefreshCommand(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--run-lane",
		"--redis-addr", "redis:6379",
		"--lock-owner", "laravel-metadata-refresh-owner",
		"--lock-mode", "held",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "metadata-refresh",
			"description": "Refresh external release-name evidence and run strong fix-name passes",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 900
		},
		"lock": {
			"name": "nntmux:distributed-worker:metadata-refresh",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:metadata-refresh",
			"seconds": 42
		},
		"commands": [
			{"command": "releases:fix-names", "arguments": {"method": "20", "--category": "hashed"}}
		]
	}`), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run succeeded without metadata refresh command, stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), "metadata-refresh lane execution requires predb:refresh-external-metadata command") {
		t.Fatalf("stderr = %q, want metadata refresh command requirement", stderr.String())
	}
}

func TestMetadataRefreshIncludesSrrdbHonorsSourceArguments(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name      string
		argument  any
		wantFetch bool
	}{
		{name: "missing source defaults to srrdb", argument: nil, wantFetch: true},
		{name: "all includes srrdb", argument: []any{"all"}, wantFetch: true},
		{name: "explicit srrdb includes srrdb", argument: []any{"srrdb"}, wantFetch: true},
		{name: "non srrdb source skips", argument: []any{"predb-net"}, wantFetch: false},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()

			arguments := map[string]any{"--limit": float64(7)}
			if test.argument != nil {
				arguments["--source"] = test.argument
			}
			plan := worker.Plan{
				Job: worker.Job{Name: "metadata-refresh"},
				Commands: []worker.Command{{
					Command:   "predb:refresh-external-metadata",
					Arguments: arguments,
				}},
			}

			if got := metadataRefreshIncludesSrrdb(plan); got != test.wantFetch {
				t.Fatalf("metadataRefreshIncludesSrrdb = %t, want %t", got, test.wantFetch)
			}
		})
	}
}

func TestRunRejectsMetadataRefreshWriteCommitWithoutRefreshCommand(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--commit-lane-writes",
		"--redis-addr", "redis:6379",
		"--lock-owner", "metadata-refresh-owner",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "metadata-refresh",
			"description": "Refresh external release-name evidence and run strong fix-name passes",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 900
		},
		"lock": {
			"name": "nntmux:distributed-worker:metadata-refresh",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:metadata-refresh",
			"seconds": 42
		},
		"commands": [
			{"command": "releases:fix-names", "arguments": {"method": "20", "--category": "hashed"}}
		]
	}`), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run succeeded without metadata refresh command, stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), "--commit-lane-writes requires metadata-refresh commands") {
		t.Fatalf("stderr = %q, want metadata refresh command requirement", stderr.String())
	}
}

func TestRunRejectsPerGroupWriteCommitWithWrongCommand(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--commit-lane-writes",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
		"--redis-addr", "redis:6379",
		"--lock-owner", "per-group-commit-proof",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "per-group",
			"description": "Run the per-group all-in-one processing worker",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:per-group",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:per-group",
			"seconds": 42
		},
		"commands": [
			{"command": "multiprocessing:releases", "arguments": []}
		]
	}`), &stdout, &stderr)

	if code != 1 {
		t.Fatalf("run exit = %d, want command requirement refusal; stderr=%q stdout=%q", code, stderr.String(), stdout.String())
	}
	if !strings.Contains(stderr.String(), `unsupported per-group command "multiprocessing:releases"`) {
		t.Fatalf("stderr = %q, want unsupported per-group command", stderr.String())
	}
}

func TestRunRejectsUnsupportedReleasesCommand(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--dry-run",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "releases",
			"description": "Create and categorize releases",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 60
		},
		"lock": {
			"name": "nntmux:distributed-worker:releases",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:releases",
			"seconds": 42
		},
		"commands": [
			{"command": "multiprocessing:update-per-group", "arguments": []}
		]
	}`), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run succeeded with unsupported releases command, stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), `unsupported releases command "multiprocessing:update-per-group"`) {
		t.Fatalf("stderr = %q, want unsupported releases command", stderr.String())
	}
}

func TestRunLaneRejectsUnexpectedFirstLaneCommandEnvelopeBeforePlanning(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--run-lane",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
		"--redis-addr", "redis:6379",
		"--lock-owner", "laravel-owner",
		"--lock-mode", "held",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "binaries",
			"description": "Download new headers for active groups",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 60
		},
		"lock": {
			"name": "nntmux:distributed-worker:binaries",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:binaries",
			"seconds": 42
		},
		"commands": [
			{"command": "multiprocessing:safe", "arguments": {"type": "binaries"}},
			{"command": "releases:process", "arguments": {"groupId": 42}}
		]
	}`), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want command-envelope refusal; stderr=%q stdout=%q", code, stderr.String(), stdout.String())
	}
	if !strings.Contains(stderr.String(), `unsupported binaries command "releases:process"`) {
		t.Fatalf("stderr = %q, want unsupported binaries command", stderr.String())
	}
	if stdout.Len() != 0 {
		t.Fatalf("stdout = %q, want no report before first-lane command-envelope refusal", stdout.String())
	}
}

func TestRunRejectsUnsupportedPerGroupCommand(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--dry-run",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "per-group",
			"description": "Run the per-group all-in-one processing worker",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:per-group",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:per-group",
			"seconds": 42
		},
		"commands": [
			{"command": "multiprocessing:releases", "arguments": []}
		]
	}`), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run succeeded with unsupported per-group command, stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), `unsupported per-group command "multiprocessing:releases"`) {
		t.Fatalf("stderr = %q, want unsupported per-group command", stderr.String())
	}
}

func TestRunRejectsUnsupportedFixnamesCommand(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--dry-run",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "fixnames",
			"description": "Run release name fixing passes",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:fixnames",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:fixnames",
			"seconds": 42
		},
		"commands": [
			{"command": "multiprocessing:releases", "arguments": []}
		]
	}`), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run succeeded with unsupported fixnames command, stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), `unsupported fixnames command "multiprocessing:releases"`) {
		t.Fatalf("stderr = %q, want unsupported fixnames command", stderr.String())
	}
}

func TestRunRejectsUnsupportedIrcCommand(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--dry-run",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "irc",
			"description": "Run the IRC scraper",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 10
		},
		"lock": {
			"name": "nntmux:distributed-worker:irc",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:irc",
			"seconds": 42
		},
		"commands": [
			{"command": "multiprocessing:releases", "arguments": []}
		]
	}`), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run succeeded with unsupported irc command, stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), `unsupported irc command "multiprocessing:releases"`) {
		t.Fatalf("stderr = %q, want unsupported irc command", stderr.String())
	}
}

func TestRunDryRunPrintsJSONReport(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/metadata-refresh-plan.json",
		"--dry-run",
		"--output", "json",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "native worker dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}

	var report struct {
		NativeWorker struct {
			Job                  string `json:"job"`
			Enabled              bool   `json:"enabled"`
			Sleep                int    `json:"sleep"`
			Lock                 string `json:"lock"`
			LockSeconds          int    `json:"lock_seconds"`
			Commands             int    `json:"commands"`
			ReplacementReady     bool   `json:"replacement_ready"`
			ReplacementReadiness struct {
				Blockers []string `json:"blockers"`
			} `json:"replacement_readiness"`
			Writes int `json:"writes"`
		} `json:"native_worker"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "metadata-refresh" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Lock != "nntmux:distributed-worker:metadata-refresh" {
		t.Fatalf("native_worker.lock = %q", report.NativeWorker.Lock)
	}
	if report.NativeWorker.Commands != 3 {
		t.Fatalf("native_worker.commands = %d", report.NativeWorker.Commands)
	}
	if report.NativeWorker.ReplacementReady {
		t.Fatalf("native_worker.replacement_ready = true, want false")
	}
	if !slices.Contains(report.NativeWorker.ReplacementReadiness.Blockers, "metadata-refresh embedded hashed fix-name commands are deferred to PHP") {
		t.Fatalf("native_worker.replacement_readiness.blockers = %#v", report.NativeWorker.ReplacementReadiness.Blockers)
	}
	if report.NativeWorker.Writes != 0 {
		t.Fatalf("native_worker.writes = %d, want 0", report.NativeWorker.Writes)
	}

	var generic map[string]any
	if err := json.Unmarshal(stdout.Bytes(), &generic); err != nil {
		t.Fatalf("decode generic json report: %v", err)
	}
	for _, forbidden := range []string{"--source", "--limit", "arguments", "redis_key", "nntmux_database"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}
	if _, ok := generic["schema_version"]; !ok {
		t.Fatalf("json report missing schema_version: %#v", generic)
	}
	if generic["dry_run"] != true {
		t.Fatalf("json report dry_run = %#v, want true", generic["dry_run"])
	}
	if generic["mode"] != "shadow" {
		t.Fatalf("json report mode = %#v, want shadow", generic["mode"])
	}
	if _, ok := generic["metadata_refresh"]; ok {
		t.Fatalf("json report without mysql should not include metadata_refresh: %#v", generic["metadata_refresh"])
	}
	if _, ok := generic["hashed_fixnames"]; ok {
		t.Fatalf("json report without mysql should not include hashed_fixnames: %#v", generic["hashed_fixnames"])
	}
}

func TestRunPrintsIrcJSONReportWithoutNetwork(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/irc.json",
		"--dry-run",
		"--output", "json",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{"arguments", "redis_key", "nntmux_database", "--debug", "server", "password", "channel"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		Irc struct {
			Commands             int  `json:"commands"`
			NetworkRequired      bool `json:"network_required"`
			ParserReady          bool `json:"parser_ready"`
			SessionReady         bool `json:"session_ready"`
			ReplacementReady     bool `json:"replacement_ready"`
			ReplacementReadiness struct {
				Blockers []string `json:"blockers"`
			} `json:"replacement_readiness"`
			Writes int `json:"writes"`
		} `json:"irc"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.NativeWorker.Job != "irc" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.Irc.Writes != 0 {
		t.Fatalf("writes = native:%d irc:%d, want 0", report.NativeWorker.Writes, report.Irc.Writes)
	}
	if report.Irc.Commands != 1 || !report.Irc.NetworkRequired || !report.Irc.ParserReady || !report.Irc.SessionReady || report.Irc.ReplacementReady {
		t.Fatalf("irc = %#v", report.Irc)
	}
	if len(report.Irc.ReplacementReadiness.Blockers) == 0 {
		t.Fatalf("blockers = %#v, want replacement blockers", report.Irc.ReplacementReadiness.Blockers)
	}
}

func TestRunParsesIrcSampleDuringDryRun(t *testing.T) {
	t.Parallel()

	samplePath := tempFileWithContents(t, "irc-sample-*.log", strings.Join([]string{
		":prebot!bot@example PRIVMSG #PreNNTmux :NEW: [DT: 2026-06-17 12:34:56] [TT: Movie.Name.2026-GRP] [SC: #a.b.movies] [CT: MOVIE] [RQ: N/A] [SZ: 8 GB] [FL: 10F]",
		":prebot!bot@example PRIVMSG #PreNNTmux :not a pre",
		"UPD: [DT: 2026-06-17 12:36:56] [TT: Direct.Message-GRP] [SC: srrdb] [CT: N/A] [RQ: N/A] [SZ: N/A] [FL: N/A]",
	}, "\n"))

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/irc.json",
		"--dry-run",
		"--output", "json",
		"--irc-sample", samplePath,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{"#PreNNTmux", "Movie.Name.2026-GRP", "Direct.Message-GRP", "password"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked sample detail %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		Irc struct {
			Sample struct {
				Lines      int `json:"lines"`
				Messages   int `json:"messages"`
				Candidates int `json:"candidates"`
				Ignored    int `json:"ignored"`
				Unmatched  int `json:"unmatched"`
			} `json:"sample"`
		} `json:"irc"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.Irc.Sample.Lines != 3 || report.Irc.Sample.Messages != 2 || report.Irc.Sample.Candidates != 2 || report.Irc.Sample.Unmatched != 1 || report.Irc.Sample.Ignored != 0 {
		t.Fatalf("sample = %#v", report.Irc.Sample)
	}
}

func TestRunRejectsIrcSampleOutsideDryRunOrIrcJob(t *testing.T) {
	t.Parallel()

	samplePath := tempFileWithContents(t, "irc-sample-*.log", "NEW: [DT: 2026-06-17 12:34:56] [TT: Movie-GRP] [SC: srrdb] [CT: N/A] [RQ: N/A] [SZ: N/A] [FL: N/A]\n")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/irc.json",
		"--run-lane",
		"--irc-sample", samplePath,
	}, strings.NewReader(""), &stdout, &stderr)
	if code != 2 {
		t.Fatalf("run exit = %d, want usage error; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--irc-sample requires --dry-run or --commit-lane-writes") {
		t.Fatalf("stderr = %q, want dry-run or commit guard", stderr.String())
	}

	stdout.Reset()
	stderr.Reset()
	code = run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/releases.json",
		"--dry-run",
		"--irc-sample", samplePath,
	}, strings.NewReader(""), &stdout, &stderr)
	if code != 2 {
		t.Fatalf("run exit = %d, want job guard; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--irc-sample currently supports only the irc job") {
		t.Fatalf("stderr = %q, want irc job guard", stderr.String())
	}
}

func TestRunRequireReplacementReadyRejectsIrcCatalog(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/irc.json",
		"--dry-run",
		"--output", "json",
		"--require-replacement-ready",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want replacement readiness failure; stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"irc catalog is not replacement-ready",
		"native IRC replacement still requires live deployment verification",
	} {
		if !strings.Contains(stderr.String(), want) {
			t.Fatalf("stderr = %q, missing %q", stderr.String(), want)
		}
	}
	if stdout.Len() != 0 {
		t.Fatalf("stdout = %q, want no report for replacement readiness failure", stdout.String())
	}
	for _, forbidden := range []string{"arguments", "redis_key", "nntmux_database", "--debug", "password"} {
		if strings.Contains(stderr.String(), forbidden) {
			t.Fatalf("stderr leaked %q: %q", forbidden, stderr.String())
		}
	}
}

func TestRunPrintsFixnamesJSONReportWithoutMySQL(t *testing.T) {
	t.Setenv("NNTMUX_NATIVE_MYSQL_DSN", "user:pass@tcp(db:3306)/secret_db")

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/fixnames.json",
		"--dry-run",
		"--output", "json",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{"arguments", "redis_key", "nntmux_database", "user:pass@tcp(db:3306)/secret_db", "--category", "--limit", "other", "movies"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		Fixnames struct {
			Commands             int  `json:"commands"`
			Methods              int  `json:"methods"`
			Categories           int  `json:"categories"`
			LimitedCommands      int  `json:"limited_commands"`
			UpdateCommands       int  `json:"update_commands"`
			SetStatusCommands    int  `json:"set_status_commands"`
			ShowCommands         int  `json:"show_commands"`
			ReplacementReady     bool `json:"replacement_ready"`
			ReplacementReadiness struct {
				SupportedMethods    []string `json:"supported_methods"`
				UnsupportedMethods  []string `json:"unsupported_methods"`
				UnsupportedCommands int      `json:"unsupported_commands"`
				NativeCommands      int      `json:"native_commands"`
				Blockers            []string `json:"blockers"`
			} `json:"replacement_readiness"`
			Writes int `json:"writes"`
		} `json:"fixnames"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.NativeWorker.Job != "fixnames" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.Fixnames.Writes != 0 {
		t.Fatalf("writes = native:%d fixnames:%d, want 0", report.NativeWorker.Writes, report.Fixnames.Writes)
	}
	if report.Fixnames.Commands != 15 || report.Fixnames.Methods != 13 || report.Fixnames.Categories != 2 || report.Fixnames.LimitedCommands != 4 {
		t.Fatalf("fixnames = %#v", report.Fixnames)
	}
	if report.Fixnames.UpdateCommands != 15 || report.Fixnames.SetStatusCommands != 15 || report.Fixnames.ShowCommands != 15 {
		t.Fatalf("fixnames = %#v", report.Fixnames)
	}
	if report.Fixnames.ReplacementReady {
		t.Fatalf("replacement_ready = true, want false")
	}
	if !reflect.DeepEqual(report.Fixnames.ReplacementReadiness.SupportedMethods, []string{"15", "19"}) {
		t.Fatalf("supported_methods = %#v", report.Fixnames.ReplacementReadiness.SupportedMethods)
	}
	if !reflect.DeepEqual(report.Fixnames.ReplacementReadiness.UnsupportedMethods, []string{"3", "4", "5", "6", "7", "8", "9", "11", "13", "17", "21"}) {
		t.Fatalf("unsupported_methods = %#v", report.Fixnames.ReplacementReadiness.UnsupportedMethods)
	}
	if report.Fixnames.ReplacementReadiness.UnsupportedCommands != 13 {
		t.Fatalf("unsupported_commands = %d, want 13", report.Fixnames.ReplacementReadiness.UnsupportedCommands)
	}
	if report.Fixnames.ReplacementReadiness.NativeCommands != 2 {
		t.Fatalf("native_commands = %d, want 2", report.Fixnames.ReplacementReadiness.NativeCommands)
	}
	if len(report.Fixnames.ReplacementReadiness.Blockers) == 0 {
		t.Fatalf("blockers = %#v, want replacement blockers", report.Fixnames.ReplacementReadiness.Blockers)
	}
}

func TestRunReachesFixnamesMariaDBDryRunPlanner(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/fixnames.json",
		"--dry-run",
		"--mysql-dsn", "nntmux:nntmux@tcp(127.0.0.1:1)/nntmux_native_test?parseTime=true",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 1 {
		t.Fatalf("run exit = %d, want DB planner failure; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), `build fixnames mysql dry-run`) {
		t.Fatalf("stderr = %q, want fixnames DB planner failure", stderr.String())
	}
	if stdout.Len() != 0 {
		t.Fatalf("stdout = %q, want no report for failed DB planner", stdout.String())
	}
}

func TestRunDryRunMysqlDSNEnvRequiresEnvironmentValue(t *testing.T) {
	t.Setenv("NNTMUX_NATIVE_MYSQL_DSN", "")

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn-env",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want config error; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--mysql-dsn-env requires NNTMUX_NATIVE_MYSQL_DSN") {
		t.Fatalf("stderr = %q, want missing env error", stderr.String())
	}
	if stdout.String() != "" {
		t.Fatalf("stdout = %q, want no report for config error", stdout.String())
	}
}

func TestRunRejectsSearchDocumentParityWithWriteRehearsal(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
		"--search-document-parity",
		"--rehearse-writes",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want config error; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--search-document-parity cannot be combined with --rehearse-writes") {
		t.Fatalf("stderr = %q, want parity write rehearsal rejection", stderr.String())
	}
	if stdout.String() != "" {
		t.Fatalf("stdout = %q, want no report for config error", stdout.String())
	}
}

func TestRunRejectsNNTPProbeWithoutDryRun(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--run-lane",
		"--nntp-probe",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
		"--redis-addr", "redis:6379",
		"--lock-owner", "laravel-owner",
		"--lock-mode", "held",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want config error; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--nntp-probe requires --dry-run") {
		t.Fatalf("stderr = %q, want nntp dry-run guard", stderr.String())
	}
	if stdout.String() != "" {
		t.Fatalf("stdout = %q, want no report for config error", stdout.String())
	}
}

func TestRunRejectsNNTPProbeForUnsupportedLane(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/releases.json",
		"--dry-run",
		"--nntp-probe",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want config error; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--nntp-probe currently supports only the binaries and backfill jobs") {
		t.Fatalf("stderr = %q, want nntp lane guard", stderr.String())
	}
	if stdout.String() != "" {
		t.Fatalf("stdout = %q, want no report for config error", stdout.String())
	}
}

func TestRunRejectsNNTPOverviewSampleWithoutDryRunOrCommit(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--run-lane",
		"--nntp-overview-sample", "2",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
		"--redis-addr", "redis:6379",
		"--lock-owner", "laravel-owner",
		"--lock-mode", "held",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want config error; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--nntp-overview-sample requires --dry-run or --commit-lane-writes") {
		t.Fatalf("stderr = %q, want nntp overview dry-run guard", stderr.String())
	}
	if stdout.String() != "" {
		t.Fatalf("stdout = %q, want no report for config error", stdout.String())
	}
}

func TestRunRejectsCommittedAcquisitionWithoutNNTPOverviewSample(t *testing.T) {
	t.Parallel()

	for _, tt := range []struct {
		name string
		plan string
		job  string
	}{
		{name: "binaries", plan: "../../../tests/Fixtures/native-worker/catalog/binaries.json", job: "binaries"},
		{name: "backfill", plan: "../../../tests/Fixtures/native-worker/catalog/backfill.json", job: "backfill"},
	} {
		t.Run(tt.name, func(t *testing.T) {
			t.Parallel()

			var stdout bytes.Buffer
			var stderr bytes.Buffer

			code := run([]string{
				"--plan", tt.plan,
				"--commit-lane-writes",
				"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
				"--redis-addr", "redis:6379",
				"--lock-owner", tt.job + "-owner",
			}, strings.NewReader(""), &stdout, &stderr)

			if code != 2 {
				t.Fatalf("run exit = %d, want config error; stderr = %q", code, stderr.String())
			}
			want := "--commit-lane-writes for " + tt.job + " requires --nntp-overview-sample"
			if !strings.Contains(stderr.String(), want) {
				t.Fatalf("stderr = %q, want %q", stderr.String(), want)
			}
			if stdout.String() != "" {
				t.Fatalf("stdout = %q, want no report for config error", stdout.String())
			}
		})
	}
}

func TestRunRejectsNNTPOverviewSampleForUnsupportedLane(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/releases.json",
		"--dry-run",
		"--nntp-overview-sample", "2",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want config error; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--nntp-overview-sample currently supports only the binaries and backfill jobs") {
		t.Fatalf("stderr = %q, want nntp overview lane guard", stderr.String())
	}
	if stdout.String() != "" {
		t.Fatalf("stdout = %q, want no report for config error", stdout.String())
	}
}

func TestRunRejectsInvalidOutputFormat(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/metadata-refresh-plan.json",
		"--dry-run",
		"--output", "xml",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want invalid option failure", code)
	}
	if !strings.Contains(stderr.String(), `unsupported --output "xml"`) {
		t.Fatalf("stderr = %q, want output validation error", stderr.String())
	}
}

func TestRunRequireReplacementReadyRejectsFixnamesCatalog(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/fixnames.json",
		"--dry-run",
		"--output", "json",
		"--require-replacement-ready",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want replacement readiness failure; stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"fixnames catalog is not replacement-ready",
		"unsupported regular fix-name methods: 3,4,5,6,7,8,9,11,13,17,21",
		"remaining regular fix-name methods are deferred to PHP",
	} {
		if !strings.Contains(stderr.String(), want) {
			t.Fatalf("stderr = %q, missing %q", stderr.String(), want)
		}
	}
	if stdout.Len() != 0 {
		t.Fatalf("stdout = %q, want no report for replacement readiness failure", stdout.String())
	}
	for _, forbidden := range []string{"arguments", "redis_key", "nntmux_database", "--category", "other", "movies"} {
		if strings.Contains(stderr.String(), forbidden) {
			t.Fatalf("stderr leaked %q: %q", forbidden, stderr.String())
		}
	}
}

func TestRunRequireReplacementReadyRejectsHashedFixnamesCatalogWithUnsupportedMethods(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--output", "json",
		"--require-replacement-ready",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want replacement readiness failure; stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"hashed-fixnames catalog is not replacement-ready",
		"unsupported hashed fix-name methods: 4,6,8,10,12,14,18,21",
		"release rename, category, event, and search side effects remain PHP-owned",
	} {
		if !strings.Contains(stderr.String(), want) {
			t.Fatalf("stderr = %q, missing %q", stderr.String(), want)
		}
	}
	if stdout.Len() != 0 {
		t.Fatalf("stdout = %q, want no report for replacement readiness failure", stdout.String())
	}
	for _, forbidden := range []string{"arguments", "redis_key", "nntmux_database", "Hash.Target", "--mysql-dsn"} {
		if strings.Contains(stderr.String(), forbidden) {
			t.Fatalf("stderr leaked %q: %q", forbidden, stderr.String())
		}
	}
}

func TestRunRequireReplacementReadyRejectsUnsupportedOnlyHashedFixnamesPlan(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--dry-run",
		"--output", "json",
		"--require-replacement-ready",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "hashed-fixnames",
			"description": "Run hashed fix-name passes",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:hashed-fixnames",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:hashed-fixnames",
			"seconds": 42
		},
		"commands": [
			{
				"command": "releases:fix-names",
				"arguments": {
					"method": "4",
					"--update": true,
					"--category": "hashed",
					"--set-status": true
				}
			}
		]
	}`), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want replacement readiness failure; stdout = %q stderr = %q", code, stdout.String(), stderr.String())
	}
	if !strings.Contains(stderr.String(), "unsupported hashed fix-name methods: 4") {
		t.Fatalf("stderr = %q, want unsupported method blocker", stderr.String())
	}
	if stdout.Len() != 0 {
		t.Fatalf("stdout = %q, want no report for replacement readiness failure", stdout.String())
	}
	for _, forbidden := range []string{"arguments", "redis_key", "nntmux_database"} {
		if strings.Contains(stderr.String(), forbidden) {
			t.Fatalf("stderr leaked %q: %q", forbidden, stderr.String())
		}
	}
}

func TestRunRequireReplacementReadyRejectsCatalogLanesWithoutExplicitReadiness(t *testing.T) {
	t.Parallel()

	for _, test := range []struct {
		name    string
		job     string
		plan    string
		blocker string
	}{
		{
			name:    "metadata refresh",
			job:     "metadata-refresh",
			plan:    "../../../tests/Fixtures/native-worker/catalog/metadata-refresh.json",
			blocker: "metadata-refresh embedded hashed fix-name commands are deferred to PHP",
		},
		{
			name:    "releases",
			job:     "releases",
			plan:    "../../../tests/Fixtures/native-worker/catalog/releases.json",
			blocker: "full release creation, categorization, and release-processing side effects remain PHP-owned",
		},
		{
			name:    "per group",
			job:     "per-group",
			plan:    "../../../tests/Fixtures/native-worker/catalog/per-group.json",
			blocker: "group update, backfill, release creation, and post-processing side effects remain PHP-owned",
		},
		{
			name:    "binaries",
			job:     "binaries",
			plan:    "../../../tests/Fixtures/native-worker/catalog/binaries.json",
			blocker: "production binary header acquisition, full header persistence, and cursor ownership remain PHP-owned",
		},
		{
			name:    "backfill",
			job:     "backfill",
			plan:    "../../../tests/Fixtures/native-worker/catalog/backfill.json",
			blocker: "production backfill acquisition, full header persistence, and cursor ownership remain PHP-owned",
		},
		{
			name:    "removecrap",
			job:     "removecrap",
			plan:    "../../../tests/Fixtures/native-worker/catalog/removecrap.json",
			blocker: "removecrap production commit requires live rollout proof",
		},
		{
			name:    "post tv",
			job:     "post-tv",
			plan:    "../../../tests/Fixtures/native-worker/catalog/post-tv.json",
			blocker: "metadata-provider lookups, NZB/NFO reads, release events, and full postprocess side effects remain PHP-owned",
		},
		{
			name:    "post movies",
			job:     "post-movies",
			plan:    "../../../tests/Fixtures/native-worker/catalog/post-movies.json",
			blocker: "metadata-provider lookups, NZB/NFO reads, release events, and full postprocess side effects remain PHP-owned",
		},
		{
			name:    "post amazon",
			job:     "post-amazon",
			plan:    "../../../tests/Fixtures/native-worker/catalog/post-amazon.json",
			blocker: "metadata-provider lookups, NZB/NFO reads, release events, and full postprocess side effects remain PHP-owned",
		},
		{
			name:    "post additional",
			job:     "post-additional",
			plan:    "../../../tests/Fixtures/native-worker/catalog/post-additional.json",
			blocker: "additional/NFO provider processing, NNTP/NZB/NFO reads, release events, and deferred metadata-refresh/hashed-fixnames side effects remain PHP-owned",
		},
	} {
		test := test
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()

			var stdout bytes.Buffer
			var stderr bytes.Buffer

			code := run([]string{
				"--plan", test.plan,
				"--dry-run",
				"--output", "json",
				"--require-replacement-ready",
			}, strings.NewReader(""), &stdout, &stderr)

			if code != 2 {
				t.Fatalf("run exit = %d, want replacement readiness failure; stderr = %q stdout = %q", code, stderr.String(), stdout.String())
			}
			for _, want := range []string{
				test.job + " catalog is not replacement-ready",
				test.blocker,
			} {
				if !strings.Contains(stderr.String(), want) {
					t.Fatalf("stderr = %q, missing %q", stderr.String(), want)
				}
			}
			if stdout.Len() != 0 {
				t.Fatalf("stdout = %q, want no report for replacement readiness failure", stdout.String())
			}
			for _, forbidden := range []string{"arguments", "redis_key", "nntmux_database", "--mysql-dsn"} {
				if strings.Contains(stderr.String(), forbidden) {
					t.Fatalf("stderr leaked %q: %q", forbidden, stderr.String())
				}
			}
		})
	}
}

func TestRunHashedFixnamesJSONReportIncludesReadinessWithoutMySQL(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--output", "json",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	var report struct {
		HashedFixnames struct {
			ReplacementReady     bool `json:"replacement_ready"`
			ReplacementReadiness struct {
				SupportedMethods    []string `json:"supported_methods"`
				UnsupportedMethods  []string `json:"unsupported_methods"`
				UnsupportedCommands int      `json:"unsupported_commands"`
				Blockers            []string `json:"blockers"`
			} `json:"replacement_readiness"`
			Writes int `json:"writes"`
		} `json:"hashed_fixnames"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.HashedFixnames.ReplacementReady {
		t.Fatalf("replacement_ready = true, want false")
	}
	readiness := report.HashedFixnames.ReplacementReadiness
	if !reflect.DeepEqual(readiness.SupportedMethods, []string{"16", "20"}) {
		t.Fatalf("supported_methods = %#v, want [16 20]", readiness.SupportedMethods)
	}
	if !reflect.DeepEqual(readiness.UnsupportedMethods, []string{"4", "6", "8", "10", "12", "14", "18", "21"}) {
		t.Fatalf("unsupported_methods = %#v", readiness.UnsupportedMethods)
	}
	if readiness.UnsupportedCommands != 8 {
		t.Fatalf("unsupported_commands = %d, want 8", readiness.UnsupportedCommands)
	}
	if len(readiness.Blockers) == 0 {
		t.Fatalf("blockers = %#v, want replacement blockers", readiness.Blockers)
	}
	if report.HashedFixnames.Writes != 0 {
		t.Fatalf("hashed_fixnames.writes = %d, want 0", report.HashedFixnames.Writes)
	}
}

func TestRunUsesEnvironmentFallbacksForCommittedConnectionSettings(t *testing.T) {
	t.Setenv("NNTMUX_NATIVE_MYSQL_DSN", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true")
	t.Setenv("NNTMUX_NATIVE_REDIS_ADDR", "redis:6379")
	t.Setenv("NNTMUX_NATIVE_LOCK_OWNER", "env-owner")

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--commit-miss-status",
		"--output", "json",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 1 {
		t.Fatalf("run exit = %d, want safety failure after env fallbacks; stderr = %q", code, stderr.String())
	}
	if strings.Contains(stderr.String(), "--commit-miss-status requires --mysql-dsn") ||
		strings.Contains(stderr.String(), "--commit-miss-status requires --redis-addr") {
		t.Fatalf("stderr = %q, want env fallback to satisfy connection options", stderr.String())
	}
	if !strings.Contains(stderr.String(), safety.AllowDestructiveTestDBEnv) &&
		!strings.Contains(stderr.String(), safety.AllowCommittedTestDBEnv) {
		t.Fatalf("stderr = %q, want native safety guard after env fallback", stderr.String())
	}
}

func TestRunRejectsCommitMissStatusForMetadataRefreshPlan(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/metadata-refresh.json",
		"--commit-miss-status",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
		"--redis-addr", "redis:6379",
		"--lock-owner", "metadata-refresh-owner",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want config error; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--commit-miss-status requires the hashed-fixnames job") {
		t.Fatalf("stderr = %q, want lane-scoped commit rejection", stderr.String())
	}
	if stdout.String() != "" {
		t.Fatalf("stdout = %q, want no report for config error", stdout.String())
	}
}

func TestRunRejectsBackfillDateModeWithoutSafeDate(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/backfill.json",
		"--dry-run",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
		"--backfill-days", "2",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want usage failure; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--backfill-days=2 requires --backfill-safe-date") {
		t.Fatalf("stderr = %q, want safe date requirement", stderr.String())
	}
	if strings.Contains(stderr.String(), "build backfill mysql dry-run") {
		t.Fatalf("stderr = %q, planner ran before safe date validation", stderr.String())
	}
}

func TestRunRejectsMalformedRemoveCrapCommandArguments(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--dry-run",
		"--mysql-dsn", "nntmux:nntmux@tcp(mariadb:3306)/nntmux_native_test?parseTime=true",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "removecrap",
			"description": "Remove configured unwanted releases",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:removecrap",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:removecrap",
			"seconds": 42
		},
		"commands": [
			{"command": "releases:remove-crap", "arguments": {"--type": "gibberish", "--time": "4", "--delete": true}},
			{"command": "releases:remove-crap", "arguments": []}
		]
	}`), &stdout, &stderr)

	if code != 1 {
		t.Fatalf("run exit = %d, want validation failure; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "removecrap command arguments must be an object") {
		t.Fatalf("stderr = %q, want malformed arguments error", stderr.String())
	}
	if strings.Contains(stderr.String(), "build removecrap mysql dry-run") {
		t.Fatalf("stderr = %q, planner ran before command validation", stderr.String())
	}
}

func TestRunDryRunRejectsInvalidPlanFromStdin(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "-",
		"--dry-run",
	}, strings.NewReader(`{
		"version": 1,
		"generated_at": "2026-06-15T12:00:00.000000Z",
		"mode": "shadow",
		"job": {
			"name": "metadata-refresh",
			"description": "Refresh external release-name evidence and run strong fix-name passes",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 900
		},
		"lock": {
			"name": "nntmux:distributed-worker:wrong",
			"seconds": 42
		},
		"commands": [
			{
				"command": "predb:refresh-external-metadata",
				"arguments": []
			}
		]
	}`), &stdout, &stderr)

	if code != 1 {
		t.Fatalf("run exit = %d, want validation failure", code)
	}

	if !strings.Contains(stderr.String(), "does not match") {
		t.Fatalf("stderr = %q, want lock validation error", stderr.String())
	}
}

func TestRunRejectsMissingDryRunFlag(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/metadata-refresh-plan.json",
	}, strings.NewReader(""), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run exit = 0, want failure")
	}
	if !strings.Contains(stderr.String(), "only --dry-run, --run-lane, --commit-lane-writes, or --commit-miss-status is supported") {
		t.Fatalf("stderr = %q, want dry-run rejection", stderr.String())
	}
}
