package worker

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"strings"
)

const (
	SupportedPlanVersion = 1
	SupportedMode        = "shadow"
)

var supportedJobs = map[string]struct{}{
	"binaries":         {},
	"backfill":         {},
	"releases":         {},
	"fixnames":         {},
	"hashed-fixnames":  {},
	"removecrap":       {},
	"post-additional":  {},
	"metadata-refresh": {},
	"post-tv":          {},
	"post-movies":      {},
	"post-amazon":      {},
	"irc":              {},
	"per-group":        {},
}

type Plan struct {
	Version     int       `json:"version"`
	GeneratedAt string    `json:"generated_at"`
	Mode        string    `json:"mode"`
	Job         Job       `json:"job"`
	Lock        Lock      `json:"lock"`
	Commands    []Command `json:"commands"`
}

type Job struct {
	Name           string  `json:"name"`
	Description    string  `json:"description"`
	Enabled        bool    `json:"enabled"`
	DisabledReason *string `json:"disabled_reason"`
	Sleep          int     `json:"sleep"`
}

type Lock struct {
	Name     string `json:"name"`
	RedisKey string `json:"redis_key"`
	Seconds  int    `json:"seconds"`
}

type Command struct {
	Command   string `json:"command"`
	Arguments any    `json:"arguments"`
}

func DecodePlan(reader io.Reader) (Plan, error) {
	var plan Plan

	decoder := json.NewDecoder(reader)
	decoder.DisallowUnknownFields()

	if err := decoder.Decode(&plan); err != nil {
		return Plan{}, fmt.Errorf("decode native plan: %w", err)
	}

	if err := decoder.Decode(&struct{}{}); err != io.EOF {
		return Plan{}, fmt.Errorf("decode native plan: trailing JSON data")
	}

	return plan, nil
}

func ValidatePlan(plan Plan) error {
	if plan.Version != SupportedPlanVersion {
		return fmt.Errorf("unsupported native plan version %d", plan.Version)
	}

	if plan.Mode != SupportedMode {
		return fmt.Errorf("only shadow mode is supported, got %q", plan.Mode)
	}

	if plan.Job.Name == "" {
		return fmt.Errorf("native plan job name is required")
	}

	if _, ok := supportedJobs[plan.Job.Name]; !ok {
		return fmt.Errorf("unsupported native worker job %q", plan.Job.Name)
	}

	expectedLock := "nntmux:distributed-worker:" + plan.Job.Name
	if plan.Lock.Name != expectedLock {
		return fmt.Errorf("native plan lock %q does not match %q", plan.Lock.Name, expectedLock)
	}

	if plan.Lock.RedisKey == "" {
		return fmt.Errorf("native plan redis lock key is required")
	}

	if !strings.HasSuffix(plan.Lock.RedisKey, plan.Lock.Name) {
		return fmt.Errorf("native plan redis lock key %q does not end with logical lock %q", plan.Lock.RedisKey, plan.Lock.Name)
	}

	if plan.Lock.Seconds < 1 {
		return fmt.Errorf("native plan lock seconds must be positive")
	}

	if plan.Job.Sleep < 1 {
		return fmt.Errorf("native plan job sleep must be positive")
	}

	if plan.Job.Enabled && len(plan.Commands) == 0 {
		return fmt.Errorf("enabled native plan must include at least one command")
	}

	for index, command := range plan.Commands {
		if command.Command == "" {
			return fmt.Errorf("native plan command %d name is required", index)
		}
	}

	return nil
}

func DryRunSummary(plan Plan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "native worker dry-run")
	fmt.Fprintf(&buffer, "job=%s enabled=%t sleep=%d\n", plan.Job.Name, plan.Job.Enabled, plan.Job.Sleep)
	fmt.Fprintf(&buffer, "lock=%s seconds=%d\n", plan.Lock.Name, plan.Lock.Seconds)
	fmt.Fprintf(&buffer, "commands=%d\n", len(plan.Commands))

	return buffer.String()
}
