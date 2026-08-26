package worker

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestDecodePlanAcceptsMetadataRefreshFixture(t *testing.T) {
	t.Parallel()

	file, err := os.Open("../../../tests/Fixtures/native-worker/metadata-refresh-plan.json")
	if err != nil {
		t.Fatalf("open fixture: %v", err)
	}
	defer file.Close()

	plan, err := DecodePlan(file)
	if err != nil {
		t.Fatalf("decode plan: %v", err)
	}

	if err := ValidatePlan(plan); err != nil {
		t.Fatalf("validate plan: %v", err)
	}

	if plan.Job.Name != "metadata-refresh" {
		t.Fatalf("job name = %q, want metadata-refresh", plan.Job.Name)
	}
	if plan.Lock.Name != "nntmux:distributed-worker:metadata-refresh" {
		t.Fatalf("lock name = %q", plan.Lock.Name)
	}
	if plan.Lock.RedisKey == "" || !strings.HasSuffix(plan.Lock.RedisKey, plan.Lock.Name) {
		t.Fatalf("redis lock key = %q, want key ending in %q", plan.Lock.RedisKey, plan.Lock.Name)
	}
	if len(plan.Commands) != 3 {
		t.Fatalf("commands = %d, want 3", len(plan.Commands))
	}
}

func TestCatalogFixturesValidateEverySupportedLane(t *testing.T) {
	t.Parallel()

	files, err := filepath.Glob("../../../tests/Fixtures/native-worker/catalog/*.json")
	if err != nil {
		t.Fatalf("glob catalog fixtures: %v", err)
	}

	if len(files) != len(supportedJobs) {
		t.Fatalf("catalog fixture count = %d, want %d", len(files), len(supportedJobs))
	}

	expectedJobs := make(map[string]struct{}, len(supportedJobs))
	for job := range supportedJobs {
		expectedJobs[job] = struct{}{}
	}

	for _, path := range files {
		path := path
		t.Run(filepath.Base(path), func(t *testing.T) {
			file, err := os.Open(path)
			if err != nil {
				t.Fatalf("open fixture: %v", err)
			}
			defer file.Close()

			plan, err := DecodePlan(file)
			if err != nil {
				t.Fatalf("decode fixture: %v", err)
			}

			if err := ValidatePlan(plan); err != nil {
				t.Fatalf("validate fixture: %v", err)
			}

			job := strings.TrimSuffix(filepath.Base(path), ".json")
			if plan.Job.Name != job {
				t.Fatalf("fixture %s job name = %q", path, plan.Job.Name)
			}

			delete(expectedJobs, job)
		})
	}

	if len(expectedJobs) > 0 {
		t.Fatalf("missing catalog fixtures for jobs: %v", expectedJobs)
	}
}

func TestValidatePlanRejectsUnsupportedVersion(t *testing.T) {
	t.Parallel()

	plan := validPlan()
	plan.Version = 2

	if err := ValidatePlan(plan); err == nil || !strings.Contains(err.Error(), "unsupported native plan version") {
		t.Fatalf("ValidatePlan error = %v, want unsupported version", err)
	}
}

func TestValidatePlanRejectsNonShadowMode(t *testing.T) {
	t.Parallel()

	plan := validPlan()
	plan.Mode = "execute"

	if err := ValidatePlan(plan); err == nil || !strings.Contains(err.Error(), "only shadow mode is supported") {
		t.Fatalf("ValidatePlan error = %v, want non-shadow rejection", err)
	}
}

func TestValidatePlanRejectsUnsupportedJob(t *testing.T) {
	t.Parallel()

	plan := validPlan()
	plan.Job.Name = "not-a-job"
	plan.Lock.Name = "nntmux:distributed-worker:not-a-job"

	if err := ValidatePlan(plan); err == nil || !strings.Contains(err.Error(), "unsupported native worker job") {
		t.Fatalf("ValidatePlan error = %v, want unsupported job rejection", err)
	}
}

func TestValidatePlanAcceptsEveryDistributedWorkerLane(t *testing.T) {
	t.Parallel()

	for _, job := range []string{
		"binaries",
		"backfill",
		"releases",
		"fixnames",
		"hashed-fixnames",
		"removecrap",
		"post-additional",
		"metadata-refresh",
		"post-tv",
		"post-movies",
		"post-amazon",
		"irc",
		"per-group",
	} {
		job := job
		t.Run(job, func(t *testing.T) {
			t.Parallel()

			plan := validPlan()
			plan.Job.Name = job
			plan.Lock.Name = "nntmux:distributed-worker:" + job
			plan.Lock.RedisKey = "nntmux_database_nntmux-cache-" + plan.Lock.Name

			if err := ValidatePlan(plan); err != nil {
				t.Fatalf("ValidatePlan(%q) error = %v", job, err)
			}
		})
	}
}

