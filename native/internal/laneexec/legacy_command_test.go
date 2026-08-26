package laneexec

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
)

func TestParseLegacyCommandBuildsArtisanArgvForFirstWorkerLanes(t *testing.T) {
	tests := []struct {
		name    string
		command string
		want    []string
	}{
		{
			name:    "binaries group header update",
			command: "update_group_headers  alt.binaries.movies",
			want:    []string{"php", "artisan", "group:update-headers", "alt.binaries.movies"},
		},
		{
			name:    "binaries part repair",
			command: "part_repair  alt.binaries.movies",
			want:    []string{"php", "artisan", "binaries:part-repair", "alt.binaries.movies"},
		},
		{
			name:    "binaries article range",
			command: "get_range  binaries  alt.binaries.movies  1001  11000  2",
			want:    []string{"php", "artisan", "articles:get-range", "binaries", "alt.binaries.movies", "1001", "11000"},
		},
		{
			name:    "backfill article range",
			command: "get_range  backfill  alt.binaries.vintage-film  2  104  1",
			want:    []string{"php", "artisan", "articles:get-range", "backfill", "alt.binaries.vintage-film", "2", "104"},
		},
		{
			name:    "releases group processing",
			command: "releases  42",
			want:    []string{"php", "artisan", "releases:process", "42"},
		},
		{
			name:    "per-group all-in-one processing",
			command: "update_per_group  42",
			want:    []string{"php", "artisan", "group:update-all", "42"},
		},
		{
			name:    "postprocess guid bucket",
			command: "postprocess:guid movie m",
			want:    []string{"php", "artisan", "postprocess:guid", "movie", "m"},
		},
		{
			name:    "postprocess tv pipeline bucket",
			command: "postprocess:tv-pipeline A 2 --mode=pipeline",
			want:    []string{"php", "artisan", "postprocess:tv-pipeline", "A", "2", "--mode=pipeline"},
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			spec, err := ParseLegacyCommand(test.command)
			if err != nil {
				t.Fatalf("ParseLegacyCommand: %v", err)
			}

			if got := spec.Argv("php", "artisan"); !reflect.DeepEqual(got, test.want) {
				t.Fatalf("Argv = %#v, want %#v", got, test.want)
			}
		})
	}
}

func TestParseLegacyCommandRejectsUnsupportedOrMalformedCommands(t *testing.T) {
	tests := []string{
		"",
		"unknown  alt.binaries.movies",
		"get_range  binaries  alt.binaries.movies  1001",
		"get_range  other  alt.binaries.movies  1001  11000  2",
		"releases",
		"update_per_group",
		"postprocess:guid movie",
		"postprocess:tv-pipeline A 2",
	}

	for _, command := range tests {
		t.Run(command, func(t *testing.T) {
			if _, err := ParseLegacyCommand(command); err == nil {
				t.Fatalf("ParseLegacyCommand(%q) succeeded, want error", command)
			}
		})
	}
}

func TestRunExecutesQueueWithBoundedConcurrencyAndReportsFailures(t *testing.T) {
	executor := &recordingExecutor{
		failCommand: []string{"php", "artisan", "articles:get-range", "binaries", "alt.binaries.movies", "1001", "11000"},
	}
	commands := []CommandSpec{
		mustParse(t, "update_group_headers  alt.binaries.new"),
		mustParse(t, "get_range  binaries  alt.binaries.movies  1001  11000  2"),
		mustParse(t, "releases  42"),
	}

	report := Run(context.Background(), commands, Options{
		ArtisanBinary: "php",
		ArtisanScript: "artisan",
		MaxProcesses:  2,
		Executor:      executor,
	})

	if report.Commands != 3 {
		t.Fatalf("Commands = %d, want 3", report.Commands)
	}
	if report.Succeeded != 2 || report.Failed != 1 || report.ExitCode != 1 {
		t.Fatalf("report = %#v, want 2 succeeded, 1 failed, exit 1", report)
	}
	if report.MaxProcesses != 2 {
		t.Fatalf("MaxProcesses = %d, want 2", report.MaxProcesses)
	}
	if len(executor.calls) != 3 {
		t.Fatalf("executor calls = %#v, want 3 calls", executor.calls)
	}
}

