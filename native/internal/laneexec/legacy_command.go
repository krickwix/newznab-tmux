package laneexec

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"sync"
)

type CommandSpec struct {
	Name      string
	Arguments []string
}

func Summary(job string, report Report) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "native lane execution")
	fmt.Fprintf(&buffer, "job=%s\n", job)
	fmt.Fprintf(&buffer, "commands=%d\n", report.Commands)
	fmt.Fprintf(&buffer, "succeeded=%d\n", report.Succeeded)
	fmt.Fprintf(&buffer, "failed=%d\n", report.Failed)
	fmt.Fprintf(&buffer, "max-processes=%d\n", report.MaxProcesses)
	fmt.Fprintf(&buffer, "exit-code=%d\n", report.ExitCode)

	return buffer.String()
}

type Result struct {
	Argv     []string
	ExitCode int
	Error    error
}

type Executor interface {
	Run(ctx context.Context, argv []string) Result
}

type Options struct {
	ArtisanBinary      string
	ArtisanScript      string
	ArtisanEnvironment []string
	MaxProcesses       int
	Executor           Executor
}

type Report struct {
	Commands     int      `json:"commands"`
	Succeeded    int      `json:"succeeded"`
	Failed       int      `json:"failed"`
	MaxProcesses int      `json:"max_processes"`
	ExitCode     int      `json:"exit_code"`
	Failures     []string `json:"failures,omitempty"`
}

func ParseLegacyCommand(command string) (CommandSpec, error) {
	parts := strings.Fields(command)
	if len(parts) == 0 {
		return CommandSpec{}, fmt.Errorf("legacy command is empty")
	}

	switch parts[0] {
	case "update_group_headers":
		if len(parts) != 2 {
			return CommandSpec{}, fmt.Errorf("update_group_headers requires group")
		}
		return CommandSpec{Name: "group:update-headers", Arguments: []string{parts[1]}}, nil
	case "part_repair":
		if len(parts) != 2 {
			return CommandSpec{}, fmt.Errorf("part_repair requires group")
		}
		return CommandSpec{Name: "binaries:part-repair", Arguments: []string{parts[1]}}, nil
	case "get_range":
		if len(parts) != 6 {
			return CommandSpec{}, fmt.Errorf("get_range requires mode, group, first, last, and worker index")
		}
		if parts[1] != "binaries" && parts[1] != "backfill" {
			return CommandSpec{}, fmt.Errorf("unsupported get_range mode %q", parts[1])
		}
		return CommandSpec{Name: "articles:get-range", Arguments: []string{parts[1], parts[2], parts[3], parts[4]}}, nil
	case "releases":
		if len(parts) != 2 {
			return CommandSpec{}, fmt.Errorf("releases requires group id")
		}
		return CommandSpec{Name: "releases:process", Arguments: []string{parts[1]}}, nil
	case "update_per_group":
		if len(parts) != 2 {
			return CommandSpec{}, fmt.Errorf("update_per_group requires group id")
		}
		return CommandSpec{Name: "group:update-all", Arguments: []string{parts[1]}}, nil
	case "postprocess:guid":
		if len(parts) != 3 {
			return CommandSpec{}, fmt.Errorf("postprocess:guid requires type and bucket")
		}
		return CommandSpec{Name: "postprocess:guid", Arguments: []string{parts[1], parts[2]}}, nil
	case "postprocess:tv-pipeline":
		if len(parts) != 4 {
			return CommandSpec{}, fmt.Errorf("postprocess:tv-pipeline requires bucket, renamed mode, and mode option")
		}
		if parts[3] != "--mode=pipeline" {
			return CommandSpec{}, fmt.Errorf("postprocess:tv-pipeline requires --mode=pipeline")
		}
		return CommandSpec{Name: "postprocess:tv-pipeline", Arguments: []string{parts[1], parts[2], parts[3]}}, nil
	default:
		return CommandSpec{}, fmt.Errorf("unsupported legacy command %q", parts[0])
	}
}

func (command CommandSpec) Argv(artisanBinary string, artisanScript string) []string {
	if artisanBinary == "" {
		artisanBinary = "php"
	}
	if artisanScript == "" {
		artisanScript = "artisan"
	}

	argv := []string{artisanBinary, artisanScript, command.Name}
	argv = append(argv, command.Arguments...)

	return argv
}