func TestDecodePlanRejectsUnknownFieldsAndTrailingData(t *testing.T) {
	t.Parallel()

	_, err := DecodePlan(strings.NewReader(`{"version":1,"generated_at":"2026-06-15T12:00:00Z","mode":"shadow","job":{"name":"metadata-refresh","description":"","enabled":true,"disabled_reason":null,"sleep":900},"lock":{"name":"nntmux:distributed-worker:metadata-refresh","seconds":42},"commands":[],"secret":"nope"}`))
	if err == nil || !strings.Contains(err.Error(), "unknown field") {
		t.Fatalf("DecodePlan unknown field error = %v", err)
	}

	var payload bytes.Buffer
	payload.WriteString(`{"version":1,"generated_at":"2026-06-15T12:00:00Z","mode":"shadow","job":{"name":"metadata-refresh","description":"","enabled":true,"disabled_reason":null,"sleep":900},"lock":{"name":"nntmux:distributed-worker:metadata-refresh","seconds":42},"commands":[]}`)
	payload.WriteString(`{}`)

	_, err = DecodePlan(&payload)
	if err == nil || !strings.Contains(err.Error(), "trailing JSON data") {
		t.Fatalf("DecodePlan trailing data error = %v", err)
	}
}

func TestDecodePlanAcceptsEmptyCommandArgumentsArrayFromPhp(t *testing.T) {
	t.Parallel()

	plan, err := DecodePlan(strings.NewReader(`{"version":1,"generated_at":"2026-06-15T12:00:00Z","mode":"shadow","job":{"name":"irc","description":"","enabled":true,"disabled_reason":null,"sleep":10},"lock":{"name":"nntmux:distributed-worker:irc","redis_key":"nntmux_database_nntmux-cache-nntmux:distributed-worker:irc","seconds":42},"commands":[{"command":"irc:scrape","arguments":[]}]}`))
	if err != nil {
		t.Fatalf("DecodePlan empty arguments array error = %v", err)
	}

	if err := ValidatePlan(plan); err != nil {
		t.Fatalf("ValidatePlan empty arguments array error = %v", err)
	}
}

func TestValidatePlanRejectsInvalidLockSleepAndCommand(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name string
		edit func(*Plan)
		want string
	}{
		{
			name: "mismatched lock",
			edit: func(plan *Plan) { plan.Lock.Name = "wrong" },
			want: "does not match",
		},
		{
			name: "nonpositive lock seconds",
			edit: func(plan *Plan) { plan.Lock.Seconds = 0 },
			want: "lock seconds must be positive",
		},
		{
			name: "empty redis key",
			edit: func(plan *Plan) { plan.Lock.RedisKey = "" },
			want: "native plan redis lock key is required",
		},
		{
			name: "redis key missing logical lock suffix",
			edit: func(plan *Plan) { plan.Lock.RedisKey = "nntmux_database_nntmux-cache-other-lock" },
			want: "does not end with",
		},
		{
			name: "nonpositive sleep",
			edit: func(plan *Plan) { plan.Job.Sleep = 0 },
			want: "job sleep must be positive",
		},
		{
			name: "empty command",
			edit: func(plan *Plan) { plan.Commands = []Command{{Command: ""}} },
			want: "command 0 name is required",
		},
		{
			name: "enabled plan with no commands",
			edit: func(plan *Plan) { plan.Commands = nil },
			want: "enabled native plan must include at least one command",
		},
	}

	for _, test := range tests {
		test := test
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()

			plan := validPlan()
			test.edit(&plan)

			if err := ValidatePlan(plan); err == nil || !strings.Contains(err.Error(), test.want) {
				t.Fatalf("ValidatePlan error = %v, want %q", err, test.want)
			}
		})
	}
}

func TestDryRunSummaryDoesNotPrintCommandArguments(t *testing.T) {
	t.Parallel()

	plan := validPlan()
	plan.Commands = []Command{{
		Command: "touch",
		Arguments: map[string]any{
			"path": "/tmp/nntmux-native-worker-pwned",
		},
	}}

	summary := DryRunSummary(plan)

	if strings.Contains(summary, "pwned") || strings.Contains(summary, "/tmp/") {
		t.Fatalf("dry-run summary exposed command arguments: %s", summary)
	}
	if !strings.Contains(summary, "commands=1") {
		t.Fatalf("dry-run summary = %q, want command count", summary)
	}
}

func validPlan() Plan {
	return Plan{
		Version:     1,
		GeneratedAt: "2026-06-15T12:00:00Z",
		Mode:        "shadow",
		Job: Job{
			Name:           "metadata-refresh",
			Description:    "Refresh external release-name evidence and run strong fix-name passes",
			Enabled:        true,
			DisabledReason: nil,
			Sleep:          900,
		},
		Lock: Lock{
			Name:     "nntmux:distributed-worker:metadata-refresh",
			RedisKey: "nntmux_database_nntmux-cache-nntmux:distributed-worker:metadata-refresh",
			Seconds:  42,
		},
		Commands: []Command{{
			Command:   "predb:refresh-external-metadata",
			Arguments: map[string]any{"--source": []any{"all"}},
		}},
	}
}
