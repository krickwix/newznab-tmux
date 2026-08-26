package fixnames

import (
	"encoding/json"
	"os"
	"reflect"
	"strings"
	"testing"

	"nntmux-native/internal/worker"
)

func TestBuildPlanSummarizesRegularFixnamesCatalogWithoutReplacementReadiness(t *testing.T) {
	file, err := os.Open("../../../tests/Fixtures/native-worker/catalog/fixnames.json")
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

	if plan.Commands != 15 || plan.Methods != 13 || plan.Categories != 2 || plan.LimitedCommands != 4 || plan.UpdateCommands != 15 || plan.SetStatusCommands != 15 || plan.ShowCommands != 15 || plan.Writes != 0 {
		t.Fatalf("plan = %#v", plan)
	}
	if plan.ReplacementReady {
		t.Fatalf("replacement ready = true, want false")
	}
	readiness := plan.ReplacementReadiness
	wantSupported := []string{"15", "19"}
	if !reflect.DeepEqual(readiness.SupportedMethods, wantSupported) {
		t.Fatalf("supported methods = %#v, want %#v", readiness.SupportedMethods, wantSupported)
	}
	wantUnsupported := []string{"3", "4", "5", "6", "7", "8", "9", "11", "13", "17", "21"}
	if !reflect.DeepEqual(readiness.UnsupportedMethods, wantUnsupported) {
		t.Fatalf("unsupported methods = %#v, want %#v", readiness.UnsupportedMethods, wantUnsupported)
	}
	if readiness.UnsupportedCommands != 13 {
		t.Fatalf("unsupported commands = %d, want 13", readiness.UnsupportedCommands)
	}
	if readiness.NativeCommands != 2 {
		t.Fatalf("native commands = %d, want 2", readiness.NativeCommands)
	}
	if len(readiness.Blockers) == 0 || !strings.Contains(strings.Join(readiness.Blockers, "; "), "remaining regular fix-name methods are deferred to PHP") {
		t.Fatalf("blockers = %#v, want PHP-owned blocker", readiness.Blockers)
	}

	summary := DryRunSummary(plan)
	for _, want := range []string{
		"fixnames dry-run",
		"commands=15",
		"methods=13",
		"categories=2",
		"limited-commands=4",
		"update-commands=15",
		"set-status-commands=15",
		"show-commands=15",
		"replacement-ready=false",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("summary = %q, missing %q", summary, want)
		}
	}
}

func TestBuildPlanRejectsUnsupportedFixnamesCommand(t *testing.T) {
	_, err := BuildPlan(worker.Plan{
		Job: worker.Job{Name: "fixnames"},
		Commands: []worker.Command{{
			Command:   "multiprocessing:releases",
			Arguments: []any{},
		}},
	})
	if err == nil {
		t.Fatalf("BuildPlan succeeded with unsupported command")
	}
	if !strings.Contains(err.Error(), `unsupported fixnames command "multiprocessing:releases"`) {
		t.Fatalf("error = %v, want unsupported fixnames command", err)
	}
}

func TestBuildPlanRejectsNonFixnamesJob(t *testing.T) {
	_, err := BuildPlan(worker.Plan{
		Job: worker.Job{Name: "hashed-fixnames"},
		Commands: []worker.Command{{
			Command: "releases:fix-names",
			Arguments: map[string]any{
				"method":       "6",
				"--update":     true,
				"--category":   "other",
				"--set-status": true,
				"--show":       true,
			},
		}},
	})
	if err == nil {
		t.Fatalf("BuildPlan succeeded with non-fixnames job")
	}
	if !strings.Contains(err.Error(), `fixnames planner requires job "fixnames"`) {
		t.Fatalf("error = %v, want non-fixnames job rejection", err)
	}
}

func TestBuildPlanRejectsUnsupportedFixnamesCategory(t *testing.T) {
	_, err := BuildPlan(worker.Plan{
		Job: worker.Job{Name: "fixnames"},
		Commands: []worker.Command{{
			Command: "releases:fix-names",
			Arguments: map[string]any{
				"method":       "6",
				"--update":     true,
				"--category":   "hashed",
				"--set-status": true,
				"--show":       true,
			},
		}},
	})
	if err == nil {
		t.Fatalf("BuildPlan succeeded with unsupported category")
	}
	if !strings.Contains(err.Error(), `unsupported fixnames category "hashed"`) {
		t.Fatalf("error = %v, want unsupported category", err)
	}
}

func TestFixnamesPlanJSONDoesNotExposeRawCommandArguments(t *testing.T) {
	plan := Plan{
		Commands:          1,
		Methods:           1,
		Categories:        1,
		LimitedCommands:   1,
		UpdateCommands:    1,
		SetStatusCommands: 1,
		ShowCommands:      1,
		ReplacementReady:  false,
		ReplacementReadiness: ReplacementReadiness{
			UnsupportedMethods:  []string{"6"},
			UnsupportedCommands: 1,
			Blockers:            []string{"remaining regular fix-name methods are deferred to PHP"},
		},
		Writes: 0,
	}

	encoded, err := json.Marshal(plan)
	if err != nil {
		t.Fatalf("marshal Plan: %v", err)
	}

	for _, forbidden := range []string{"arguments", "redis_key", "nntmux_database", "--category", "--limit", "other", "movies", "releases:fix-names"} {
		if strings.Contains(string(encoded), forbidden) {
			t.Fatalf("Plan JSON exposed %q: %s", forbidden, encoded)
		}
	}
}