func Run(ctx context.Context, commands []CommandSpec, opts Options) Report {
	maxProcesses := opts.MaxProcesses
	if maxProcesses < 1 {
		maxProcesses = 1
	}
	executor := opts.Executor
	if executor == nil {
		executor = ProcessExecutor{Environment: opts.ArtisanEnvironment}
	}

	report := Report{
		Commands:     len(commands),
		MaxProcesses: maxProcesses,
		Failures:     []string{},
	}
	if len(commands) == 0 {
		return report
	}

	jobs := make(chan CommandSpec)
	results := make(chan Result)
	workerCount := maxProcesses
	if workerCount > len(commands) {
		workerCount = len(commands)
	}

	var workers sync.WaitGroup
	for i := 0; i < workerCount; i++ {
		workers.Add(1)
		go func() {
			defer workers.Done()
			for command := range jobs {
				results <- executor.Run(ctx, command.Argv(opts.ArtisanBinary, opts.ArtisanScript))
			}
		}()
	}

	go func() {
		workers.Wait()
		close(results)
	}()

	nextCommand := 0
	inFlight := 0
	stopping := false
	jobsClosed := false
	schedule := func() {
		for !stopping && nextCommand < len(commands) && inFlight < workerCount {
			jobs <- commands[nextCommand]
			nextCommand++
			inFlight++
		}

		if !jobsClosed && (stopping || nextCommand == len(commands)) {
			close(jobs)
			jobsClosed = true
		}
	}

	schedule()

	for result := range results {
		inFlight--
		if result.ExitCode == 0 && result.Error == nil {
			report.Succeeded++
			schedule()

			continue
		}

		report.Failed++
		report.ExitCode = 1
		report.Failures = append(report.Failures, strings.Join(result.Argv, " "))
		stopping = true
		schedule()
	}

	return report
}

type ProcessExecutor struct {
	Environment []string
}

func (executor ProcessExecutor) Run(ctx context.Context, argv []string) Result {
	if len(argv) == 0 {
		return Result{ExitCode: 1, Error: fmt.Errorf("argv is empty")}
	}

	command := exec.CommandContext(ctx, argv[0], argv[1:]...)
	if len(executor.Environment) > 0 {
		command.Env = append(filteredEnvironment(os.Environ(), executor.Environment), executor.Environment...)
	}
	if err := command.Run(); err != nil {
		if exitError, ok := err.(*exec.ExitError); ok {
			return Result{Argv: argv, ExitCode: exitError.ExitCode(), Error: err}
		}

		return Result{Argv: argv, ExitCode: 1, Error: err}
	}

	if err := recordLeafStartupSmoke(argv, command.Env); err != nil {
		return Result{Argv: argv, ExitCode: 1, Error: err}
	}

	return Result{Argv: argv, ExitCode: 0}
}

func filteredEnvironment(environment []string, overrides []string) []string {
	overrideKeys := map[string]struct{}{}
	for _, entry := range overrides {
		key, _, ok := strings.Cut(entry, "=")
		if ok {
			overrideKeys[key] = struct{}{}
		}
	}

	filtered := environment[:0]
	for _, entry := range environment {
		key, _, ok := strings.Cut(entry, "=")
		if ok {
			if _, overridden := overrideKeys[key]; overridden {
				continue
			}
		}
		filtered = append(filtered, entry)
	}

	return filtered
}

func recordLeafStartupSmoke(argv []string, environment []string) error {
	if environmentValue(environment, "NNTMUX_NATIVE_LEAF_STARTUP_SMOKE") != "1" {
		return nil
	}

	logPath := environmentValue(environment, "NNTMUX_NATIVE_LEAF_STARTUP_SMOKE_LOG")
	if strings.TrimSpace(logPath) == "" {
		return fmt.Errorf("native leaf startup smoke log is not configured")
	}

	if len(argv) < 3 {
		return fmt.Errorf("native leaf startup smoke argv is incomplete")
	}

	line := strings.Join(argv[2:], " ")
	file, err := os.OpenFile(logPath, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o666)
	if err != nil {
		return err
	}
	defer file.Close()

	_, err = fmt.Fprintln(file, line)

	return err
}

func environmentValue(environment []string, key string) string {
	prefix := key + "="
	for _, entry := range environment {
		if strings.HasPrefix(entry, prefix) {
			return strings.TrimPrefix(entry, prefix)
		}
	}

	return ""
}