func TestRunStopsSchedulingCommandsAfterFirstFailure(t *testing.T) {
	executor := &recordingExecutor{
		failCommand: []string{"php", "artisan", "articles:get-range", "binaries", "alt.binaries.movies", "1001", "11000"},
	}
	commands := []CommandSpec{
		mustParse(t, "update_group_headers  alt.binaries.new"),
		mustParse(t, "get_range  binaries  alt.binaries.movies  1001  11000  2"),
		mustParse(t, "releases  42"),
	}

	report := Run(context.Background(), commands, Options{
		ArtisanBinary: "php",
		ArtisanScript: "artisan",
		MaxProcesses:  1,
		Executor:      executor,
	})

	if report.Succeeded != 1 || report.Failed != 1 || report.ExitCode != 1 {
		t.Fatalf("report = %#v, want 1 succeeded, 1 failed, exit 1", report)
	}
	if len(executor.calls) != 2 {
		t.Fatalf("executor calls = %#v, want fail-fast to skip third command", executor.calls)
	}
	for _, call := range executor.calls {
		if reflect.DeepEqual(call, []string{"php", "artisan", "releases:process", "42"}) {
			t.Fatalf("executor calls = %#v, want releases command skipped after failure", executor.calls)
		}
	}
}

func TestProcessExecutorPassesConfiguredEnvironmentToLeafCommand(t *testing.T) {
	t.Setenv("NNTMUX_NATIVE_LEAF_STARTUP_SMOKE", "")

	dir := t.TempDir()
	logPath := filepath.Join(dir, "env.log")
	smokeLogPath := filepath.Join(dir, "smoke.log")
	scriptPath := filepath.Join(dir, "leaf.sh")
	script := "#!/usr/bin/env sh\nprintf '%s\\n' \"$NNTMUX_NATIVE_LEAF_STARTUP_SMOKE\" \"$NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG\" > \"" + logPath + "\"\n"
	if err := os.WriteFile(scriptPath, []byte(script), 0o755); err != nil {
		t.Fatalf("write leaf script: %v", err)
	}

	result := ProcessExecutor{
		Environment: []string{
			"NNTMUX_NATIVE_LEAF_STARTUP_SMOKE=1",
			"NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG=" + smokeLogPath,
		},
	}.Run(context.Background(), []string{scriptPath, "artisan", "group:update-headers", "alt.binaries.small"})
	if result.Error != nil || result.ExitCode != 0 {
		t.Fatalf("Run result = %#v, want success", result)
	}

	contents, err := os.ReadFile(logPath)
	if err != nil {
		t.Fatalf("read env log: %v", err)
	}
	if got := strings.TrimSpace(string(contents)); got != "1\n"+smokeLogPath {
		t.Fatalf("leaf env = %q, want startup smoke env", got)
	}

	smokeContents, err := os.ReadFile(smokeLogPath)
	if err != nil {
		t.Fatalf("read smoke log: %v", err)
	}
	if got := strings.TrimSpace(string(smokeContents)); got != "group:update-headers alt.binaries.small" {
		t.Fatalf("smoke log = %q, want command line", got)
	}
}

func mustParse(t *testing.T, command string) CommandSpec {
	t.Helper()

	spec, err := ParseLegacyCommand(command)
	if err != nil {
		t.Fatalf("ParseLegacyCommand(%q): %v", command, err)
	}

	return spec
}

type recordingExecutor struct {
	calls       [][]string
	failCommand []string
}

func (executor *recordingExecutor) Run(ctx context.Context, argv []string) Result {
	executor.calls = append(executor.calls, append([]string{}, argv...))
	if reflect.DeepEqual(argv, executor.failCommand) {
		return Result{Argv: argv, ExitCode: 1, Error: errors.New("forced failure")}
	}

	return Result{Argv: argv, ExitCode: 0}
}
