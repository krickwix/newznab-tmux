package main

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"
	"sort"
	"strconv"
	"strings"
	"time"

	"nntmux-native/internal/backfill"
	"nntmux-native/internal/binaries"
	"nntmux-native/internal/fixnames"
	"nntmux-native/internal/irc"
	"nntmux-native/internal/laneexec"
	workerlock "nntmux-native/internal/lock"
	"nntmux-native/internal/metadata"
	"nntmux-native/internal/namefix"
	"nntmux-native/internal/nntp"
	"nntmux-native/internal/pergroup"
	"nntmux-native/internal/postprocess"
	"nntmux-native/internal/releases"
	"nntmux-native/internal/removecrap"
	"nntmux-native/internal/safety"
	"nntmux-native/internal/searchdoc"
	"nntmux-native/internal/worker"

	_ "github.com/go-sql-driver/mysql"
	"github.com/redis/go-redis/v9"
	"github.com/redis/go-redis/v9/maintnotifications"
)

const (
	lockModeAcquire = "acquire"
	lockModeHeld    = "held"
)

func main() {
	os.Exit(run(os.Args[1:], os.Stdin, os.Stdout, os.Stderr))
}

func run(args []string, stdin io.Reader, stdout io.Writer, stderr io.Writer) int {
	flags := flag.NewFlagSet("nntmux-worker", flag.ContinueOnError)
	flags.SetOutput(stderr)

	planPath := flags.String("plan", "", "path to a native worker plan JSON file")
	dryRun := flags.Bool("dry-run", false, "validate the plan and print a dry-run summary")
	runLane := flags.Bool("run-lane", false, "execute supported native worker lane queues through explicit Artisan leaf commands")
	mysqlDSN := flags.String("mysql-dsn", "", "optional MariaDB DSN for read-only lane dry-run planning")
	mysqlDSNEnv := flags.Bool("mysql-dsn-env", false, "read MariaDB DSN from NNTMUX_NATIVE_MYSQL_DSN for read-only lane dry-run planning")
	output := flags.String("output", "text", "dry-run output format: text or json")
	rehearseWrites := flags.Bool("rehearse-writes", false, "rehearse supported write-contract SQL in a rolled-back test DB transaction")
	commitMissStatus := flags.Bool("commit-miss-status", false, "commit safe hashed-fixnames miss-status updates in an explicitly guarded native test DB")
	commitLaneWrites := flags.Bool("commit-lane-writes", false, "commit supported native lane writes in an explicitly guarded native test DB")
	searchDocumentParity := flags.Bool("search-document-parity", false, "include read-only release search document fingerprints for pending native search outbox rows")
	searchDocumentLimit := flags.Int("search-document-limit", 100, "read-only search document parity max pending outbox rows")
	requireReplacementReady := flags.Bool("require-replacement-ready", false, "fail unless the plan is ready for native replacement")
	resolvedWriteContractPath := flags.String("resolved-write-contract", "", "optional PHP-resolved write-contract oracle JSON for rollback-only write rehearsal")
	redisAddr := flags.String("redis-addr", "", "Redis address used to acquire the exported worker lock for committed test writes")
	redisAddrEnv := flags.Bool("redis-addr-env", false, "read Redis address from NNTMUX_NATIVE_REDIS_ADDR")
	lockOwner := flags.String("lock-owner", "", "owner token used when acquiring the exported worker lock for committed test writes")
	lockOwnerEnv := flags.Bool("lock-owner-env", false, "read worker lock owner from NNTMUX_NATIVE_LOCK_OWNER")
	lockMode := flags.String("lock-mode", lockModeAcquire, "worker lock mode: acquire or held")
	artisanBinary := flags.String("artisan-binary", "php", "binary used to execute Artisan leaf commands for --run-lane")
	artisanScript := flags.String("artisan-script", "artisan", "Artisan script path used for --run-lane")
	allowDeferredPostAdditional := flags.Bool("allow-deferred-post-additional", false, "allow post-additional --run-lane to defer embedded metadata-refresh and hashed-fixnames commands")
	var artisanEnvironment stringListFlag
	flags.Var(&artisanEnvironment, "artisan-env", "additional KEY=VALUE environment entry passed to Artisan leaf commands; may be repeated")
	laneMaxProcesses := flags.Int("lane-max-processes", 0, "max concurrent Artisan leaf commands for --run-lane; defaults to the planned lane's worker count")
	binariesMaxMessages := flags.Int("binaries-max-messages", 20000, "safe binaries dry-run max messages per range")
	binariesMaxHeaders := flags.Int("binaries-max-headers", 1000000, "safe binaries dry-run max headers per group")
	backfillQty := flags.Int("backfill-qty", 75000, "safe backfill dry-run total articles per thread window")
	backfillMaxMessages := flags.Int("backfill-max-messages", 20000, "safe backfill dry-run max messages per range")
	backfillThreads := flags.Int("backfill-threads", 1, "safe backfill dry-run worker thread count")
	backfillGroups := flags.Int("backfill-groups", 1, "safe backfill dry-run max groups")
	backfillDays := flags.Int("backfill-days", 1, "safe backfill day mode: 1 uses group targets, 2 uses --backfill-safe-date, other values use current cursors")
	backfillSafeDate := flags.String("backfill-safe-date", "", "safe backfill date for --backfill-days=2 in YYYY-MM-DD format")
	backfillMinArticles := flags.Int("backfill-min-articles", 100, "safe backfill minimum provider-floor gap")
	nntpProbe := flags.Bool("nntp-probe", false, "probe planned binaries/backfill groups against the configured NNTP server without fetching headers")
	nntpOverviewSample := flags.Int("nntp-overview-sample", 0, "fetch up to N overview rows per planned binaries/backfill range for dry-run, rehearsal, or guarded commit")
	ircSamplePath := flags.String("irc-sample", "", "optional file of raw IRC PRIVMSG or PRE messages to parse during irc dry-run")

	if err := flags.Parse(args); err != nil {
		return 2
	}

	if *output != "text" && *output != "json" {
		fmt.Fprintf(stderr, "unsupported --output %q; expected text or json\n", *output)
		return 2
	}
	if *lockMode != lockModeAcquire && *lockMode != lockModeHeld {
		fmt.Fprintf(stderr, "unsupported --lock-mode %q; expected acquire or held\n", *lockMode)
		return 2
	}

	if *planPath == "" {
		fmt.Fprintln(stderr, "--plan is required")
		return 2
	}
	var parsedBackfillSafeDate time.Time
	if *backfillSafeDate != "" {
		date, err := time.Parse("2006-01-02", *backfillSafeDate)
		if err != nil {
			fmt.Fprintf(stderr, "parse --backfill-safe-date: %v\n", err)
			return 2
		}
		parsedBackfillSafeDate = date
	}

	if !*dryRun && !*commitMissStatus && !*runLane && !*commitLaneWrites {
		fmt.Fprintln(stderr, "only --dry-run, --run-lane, --commit-lane-writes, or --commit-miss-status is supported in this native worker slice")
		return 2
	}
	if *runLane && *dryRun {
		fmt.Fprintln(stderr, "--run-lane cannot be combined with --dry-run")
		return 2
	}
	if *ircSamplePath != "" && !*dryRun && !*commitLaneWrites {
		fmt.Fprintln(stderr, "--irc-sample requires --dry-run or --commit-lane-writes")
		return 2
	}
	if *runLane && (*commitMissStatus || *commitLaneWrites) {
		fmt.Fprintln(stderr, "--run-lane cannot be combined with committed write modes")
		return 2
	}
	if *commitLaneWrites && (*dryRun || *commitMissStatus || *rehearseWrites) {
		fmt.Fprintln(stderr, "--commit-lane-writes cannot be combined with --dry-run, --rehearse-writes, or --commit-miss-status")
		return 2
	}
	if *mysqlDSNEnv {
		if *mysqlDSN != "" {
			fmt.Fprintln(stderr, "--mysql-dsn-env cannot be combined with --mysql-dsn")
			return 2
		}

		*mysqlDSN = os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
		if *mysqlDSN == "" {
			fmt.Fprintln(stderr, "--mysql-dsn-env requires NNTMUX_NATIVE_MYSQL_DSN")
			return 2
		}
	}
	if *redisAddrEnv {
		if *redisAddr != "" {
			fmt.Fprintln(stderr, "--redis-addr-env cannot be combined with --redis-addr")
			return 2
		}

		*redisAddr = os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
		if *redisAddr == "" {
			fmt.Fprintln(stderr, "--redis-addr-env requires NNTMUX_NATIVE_REDIS_ADDR")
			return 2
		}
	}
	if *lockOwnerEnv {
		if *lockOwner != "" {
			fmt.Fprintln(stderr, "--lock-owner-env cannot be combined with --lock-owner")
			return 2
		}

		*lockOwner = os.Getenv("NNTMUX_NATIVE_LOCK_OWNER")
		if *lockOwner == "" {
			fmt.Fprintln(stderr, "--lock-owner-env requires NNTMUX_NATIVE_LOCK_OWNER")
			return 2
		}
	}
	if *commitMissStatus || *commitLaneWrites {
		if *mysqlDSN == "" {
			*mysqlDSN = os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
		}
		if *redisAddr == "" {
			*redisAddr = os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
		}
		if *lockOwner == "" {
			*lockOwner = os.Getenv("NNTMUX_NATIVE_LOCK_OWNER")
		}
	}
	if *dryRun && *commitMissStatus {
		fmt.Fprintln(stderr, "--commit-miss-status cannot be combined with --dry-run")
		return 2
	}
	if *rehearseWrites && *mysqlDSN == "" {
		fmt.Fprintln(stderr, "--rehearse-writes requires --mysql-dsn")
		return 2
	}
	if *commitMissStatus && *mysqlDSN == "" {
		fmt.Fprintln(stderr, "--commit-miss-status requires --mysql-dsn")
		return 2
	}
	if *commitLaneWrites && *mysqlDSN == "" {
		fmt.Fprintln(stderr, "--commit-lane-writes requires --mysql-dsn")
		return 2
	}
	if *commitMissStatus && *rehearseWrites {
		fmt.Fprintln(stderr, "--commit-miss-status cannot be combined with --rehearse-writes")
		return 2
	}
	if *commitMissStatus && *redisAddr == "" {
		fmt.Fprintln(stderr, "--commit-miss-status requires --redis-addr")
		return 2
	}
	if *commitLaneWrites && *redisAddr == "" {
		fmt.Fprintln(stderr, "--commit-lane-writes requires --redis-addr")
		return 2
	}
	if *commitMissStatus && *lockOwner == "" {
		fmt.Fprintln(stderr, "--commit-miss-status requires --lock-owner or NNTMUX_NATIVE_LOCK_OWNER")
		return 2
	}
	if *commitLaneWrites && *lockOwner == "" {
		fmt.Fprintln(stderr, "--commit-lane-writes requires --lock-owner or NNTMUX_NATIVE_LOCK_OWNER")
		return 2
	}
	if *runLane && *lockMode != lockModeHeld {
		fmt.Fprintln(stderr, "--run-lane requires --lock-mode=held")
		return 2
	}
	if *runLane && *redisAddr == "" {
		fmt.Fprintln(stderr, "--run-lane requires --redis-addr or NNTMUX_NATIVE_REDIS_ADDR")
		return 2
	}
	if *runLane && *lockOwner == "" {
		fmt.Fprintln(stderr, "--run-lane requires --lock-owner or NNTMUX_NATIVE_LOCK_OWNER")
		return 2
	}
	if *searchDocumentParity && !*dryRun {
		fmt.Fprintln(stderr, "--search-document-parity requires --dry-run")
		return 2
	}
	if *searchDocumentParity && *mysqlDSN == "" {
		fmt.Fprintln(stderr, "--search-document-parity requires --mysql-dsn")
		return 2
	}
	if *searchDocumentParity && *rehearseWrites {
		fmt.Fprintln(stderr, "--search-document-parity cannot be combined with --rehearse-writes")
		return 2
	}
	if *lockMode == lockModeHeld && !*commitMissStatus && !*runLane && !*commitLaneWrites {
		fmt.Fprintln(stderr, "--lock-mode=held requires --commit-miss-status, --commit-lane-writes, or --run-lane")
		return 2
	}
	if *resolvedWriteContractPath != "" && !*rehearseWrites {
		fmt.Fprintln(stderr, "--resolved-write-contract requires --rehearse-writes")
		return 2
	}
	if *nntpProbe && !*dryRun {
		fmt.Fprintln(stderr, "--nntp-probe requires --dry-run")
		return 2
	}
	if *nntpOverviewSample < 0 {
		fmt.Fprintln(stderr, "--nntp-overview-sample must be non-negative")
		return 2
	}
	if *nntpOverviewSample > 0 && !*dryRun && !*commitLaneWrites {
		fmt.Fprintln(stderr, "--nntp-overview-sample requires --dry-run or --commit-lane-writes")
		return 2
	}

	var planReader io.Reader = stdin
	if *planPath != "-" {
		file, err := os.Open(*planPath)
		if err != nil {
			fmt.Fprintf(stderr, "open native plan: %v\n", err)
			return 1
		}
		defer file.Close()

		planReader = file
	}

	plan, err := worker.DecodePlan(planReader)
	if err != nil {
		fmt.Fprintln(stderr, err)
		return 1
	}

	if err := worker.ValidatePlan(plan); err != nil {
		fmt.Fprintln(stderr, err)
		return 1
	}
	if *runLane {
		if err := validateRunLaneCommandEnvelope(plan, *allowDeferredPostAdditional); err != nil {
			fmt.Fprintln(stderr, err)
			return 2
		}
		if *mysqlDSN == "" && !isCommandOnlyNativeLane(plan.Job.Name) {
			fmt.Fprintln(stderr, "--run-lane requires --mysql-dsn")
			return 2
		}
	}
	var fixNamesPlan fixnames.Plan
	fixNamesReq := plan.Job.Name == "fixnames"
	if fixNamesReq {
		var err error
		fixNamesPlan, err = fixnames.BuildPlan(plan)
		if err != nil {
			fmt.Fprintln(stderr, err)
			return 1
		}
	}
	var ircPlan irc.Plan
	ircReq := plan.Job.Name == "irc"
	if ircReq {
		var err error
		ircPlan, err = irc.BuildPlan(plan)
		if err != nil {
			fmt.Fprintln(stderr, err)
			return 1
		}
	}
	replacementBlockers := replacementReadinessBlockers(plan, fixNamesPlan, ircPlan)
	if *requireReplacementReady {
		if len(replacementBlockers) > 0 {
			fmt.Fprintf(stderr, "%s catalog is not replacement-ready: %s\n", plan.Job.Name, strings.Join(replacementBlockers, "; "))
			return 2
		}
	}
	if *commitMissStatus {
		if plan.Job.Name != "hashed-fixnames" {
			fmt.Fprintln(stderr, "--commit-miss-status requires the hashed-fixnames job")
			return 2
		}
		if !hasHashedFixNamePlannerCommands(plan) {
			fmt.Fprintln(stderr, "--commit-miss-status requires hashed fix-name commands")
			return 2
		}
	}
	regularFixReqs, err := regularFixNameRequests(plan)
	if err != nil {
		fmt.Fprintln(stderr, err)
		return 1
	}
	if *commitLaneWrites {
		if plan.Job.Name != "binaries" && plan.Job.Name != "backfill" && plan.Job.Name != "releases" && plan.Job.Name != "per-group" && plan.Job.Name != "removecrap" && plan.Job.Name != "metadata-refresh" && plan.Job.Name != "fixnames" && plan.Job.Name != "irc" && !isExecutablePostprocessLane(plan.Job.Name) {
			fmt.Fprintln(stderr, "--commit-lane-writes currently supports only the binaries, backfill, releases, per-group, removecrap, metadata-refresh, fixnames, irc, and postprocess jobs")
			return 2
		}
		if plan.Job.Name == "fixnames" && len(regularFixReqs) == 0 {
			fmt.Fprintln(stderr, "--commit-lane-writes requires native-supported fixnames commands")
			return 2
		}
		if plan.Job.Name == "binaries" && !hasSafeBinariesCommands(plan) {
			fmt.Fprintln(stderr, "--commit-lane-writes requires safe binaries commands")
			return 2
		}
		if plan.Job.Name == "backfill" && !hasSafeBackfillCommands(plan) {
			fmt.Fprintln(stderr, "--commit-lane-writes requires safe backfill commands")
			return 2
		}
		if plan.Job.Name == "releases" {
			requested, err := releasesRequest(plan)
			if err != nil {
				fmt.Fprintln(stderr, err)
				return 2
			}
			if !requested {
				fmt.Fprintln(stderr, "--commit-lane-writes requires releases commands")
				return 2
			}
		}
		if plan.Job.Name == "irc" && *ircSamplePath == "" {
			fmt.Fprintln(stderr, "--commit-lane-writes requires --irc-sample for the irc job")
			return 2
		}
	}
	if *searchDocumentParity && plan.Job.Name != "hashed-fixnames" {
		fmt.Fprintln(stderr, "--search-document-parity requires the hashed-fixnames job")
		return 2
	}
	if *searchDocumentParity && !hasHashedFixNamePlannerCommands(plan) {
		fmt.Fprintln(stderr, "--search-document-parity requires hashed fix-name commands")
		return 2
	}
	if *nntpProbe && plan.Job.Name != "binaries" && plan.Job.Name != "backfill" {
		fmt.Fprintln(stderr, "--nntp-probe currently supports only the binaries and backfill jobs")
		return 2
	}
	if *nntpOverviewSample > 0 && plan.Job.Name != "binaries" && plan.Job.Name != "backfill" {
		fmt.Fprintln(stderr, "--nntp-overview-sample currently supports only the binaries and backfill jobs")
		return 2
	}
	if *ircSamplePath != "" && plan.Job.Name != "irc" {
		fmt.Fprintln(stderr, "--irc-sample currently supports only the irc job")
		return 2
	}
	if *rehearseWrites && plan.Job.Name == "irc" && *ircSamplePath == "" {
		fmt.Fprintln(stderr, "--rehearse-writes requires --irc-sample for the irc job")
		return 2
	}
	if *commitLaneWrites && (plan.Job.Name == "binaries" || plan.Job.Name == "backfill") && *nntpOverviewSample == 0 {
		fmt.Fprintf(stderr, "--commit-lane-writes for %s requires --nntp-overview-sample\n", plan.Job.Name)
		return 2
	}
	removeCrapReqs, err := removeCrapRequests(plan)
	if err != nil {
		fmt.Fprintln(stderr, err)
		return 1
	}
	if *commitLaneWrites && plan.Job.Name == "removecrap" && len(removeCrapReqs) == 0 {
		fmt.Fprintln(stderr, "--commit-lane-writes requires removecrap commands")
		return 2
	}
	if *commitLaneWrites && plan.Job.Name == "metadata-refresh" && !hasMetadataRefreshCommand(plan) {
		fmt.Fprintln(stderr, "--commit-lane-writes requires metadata-refresh commands")
		return 2
	}
	postprocessReqs, err := postprocessRequests(plan)
	if err != nil {
		fmt.Fprintln(stderr, err)
		return 1
	}
	if *commitLaneWrites && isExecutablePostprocessLane(plan.Job.Name) && len(postprocessReqs) == 0 {
		fmt.Fprintln(stderr, "--commit-lane-writes requires postprocess commands")
		return 2
	}
	if *commitLaneWrites && plan.Job.Name == "post-additional" && postAdditionalHasDeferredCommands(plan) && !*allowDeferredPostAdditional {
		fmt.Fprintln(stderr, "--allow-deferred-post-additional is required for post-additional lane write commit")
		return 2
	}
	releasesReq, err := releasesRequest(plan)
	if err != nil {
		fmt.Fprintln(stderr, err)
		return 1
	}
	perGroupReq, err := perGroupRequest(plan)
	if err != nil {
		fmt.Fprintln(stderr, err)
		return 1
	}
	if *commitLaneWrites && plan.Job.Name == "per-group" && !perGroupReq {
		fmt.Fprintln(stderr, "--commit-lane-writes requires per-group commands")
		return 2
	}
	if *mysqlDSN != "" && hasSafeBackfillCommands(plan) && *backfillDays == 2 && parsedBackfillSafeDate.IsZero() {
		fmt.Fprintln(stderr, "--backfill-days=2 requires --backfill-safe-date")
		return 2
	}

	var ircCandidates []irc.Candidate
	report := newWorkerReport(plan, *dryRun, replacementBlockers)
	if fixNamesReq {
		report.Fixnames = &fixNamesPlan
	}
	if ircReq {
		if *ircSamplePath != "" {
			file, err := os.Open(*ircSamplePath)
			if err != nil {
				fmt.Fprintf(stderr, "open irc sample: %v\n", err)
				return 1
			}
			sample, candidates, err := irc.ParseSample(file, irc.ParseOptions{})
			closeErr := file.Close()
			if err != nil {
				fmt.Fprintf(stderr, "parse irc sample: %v\n", err)
				return 1
			}
			if closeErr != nil {
				fmt.Fprintf(stderr, "close irc sample: %v\n", closeErr)
				return 1
			}
			ircPlan.Sample = &sample
			ircCandidates = candidates
		}
		report.Irc = &ircPlan
	}
	if plan.Job.Name == "hashed-fixnames" && hasAnyHashedFixNamePlannerCommands(plan) {
		report.HashedFixnames = newHashedFixnamesReport(plan)
	}
	var text bytes.Buffer
	if *output == "text" {
		fmt.Fprint(&text, worker.DryRunSummary(plan))
		if fixNamesReq {
			fmt.Fprint(&text, fixnames.DryRunSummary(fixNamesPlan))
		}
		if ircReq {
			fmt.Fprint(&text, irc.DryRunSummary(ircPlan))
		}
	}
	var laneCommands []laneexec.CommandSpec
	var laneMaxProcessesResolved int
	if *runLane && plan.Job.Name == "fixnames" {
		laneCommands, err = fixNamesLaneCommands(plan)
		if err != nil {
			fmt.Fprintf(stderr, "build fixnames lane commands: %v\n", err)
			return 1
		}
		laneMaxProcessesResolved = *laneMaxProcesses
		if laneMaxProcessesResolved < 1 {
			laneMaxProcessesResolved = 1
		}
	}
	if *runLane && plan.Job.Name == "metadata-refresh" {
		laneCommands, err = metadataRefreshLaneCommands(plan)
		if err != nil {
			fmt.Fprintf(stderr, "build metadata-refresh lane commands: %v\n", err)
			return 1
		}
		laneMaxProcessesResolved = *laneMaxProcesses
		if laneMaxProcessesResolved < 1 {
			laneMaxProcessesResolved = 1
		}
	}
	if *runLane && plan.Job.Name == "hashed-fixnames" {
		laneCommands, err = hashedFixNamesLaneCommands(plan)
		if err != nil {
			fmt.Fprintf(stderr, "build hashed-fixnames lane commands: %v\n", err)
			return 1
		}
		laneMaxProcessesResolved = *laneMaxProcesses
		if laneMaxProcessesResolved < 1 {
			laneMaxProcessesResolved = 1
		}
	}
	if *runLane && plan.Job.Name == "irc" {
		laneMaxProcessesResolved = *laneMaxProcesses
		if laneMaxProcessesResolved < 1 {
			laneMaxProcessesResolved = 1
		}
	}
	if *mysqlDSN != "" && !(*runLane && (isCommandOnlyNativeLane(plan.Job.Name) || plan.Job.Name == "irc")) {
		if plan.Job.Name != "metadata-refresh" && len(regularFixReqs) == 0 && !hasHashedFixNamePlannerCommands(plan) && !hasSafeBinariesCommands(plan) && !hasSafeBackfillCommands(plan) && len(removeCrapReqs) == 0 && len(postprocessReqs) == 0 && !releasesReq && !perGroupReq && len(ircCandidates) == 0 && !*searchDocumentParity {
			fmt.Fprintf(stderr, "--mysql-dsn has no supported dry-run planner for job %q\n", plan.Job.Name)
			return 1
		}
		if *rehearseWrites && plan.Job.Name != "metadata-refresh" && !hasHashedFixNamePlannerCommands(plan) && !hasSafeBinariesCommands(plan) && !hasSafeBackfillCommands(plan) && len(removeCrapReqs) == 0 && len(postprocessReqs) == 0 && !releasesReq && !perGroupReq && len(ircCandidates) == 0 {
			fmt.Fprintln(stderr, "--rehearse-writes requires metadata-refresh, hashed fix-name, binaries, backfill, removecrap, postprocess, releases, per-group, or irc sample commands")
			return 2
		}

		db, err := sql.Open("mysql", *mysqlDSN)
		if err != nil {
			fmt.Fprintf(stderr, "open mysql: %v\n", err)
			return 1
		}
		defer db.Close()

		if *rehearseWrites {
			if err := safety.ValidateNativeTestMySQL(context.Background(), db, *mysqlDSN); err != nil {
				fmt.Fprintf(stderr, "refuse write rehearsal: %v\n", err)
				return 1
			}
		}
		if *commitMissStatus {
			if err := safety.ValidateNativeTestMySQLCommit(context.Background(), db, *mysqlDSN); err != nil {
				fmt.Fprintf(stderr, "refuse miss-status commit: %v\n", err)
				return 1
			}
		}
		if *commitLaneWrites {
			if safety.AllowsProductionCommit(plan.Job.Name) {
				if err := safety.ValidateProductionMySQL(context.Background(), db, *mysqlDSN); err != nil {
					fmt.Fprintf(stderr, "refuse production lane write commit: %v\n", err)
					return 1
				}
			} else {
				if err := safety.ValidateNativeTestMySQLCommit(context.Background(), db, *mysqlDSN); err != nil {
					fmt.Fprintf(stderr, "refuse lane write commit: %v\n", err)
					return 1
				}
			}
		}

		if ircReq && len(ircCandidates) > 0 {
			if *rehearseWrites {
				rehearsal, err := irc.RehearsePredbWrites(context.Background(), db, ircCandidates)
				if err != nil {
					fmt.Fprintf(stderr, "rehearse irc predb writes: %v\n", err)
					return 1
				}
				report.IrcWriteRehearsal = &rehearsal
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("irc", rehearsal.Candidates, rehearsal.RolledBack, rehearsal.WritesCommitted))
				}
			}
			if *commitLaneWrites && plan.Job.Name == "irc" {
				commit, err := commitIRCPredbWrites(context.Background(), plan, db, ircCandidates, *redisAddr, *lockOwner, *lockMode)
				if err != nil {
					fmt.Fprintf(stderr, "commit irc predb writes: %v\n", err)
					return 1
				}
				report.IrcWriteCommit = &commit
				report.NativeWorker.Writes = commit.WritesCommitted
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("irc", commit.Candidates, commit.RolledBack, commit.WritesCommitted))
				}
			}
		}

		if plan.Job.Name == "metadata-refresh" {
			refreshPlan, err := metadata.BuildRefreshDryRunPlan(context.Background(), db, metadataRefreshLimit(plan))
			if err != nil {
				fmt.Fprintf(stderr, "build metadata-refresh mysql dry-run: %v\n", err)
				return 1
			}
			previewQueries := append([]string(nil), refreshPlan.SearchQueries...)

			report.MetadataRefresh = &metadataRefreshReport{
				SrrdbTitleCandidates: len(refreshPlan.SrrdbTitleCandidates),
				ArchiveCRCCandidates: len(refreshPlan.ArchiveCRCCandidates),
				SearchQueries:        len(refreshPlan.SearchQueries),
				Writes:               0,
			}
			if *output == "text" {
				fmt.Fprint(&text, metadata.DryRunSummary(refreshPlan))
			}
			if *rehearseWrites || *commitLaneWrites {
				var fetch metadata.SrrdbFetchSummary
				if metadataRefreshIncludesSrrdb(plan) {
					refreshPlan, fetch, err = metadata.EnrichSrrdbTitleDetails(
						context.Background(),
						refreshPlan,
						metadata.SrrdbClientFromEnv(),
						time.Duration(metadataRefreshSleepMS(plan))*time.Millisecond,
					)
					if err != nil {
						fmt.Fprintf(stderr, "fetch metadata-refresh srrdb details: %v\n", err)
						return 1
					}
					var archiveFetch metadata.SrrdbFetchSummary
					refreshPlan, archiveFetch, err = metadata.EnrichSrrdbArchiveCRCSearch(
						context.Background(),
						refreshPlan,
						metadata.SrrdbClientFromEnv(),
						time.Duration(metadataRefreshSleepMS(plan))*time.Millisecond,
					)
					if err != nil {
						fmt.Fprintf(stderr, "fetch metadata-refresh srrdb archive crc search: %v\n", err)
						return 1
					}
					fetch.Merge(archiveFetch)
				} else {
					fetch = metadata.SrrdbFetchSummary{
						Candidates:        len(refreshPlan.SrrdbTitleCandidates),
						ArchiveCandidates: len(refreshPlan.ArchiveCRCCandidates),
						Skipped:           true,
					}
					refreshPlan.SrrdbTitleCandidates = nil
					refreshPlan.ArchiveCRCCandidates = nil
				}
				report.MetadataRefreshSrrdbFetch = &fetch
				if *output == "text" {
					fmt.Fprint(&text, metadata.SrrdbFetchSummaryText(fetch))
				}
				var providerFetch metadata.SearchProviderFetchSummary
				refreshPlan, providerFetch, err = metadata.EnrichSearchProviderHits(
					context.Background(),
					refreshPlan,
					metadata.SearchProviderClientFromEnv(),
					metadataRefreshSources(plan),
					metadataRefreshLimit(plan),
					time.Duration(metadataRefreshSleepMS(plan))*time.Millisecond,
				)
				if err != nil {
					fmt.Fprintf(stderr, "fetch metadata-refresh search providers: %v\n", err)
					return 1
				}
				report.MetadataRefreshSearchProviderFetch = &providerFetch
				if *output == "text" {
					fmt.Fprint(&text, metadata.SearchProviderFetchSummaryText(providerFetch))
				}
				previewFetch, err := metadata.FetchPreviewSources(
					context.Background(),
					previewQueries,
					metadata.PreviewSourceClientFromEnv(),
					metadataRefreshSources(plan),
					metadataRefreshLimit(plan),
					time.Duration(metadataRefreshSleepMS(plan))*time.Millisecond,
				)
				if err != nil {
					fmt.Fprintf(stderr, "fetch metadata-refresh preview sources: %v\n", err)
					return 1
				}
				report.MetadataRefreshPreviewSourceFetch = &previewFetch
				if *output == "text" {
					fmt.Fprint(&text, metadata.PreviewSourceFetchSummaryText(previewFetch))
				}
			}
			if *rehearseWrites {
				rehearsal, err := metadata.RehearseMetadataRefreshWrites(context.Background(), db, refreshPlan)
				if err != nil {
					fmt.Fprintf(stderr, "rehearse metadata-refresh writes: %v\n", err)
					return 1
				}
				report.MetadataRefreshWriteRehearsal = &rehearsal
				if *output == "text" {
					totalCandidates := rehearsal.SrrdbTitleCandidates + rehearsal.ArchiveCRCCandidates + rehearsal.SearchQueries
					fmt.Fprint(&text, writeRehearsalSummary("metadata-refresh", totalCandidates, rehearsal.RolledBack, rehearsal.WritesCommitted))
				}
			}
			if *commitLaneWrites {
				commit, err := commitMetadataRefreshWrites(context.Background(), plan, db, refreshPlan, *redisAddr, *lockOwner, *lockMode)
				if err != nil {
					fmt.Fprintf(stderr, "commit metadata-refresh writes: %v\n", err)
					return 1
				}
				report.MetadataRefreshWriteCommit = &commit
				report.NativeWorker.Writes = commit.WritesCommitted
				if *output == "text" {
					totalCandidates := commit.SrrdbTitleCandidates + commit.ArchiveCRCCandidates + commit.SearchQueries
					fmt.Fprint(&text, writeRehearsalSummary("metadata-refresh", totalCandidates, commit.RolledBack, commit.WritesCommitted))
				}
			}
		}

		if len(regularFixReqs) > 0 {
			ctx := context.Background()
			regularFixPlan, err := namefix.BuildRegularFixDryRunPlan(ctx, db, regularFixReqs)
			if err != nil {
				fmt.Fprintf(stderr, "build fixnames mysql dry-run: %v\n", err)
				return 1
			}

			fixNamesPlan.CRCMutations = len(regularFixPlan.CRCMutations)
			fixNamesPlan.CRCStatusOnly = len(regularFixPlan.CRCStatusOnly)
			fixNamesPlan.ParHashMutations = len(regularFixPlan.ParHashMutations)
			fixNamesPlan.ParHashStatusOnly = len(regularFixPlan.ParHashStatusOnly)
			if report.Fixnames != nil {
				*report.Fixnames = fixNamesPlan
			}
			if *output == "text" {
				fmt.Fprint(&text, namefix.RegularFixDryRunSummary(regularFixPlan))
			}
			if *commitLaneWrites && plan.Job.Name == "fixnames" {
				writeContract, err := namefix.BuildRegularFixWriteContract(ctx, db, regularFixPlan, regularFixReqs, true)
				if err != nil {
					fmt.Fprintf(stderr, "build fixnames write contract: %v\n", err)
					return 1
				}
				commit, err := commitRegularFixMissStatus(ctx, plan, db, writeContract, *redisAddr, *lockOwner, *lockMode)
				if err != nil {
					fmt.Fprintf(stderr, "commit fixnames miss status: %v\n", err)
					return 1
				}
				report.FixnamesWriteCommit = &commit
				report.NativeWorker.Writes = commit.WritesCommitted
				if *output == "text" {
					fmt.Fprint(&text, namefix.RegularFixMissStatusCommitSummary(commit))
				}
			}
		}

		if hasSafeBinariesCommands(plan) {
			binariesPlan, err := binaries.BuildSafeBinariesDryRunPlan(context.Background(), db, *binariesMaxMessages, *binariesMaxHeaders)
			if err != nil {
				fmt.Fprintf(stderr, "build binaries mysql dry-run: %v\n", err)
				return 1
			}

			report.Binaries = &binariesReport{
				Groups:        binariesPlan.Groups,
				QueueEntries:  binariesPlan.QueueEntries,
				HeaderUpdates: binariesPlan.HeaderUpdates,
				PartRepair:    binariesPlan.PartRepair,
				Ranges:        binariesPlan.Ranges,
				Writes:        binariesPlan.Writes,
			}
			if *output == "text" {
				fmt.Fprint(&text, binaries.DryRunSummary(binariesPlan))
			}
			if *nntpProbe && plan.Job.Name == "binaries" {
				probe, err := nntp.ProbeGroups(context.Background(), nntp.ConfigFromEnv(), binariesProbeGroups(binariesPlan.Queues))
				if err != nil {
					fmt.Fprintf(stderr, "probe binaries nntp groups: %v\n", err)
					return 1
				}
				report.NNTPProbe = &probe
				if *output == "text" {
					fmt.Fprint(&text, nntpProbeSummary("binaries", probe))
				}
			}
			if *nntpOverviewSample > 0 && plan.Job.Name == "binaries" {
				overview, err := nntp.SampleOverview(context.Background(), nntp.ConfigFromEnv(), binariesOverviewRanges(binariesPlan.Queues), *nntpOverviewSample)
				if err != nil {
					fmt.Fprintf(stderr, "sample binaries nntp overview: %v\n", err)
					return 1
				}
				report.NNTPOverviewSample = &overview
				if *output == "text" {
					fmt.Fprint(&text, nntpOverviewSampleSummary("binaries", overview))
				}
			}
			if *runLane && plan.Job.Name == "binaries" {
				laneCommands, err = parseLegacyLaneCommands(binariesQueueCommands(binariesPlan.Queues))
				if err != nil {
					fmt.Fprintf(stderr, "build binaries lane commands: %v\n", err)
					return 1
				}
				laneMaxProcessesResolved = *laneMaxProcesses
			}
			if *rehearseWrites && plan.Job.Name == "binaries" {
				var rehearsal binaries.WriteRehearsalResult
				var err error
				if report.NNTPOverviewSample != nil {
					rehearsal, err = binaries.RehearseOverviewSampleWrites(context.Background(), db, *report.NNTPOverviewSample)
				} else {
					rehearsal, err = binaries.RehearseSafeBinariesWrites(context.Background(), db, binariesPlan)
				}
				if err != nil {
					fmt.Fprintf(stderr, "rehearse binaries writes: %v\n", err)
					return 1
				}
				report.BinariesWriteRehearsal = &rehearsal
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("binaries", rehearsal.QueueEntries, rehearsal.RolledBack, rehearsal.WritesCommitted))
				}
			}
			if *commitLaneWrites && plan.Job.Name == "binaries" {
				commit, err := commitBinariesWrites(context.Background(), plan, db, binariesPlan, report.NNTPOverviewSample, *redisAddr, *lockOwner, *lockMode)
				if err != nil {
					fmt.Fprintf(stderr, "commit binaries writes: %v\n", err)
					return 1
				}
				report.BinariesWriteCommit = &commit
				report.NativeWorker.Writes = commit.WritesCommitted
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("binaries", commit.QueueEntries, commit.RolledBack, commit.WritesCommitted))
				}
			}
		}

		if hasSafeBackfillCommands(plan) {
			backfillPlan, err := backfill.BuildSafeBackfillDryRunPlan(context.Background(), db, backfill.Options{
				BackfillQty:      *backfillQty,
				MaxMessages:      *backfillMaxMessages,
				Threads:          *backfillThreads,
				BackfillGroups:   *backfillGroups,
				BackfillDays:     *backfillDays,
				SafeBackfillDate: parsedBackfillSafeDate,
				MinimumSafeRange: *backfillMinArticles,
			})
			if err != nil {
				fmt.Fprintf(stderr, "build backfill mysql dry-run: %v\n", err)
				return 1
			}

			report.Backfill = &backfillReport{
				Groups:           backfillPlan.Groups,
				QueueEntries:     backfillPlan.QueueEntries,
				Ranges:           backfillPlan.Ranges,
				SkippedInvalid:   backfillPlan.SkippedInvalid,
				SkippedNoWork:    backfillPlan.SkippedNoWork,
				SkippedNearFloor: backfillPlan.SkippedNearFloor,
				Writes:           backfillPlan.Writes,
			}
			if *output == "text" {
				fmt.Fprint(&text, backfill.DryRunSummary(backfillPlan))
			}
			if *nntpProbe && plan.Job.Name == "backfill" {
				probe, err := nntp.ProbeGroups(context.Background(), nntp.ConfigFromEnv(), backfillProbeGroups(backfillPlan.Queues))
				if err != nil {
					fmt.Fprintf(stderr, "probe backfill nntp groups: %v\n", err)
					return 1
				}
				report.NNTPProbe = &probe
				if *output == "text" {
					fmt.Fprint(&text, nntpProbeSummary("backfill", probe))
				}
			}
			if *nntpOverviewSample > 0 && plan.Job.Name == "backfill" {
				overview, err := nntp.SampleOverview(context.Background(), nntp.ConfigFromEnv(), backfillOverviewRanges(backfillPlan.Queues), *nntpOverviewSample)
				if err != nil {
					fmt.Fprintf(stderr, "sample backfill nntp overview: %v\n", err)
					return 1
				}
				report.NNTPOverviewSample = &overview
				if *output == "text" {
					fmt.Fprint(&text, nntpOverviewSampleSummary("backfill", overview))
				}
			}
			if *runLane && plan.Job.Name == "backfill" {
				laneCommands, err = parseLegacyLaneCommands(backfillQueueCommands(backfillPlan.Queues))
				if err != nil {
					fmt.Fprintf(stderr, "build backfill lane commands: %v\n", err)
					return 1
				}
				laneMaxProcessesResolved = *laneMaxProcesses
				if laneMaxProcessesResolved < 1 {
					laneMaxProcessesResolved = *backfillThreads
				}
			}
			if *rehearseWrites && plan.Job.Name == "backfill" {
				var rehearsal backfill.WriteRehearsalResult
				var err error
				if report.NNTPOverviewSample != nil {
					rehearsal, err = backfill.RehearseOverviewSampleWrites(context.Background(), db, *report.NNTPOverviewSample)
				} else {
					rehearsal, err = backfill.RehearseSafeBackfillWrites(context.Background(), db, backfillPlan)
				}
				if err != nil {
					fmt.Fprintf(stderr, "rehearse backfill writes: %v\n", err)
					return 1
				}
				report.BackfillWriteRehearsal = &rehearsal
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("backfill", rehearsal.QueueEntries, rehearsal.RolledBack, rehearsal.WritesCommitted))
				}
			}
			if *commitLaneWrites && plan.Job.Name == "backfill" {
				commit, err := commitBackfillWrites(context.Background(), plan, db, backfillPlan, report.NNTPOverviewSample, *redisAddr, *lockOwner, *lockMode)
				if err != nil {
					fmt.Fprintf(stderr, "commit backfill writes: %v\n", err)
					return 1
				}
				report.BackfillWriteCommit = &commit
				report.NativeWorker.Writes = commit.WritesCommitted
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("backfill", commit.QueueEntries, commit.RolledBack, commit.WritesCommitted))
				}
			}
		}

		if len(removeCrapReqs) > 0 {
			removeCrapPlan, err := removecrap.BuildDryRunPlan(context.Background(), db, removeCrapReqs)
			if err != nil {
				fmt.Fprintf(stderr, "build removecrap mysql dry-run: %v\n", err)
				return 1
			}

			report.RemoveCrap = &removeCrapPlan
			if *output == "text" {
				fmt.Fprint(&text, removecrap.DryRunSummary(removeCrapPlan))
			}
			if *rehearseWrites && plan.Job.Name == "removecrap" {
				rehearsal, err := removecrap.RehearseRemoveCrapWrites(context.Background(), db, removeCrapPlan)
				if err != nil {
					fmt.Fprintf(stderr, "rehearse removecrap writes: %v\n", err)
					return 1
				}
				report.RemoveCrapWriteRehearsal = &rehearsal
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("removecrap", rehearsal.CandidateRows, rehearsal.RolledBack, rehearsal.WritesCommitted))
				}
			}
			if *commitLaneWrites && plan.Job.Name == "removecrap" {
				commit, err := commitRemoveCrapWrites(context.Background(), plan, db, removeCrapPlan, *redisAddr, *lockOwner, *lockMode)
				if err != nil {
					fmt.Fprintf(stderr, "commit removecrap writes: %v\n", err)
					return 1
				}
				report.RemoveCrapWriteCommit = &commit
				report.NativeWorker.Writes = commit.WritesCommitted
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("removecrap", commit.CandidateRows, commit.RolledBack, commit.WritesCommitted))
				}
			}
			if *runLane && plan.Job.Name == "removecrap" {
				laneCommands = removeCrapLaneCommands(removeCrapReqs)
				laneMaxProcessesResolved = *laneMaxProcesses
				if laneMaxProcessesResolved < 1 {
					laneMaxProcessesResolved = 1
				}
			}
		}

		if len(postprocessReqs) > 0 {
			postprocessPlan, err := postprocess.BuildDryRunPlan(context.Background(), db, postprocessReqs)
			if err != nil {
				fmt.Fprintf(stderr, "build postprocess mysql dry-run: %v\n", err)
				return 1
			}

			report.Postprocess = &postprocessPlan
			if *output == "text" {
				fmt.Fprint(&text, postprocess.DryRunSummary(postprocessPlan))
			}
			if *rehearseWrites && isExecutablePostprocessLane(plan.Job.Name) {
				rehearsal, err := postprocess.RehearsePostprocessWrites(context.Background(), db, postprocessPlan)
				if err != nil {
					fmt.Fprintf(stderr, "rehearse postprocess writes: %v\n", err)
					return 1
				}
				report.PostprocessWriteRehearsal = &rehearsal
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("postprocess", rehearsal.BucketEntries, rehearsal.RolledBack, rehearsal.WritesCommitted))
				}
			}
			if *commitLaneWrites && isExecutablePostprocessLane(plan.Job.Name) {
				commit, err := commitPostprocessWrites(context.Background(), plan, db, postprocessPlan, *redisAddr, *lockOwner, *lockMode)
				if err != nil {
					fmt.Fprintf(stderr, "commit postprocess writes: %v\n", err)
					return 1
				}
				report.PostprocessWriteCommit = &commit
				report.NativeWorker.Writes = commit.WritesCommitted
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("postprocess", commit.BucketEntries, commit.RolledBack, commit.WritesCommitted))
				}
			}
			if *runLane && isExecutablePostprocessLane(plan.Job.Name) {
				laneCommands, err = parseLegacyLaneCommands(postprocessQueueCommands(postprocessPlan))
				if err != nil {
					fmt.Fprintf(stderr, "build postprocess lane commands: %v\n", err)
					return 1
				}
				laneMaxProcessesResolved = *laneMaxProcesses
				if laneMaxProcessesResolved < 1 {
					laneMaxProcessesResolved = postprocessMaxProcesses(postprocessPlan)
				}
			}
		}

		if releasesReq {
			releasesPlan, err := releases.BuildDryRunPlan(context.Background(), db)
			if err != nil {
				fmt.Fprintf(stderr, "build releases mysql dry-run: %v\n", err)
				return 1
			}

			report.Releases = &releasesPlan
			if *output == "text" {
				fmt.Fprint(&text, releases.DryRunSummary(releasesPlan))
			}
			if *runLane && plan.Job.Name == "releases" {
				laneCommands, err = parseLegacyLaneCommands(releasesQueueCommands(releasesPlan.Queues))
				if err != nil {
					fmt.Fprintf(stderr, "build releases lane commands: %v\n", err)
					return 1
				}
				laneMaxProcessesResolved = *laneMaxProcesses
				if laneMaxProcessesResolved < 1 {
					laneMaxProcessesResolved = releasesPlan.MaxProcesses
				}
			}
			if *rehearseWrites && plan.Job.Name == "releases" {
				rehearsal, err := releases.RehearseReleaseWrites(context.Background(), db, releasesPlan)
				if err != nil {
					fmt.Fprintf(stderr, "rehearse releases writes: %v\n", err)
					return 1
				}
				report.ReleasesWriteRehearsal = &rehearsal
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("releases", rehearsal.QueueEntries, rehearsal.RolledBack, rehearsal.WritesCommitted))
				}
			}
			if *commitLaneWrites && plan.Job.Name == "releases" {
				commit, err := commitReleaseWrites(context.Background(), plan, db, releasesPlan, *redisAddr, *lockOwner, *lockMode)
				if err != nil {
					fmt.Fprintf(stderr, "commit releases writes: %v\n", err)
					return 1
				}
				report.ReleasesWriteCommit = &commit
				report.NativeWorker.Writes = commit.WritesCommitted
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("releases", commit.QueueEntries, commit.RolledBack, commit.WritesCommitted))
				}
			}
		}

		if perGroupReq {
			perGroupPlan, err := pergroup.BuildDryRunPlan(context.Background(), db)
			if err != nil {
				fmt.Fprintf(stderr, "build per-group mysql dry-run: %v\n", err)
				return 1
			}

			report.PerGroup = &perGroupPlan
			if *output == "text" {
				fmt.Fprint(&text, pergroup.DryRunSummary(perGroupPlan))
			}
			if *runLane && plan.Job.Name == "per-group" {
				laneCommands, err = parseLegacyLaneCommands(perGroupQueueCommands(perGroupPlan.Queues))
				if err != nil {
					fmt.Fprintf(stderr, "build per-group lane commands: %v\n", err)
					return 1
				}
				laneMaxProcessesResolved = *laneMaxProcesses
				if laneMaxProcessesResolved < 1 {
					laneMaxProcessesResolved = perGroupPlan.MaxProcesses
				}
			}
			if *rehearseWrites && plan.Job.Name == "per-group" {
				rehearsal, err := pergroup.RehearsePerGroupWrites(context.Background(), db, perGroupPlan)
				if err != nil {
					fmt.Fprintf(stderr, "rehearse per-group writes: %v\n", err)
					return 1
				}
				report.PerGroupWriteRehearsal = &rehearsal
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("per-group", rehearsal.QueueEntries, rehearsal.RolledBack, rehearsal.WritesCommitted))
				}
			}
			if *commitLaneWrites && plan.Job.Name == "per-group" {
				commit, err := commitPerGroupWrites(context.Background(), plan, db, perGroupPlan, *redisAddr, *lockOwner, *lockMode)
				if err != nil {
					fmt.Fprintf(stderr, "commit per-group writes: %v\n", err)
					return 1
				}
				report.PerGroupWriteCommit = &commit
				report.NativeWorker.Writes = commit.WritesCommitted
				if *output == "text" {
					fmt.Fprint(&text, writeRehearsalSummary("per-group", commit.QueueEntries, commit.RolledBack, commit.WritesCommitted))
				}
			}
		}

		if hasHashedFixNamePlannerCommands(plan) && !*searchDocumentParity {
			ctx := context.Background()
			hashedPlan, err := namefix.BuildHashedFixDryRunPlan(ctx, db, hashedFixCRCLimit(plan))
			if err != nil {
				fmt.Fprintf(stderr, "build hashed-fixnames mysql dry-run: %v\n", err)
				return 1
			}

			hashedReport := newHashedFixnamesReport(plan)
			hashedReport.CRCMutations = len(hashedPlan.CRCMutations)
			hashedReport.CRCStatusOnly = len(hashedPlan.CRCStatusOnly)
			hashedReport.ParHashMutations = len(hashedPlan.ParHashMutations)
			hashedReport.ParHashStatusOnly = len(hashedPlan.ParHashStatusOnly)
			report.HashedFixnames = hashedReport
			if *output == "text" {
				fmt.Fprint(&text, namefix.DryRunSummary(hashedPlan))
			}

			writeContract, err := namefix.BuildHashedFixWriteContract(ctx, db, hashedPlan, namefix.WriteContractOptions{
				MethodOrder: hashedFixMethodOrder(plan),
				SetStatus:   hashedFixSetStatus(plan),
			})
			if err != nil {
				fmt.Fprintf(stderr, "build hashed-fixnames write contract: %v\n", err)
				return 1
			}

			if !*commitMissStatus && !*searchDocumentParity {
				hashedReport.WriteContract = &writeContract
			}
			if *output == "text" && !*commitMissStatus && !*searchDocumentParity {
				fmt.Fprint(&text, namefix.WriteContractSummary(writeContract))
			}

			if *rehearseWrites {
				rehearsal, err := rehearseHashedFixWrites(ctx, db, writeContract, *resolvedWriteContractPath)
				if err != nil {
					fmt.Fprintf(stderr, "rehearse hashed-fixnames write contract: %v\n", err)
					return 1
				}

				hashedReport.WriteRehearsal = &rehearsal
				if *output == "text" {
					fmt.Fprint(&text, namefix.WriteRehearsalSummary(rehearsal))
				}
			}

			if *commitMissStatus {
				commit, err := commitHashedFixMissStatus(ctx, plan, db, writeContract, *redisAddr, *lockOwner, *lockMode)
				if err != nil {
					fmt.Fprintf(stderr, "commit hashed-fixnames miss status: %v\n", err)
					return 1
				}

				hashedReport.WriteCommit = &commit
				hashedReport.Writes = commit.WritesCommitted
				report.NativeWorker.Writes = commit.WritesCommitted
				if *output == "text" {
					fmt.Fprint(&text, namefix.MissStatusCommitSummary(commit))
				}
			}
		}

		if *searchDocumentParity {
			parity, err := searchdoc.BuildPendingOutboxParityReport(context.Background(), db, searchdoc.Options{
				Limit: *searchDocumentLimit,
			})
			if err != nil {
				fmt.Fprintf(stderr, "build search document parity report: %v\n", err)
				return 1
			}

			report.SearchDocuments = &parity
			if *output == "text" {
				fmt.Fprintf(&text, "search document parity: documents=%d writes=%d\n", parity.SearchDocumentsSeen, parity.Writes)
			}
		}
	}

	if *runLane {
		if !supportsNativeLaneExecution(plan.Job.Name) {
			fmt.Fprintf(stderr, "--run-lane is only supported for binaries, backfill, releases, per-group, removecrap, post-tv, post-movies, post-amazon, post-additional, metadata-refresh, fixnames, hashed-fixnames, and irc in this native worker slice; got %q\n", plan.Job.Name)
			return 2
		}
		if err := validateHeldNativeWorkerLock(context.Background(), plan, *redisAddr, *lockOwner); err != nil {
			fmt.Fprintf(stderr, "validate native worker lock: %v\n", err)
			return 1
		}
		if laneMaxProcessesResolved < 1 {
			laneMaxProcessesResolved = 1
		}

		var execution laneexec.Report
		if plan.Job.Name == "irc" {
			db, err := sql.Open("mysql", *mysqlDSN)
			if err != nil {
				fmt.Fprintf(stderr, "open mysql: %v\n", err)
				return 1
			}
			defer db.Close()

			session, commit, runErr := runNativeIRCLane(context.Background(), db)
			report.IrcSession = &session
			report.IrcWriteCommit = &commit
			report.NativeWorker.Writes = commit.WritesCommitted
			execution = nativeIRCLaneReport(runErr)
			if runErr != nil {
				fmt.Fprintf(stderr, "run native irc lane: %v\n", runErr)
			}
		} else {
			execution = laneexec.Run(context.Background(), laneCommands, laneexec.Options{
				ArtisanBinary:      *artisanBinary,
				ArtisanScript:      *artisanScript,
				ArtisanEnvironment: artisanEnvironment,
				MaxProcesses:       laneMaxProcessesResolved,
			})
		}
		report.NativeLane = &execution
		if *output == "text" {
			fmt.Fprint(&text, laneexec.Summary(plan.Job.Name, execution))
		}
		if execution.ExitCode != 0 {
			if *output == "json" {
				_ = json.NewEncoder(stdout).Encode(report)
			} else {
				fmt.Fprint(stdout, text.String())
			}
			return execution.ExitCode
		}
	}

	if *output == "json" {
		if err := json.NewEncoder(stdout).Encode(report); err != nil {
			fmt.Fprintf(stderr, "encode json dry-run report: %v\n", err)
			return 1
		}
	} else {
		fmt.Fprint(stdout, text.String())
	}

	return 0
}

func validateHeldNativeWorkerLock(ctx context.Context, plan worker.Plan, redisAddr string, owner string) error {
	client := newNativeRedisClient(redisAddr)
	defer client.Close()

	redisLock := workerlock.NewRedisLock(client, plan.Lock.RedisKey, owner, time.Duration(plan.Lock.Seconds)*time.Second)
	held, err := redisLock.IsHeldByOwner(ctx)
	if err != nil {
		return err
	}
	if !held {
		return fmt.Errorf("native worker lock is not held by owner")
	}

	return nil
}

func newNativeRedisClient(redisAddr string) *redis.Client {
	return redis.NewClient(&redis.Options{
		Addr: redisAddr,
		MaintNotificationsConfig: &maintnotifications.Config{
			Mode: maintnotifications.ModeDisabled,
		},
	})
}

func commitHashedFixMissStatus(ctx context.Context, plan worker.Plan, db *sql.DB, contract namefix.HashedFixWriteContract, redisAddr string, owner string, lockMode string) (namefix.MissStatusCommitResult, error) {
	return commitNameFixMissStatus(ctx, plan, db, contract, redisAddr, owner, lockMode, namefix.CommitHashedFixMissStatusUpdates)
}

func commitRegularFixMissStatus(ctx context.Context, plan worker.Plan, db *sql.DB, contract namefix.HashedFixWriteContract, redisAddr string, owner string, lockMode string) (namefix.MissStatusCommitResult, error) {
	return commitNameFixMissStatus(ctx, plan, db, contract, redisAddr, owner, lockMode, namefix.CommitRegularFixMissStatusUpdates)
}

func commitNameFixMissStatus(
	ctx context.Context,
	plan worker.Plan,
	db *sql.DB,
	contract namefix.HashedFixWriteContract,
	redisAddr string,
	owner string,
	lockMode string,
	commitFn func(context.Context, *sql.DB, namefix.HashedFixWriteContract) (namefix.MissStatusCommitResult, error),
) (namefix.MissStatusCommitResult, error) {
	client := newNativeRedisClient(redisAddr)
	defer client.Close()

	redisLock := workerlock.NewRedisLock(client, plan.Lock.RedisKey, owner, time.Duration(plan.Lock.Seconds)*time.Second)

	if lockMode == lockModeHeld {
		held, err := redisLock.IsHeldByOwner(ctx)
		if err != nil {
			return namefix.MissStatusCommitResult{}, err
		}
		if !held {
			return namefix.MissStatusCommitResult{}, fmt.Errorf("native worker lock is not held by owner")
		}

		commit, err := commitFn(ctx, db, contract)
		commit.LockAcquired = true
		commit.LockMode = lockModeHeld

		return commit, err
	}

	acquired, err := redisLock.TryAcquire(ctx)
	if err != nil {
		return namefix.MissStatusCommitResult{}, err
	}
	if !acquired {
		return namefix.MissStatusCommitResult{}, fmt.Errorf("native worker lock is already held")
	}

	commit, err := commitFn(ctx, db, contract)
	commit.LockAcquired = true
	commit.LockMode = lockModeAcquire
	if releaseErr := releaseNativeWorkerLock(ctx, redisLock); releaseErr != nil {
		if err != nil {
			return commit, fmt.Errorf("%w; also failed to release native worker lock: %v", err, releaseErr)
		}

		return commit, releaseErr
	}
	if err != nil {
		return commit, err
	}

	return commit, nil
}

func commitMetadataRefreshWrites(ctx context.Context, plan worker.Plan, db *sql.DB, refreshPlan metadata.RefreshDryRunPlan, redisAddr string, owner string, lockMode string) (metadata.WriteRehearsalResult, error) {
	client := newNativeRedisClient(redisAddr)
	defer client.Close()

	redisLock := workerlock.NewRedisLock(client, plan.Lock.RedisKey, owner, time.Duration(plan.Lock.Seconds)*time.Second)

	if lockMode == lockModeHeld {
		held, err := redisLock.IsHeldByOwner(ctx)
		if err != nil {
			return metadata.WriteRehearsalResult{}, err
		}
		if !held {
			return metadata.WriteRehearsalResult{}, fmt.Errorf("native worker lock is not held by owner")
		}

		return metadata.CommitMetadataRefreshWrites(ctx, db, refreshPlan)
	}

	acquired, err := redisLock.TryAcquire(ctx)
	if err != nil {
		return metadata.WriteRehearsalResult{}, err
	}
	if !acquired {
		return metadata.WriteRehearsalResult{}, fmt.Errorf("native worker lock is already held")
	}

	commit, err := metadata.CommitMetadataRefreshWrites(ctx, db, refreshPlan)
	if releaseErr := releaseNativeWorkerLock(ctx, redisLock); releaseErr != nil {
		if err != nil {
			return commit, fmt.Errorf("%w; also failed to release native worker lock: %v", err, releaseErr)
		}

		return commit, releaseErr
	}
	if err != nil {
		return commit, err
	}

	return commit, nil
}

func commitIRCPredbWrites(ctx context.Context, plan worker.Plan, db *sql.DB, candidates []irc.Candidate, redisAddr string, owner string, lockMode string) (irc.WriteRehearsalResult, error) {
	client := newNativeRedisClient(redisAddr)
	defer client.Close()

	redisLock := workerlock.NewRedisLock(client, plan.Lock.RedisKey, owner, time.Duration(plan.Lock.Seconds)*time.Second)

	if lockMode == lockModeHeld {
		held, err := redisLock.IsHeldByOwner(ctx)
		if err != nil {
			return irc.WriteRehearsalResult{}, err
		}
		if !held {
			return irc.WriteRehearsalResult{}, fmt.Errorf("native worker lock is not held by owner")
		}

		return irc.CommitPredbWrites(ctx, db, candidates)
	}

	acquired, err := redisLock.TryAcquire(ctx)
	if err != nil {
		return irc.WriteRehearsalResult{}, err
	}
	if !acquired {
		return irc.WriteRehearsalResult{}, fmt.Errorf("native worker lock is already held")
	}

	commit, err := irc.CommitPredbWrites(ctx, db, candidates)
	if releaseErr := releaseNativeWorkerLock(ctx, redisLock); releaseErr != nil {
		if err != nil {
			return commit, fmt.Errorf("%w; also failed to release native worker lock: %v", err, releaseErr)
		}

		return commit, releaseErr
	}
	if err != nil {
		return commit, err
	}

	return commit, nil
}

func runNativeIRCLane(ctx context.Context, db *sql.DB) (irc.SessionReport, irc.WriteRehearsalResult, error) {
	cfg := irc.RuntimeConfigFromEnv()
	conn, err := irc.DialRuntime(ctx, cfg)
	if err != nil {
		return irc.SessionReport{}, irc.WriteRehearsalResult{}, err
	}
	defer conn.Close()

	session, candidates, err := irc.RunSession(ctx, conn, cfg.SessionConfig(), irc.ParseOptions{})
	if err != nil {
		return session, irc.WriteRehearsalResult{}, err
	}
	if len(candidates) == 0 {
		return session, irc.WriteRehearsalResult{Candidates: 0}, nil
	}

	commit, err := irc.CommitPredbWrites(ctx, db, candidates)
	if err != nil {
		return session, commit, err
	}

	return session, commit, nil
}

func nativeIRCLaneReport(err error) laneexec.Report {
	report := laneexec.Report{
		Commands:     1,
		MaxProcesses: 1,
		Failures:     []string{},
	}
	if err != nil {
		report.Failed = 1
		report.ExitCode = 1
		report.Failures = []string{"native irc session"}

		return report
	}

	report.Succeeded = 1

	return report
}

func commitBinariesWrites(ctx context.Context, plan worker.Plan, db *sql.DB, binariesPlan binaries.SafeBinariesPlan, overviewSample *nntp.OverviewSampleReport, redisAddr string, owner string, lockMode string) (binaries.WriteRehearsalResult, error) {
	client := newNativeRedisClient(redisAddr)
	defer client.Close()

	redisLock := workerlock.NewRedisLock(client, plan.Lock.RedisKey, owner, time.Duration(plan.Lock.Seconds)*time.Second)

	if lockMode == lockModeHeld {
		held, err := redisLock.IsHeldByOwner(ctx)
		if err != nil {
			return binaries.WriteRehearsalResult{}, err
		}
		if !held {
			return binaries.WriteRehearsalResult{}, fmt.Errorf("native worker lock is not held by owner")
		}

		return commitBinariesPlanOrSample(ctx, db, binariesPlan, overviewSample)
	}

	acquired, err := redisLock.TryAcquire(ctx)
	if err != nil {
		return binaries.WriteRehearsalResult{}, err
	}
	if !acquired {
		return binaries.WriteRehearsalResult{}, fmt.Errorf("native worker lock is already held")
	}

	commit, err := commitBinariesPlanOrSample(ctx, db, binariesPlan, overviewSample)
	if releaseErr := releaseNativeWorkerLock(ctx, redisLock); releaseErr != nil {
		if err != nil {
			return commit, fmt.Errorf("%w; also failed to release native worker lock: %v", err, releaseErr)
		}

		return commit, releaseErr
	}
	if err != nil {
		return commit, err
	}

	return commit, nil
}

func commitBinariesPlanOrSample(ctx context.Context, db *sql.DB, binariesPlan binaries.SafeBinariesPlan, overviewSample *nntp.OverviewSampleReport) (binaries.WriteRehearsalResult, error) {
	if overviewSample != nil {
		return binaries.CommitOverviewSampleWrites(ctx, db, *overviewSample)
	}

	return binaries.CommitSafeBinariesWrites(ctx, db, binariesPlan)
}

func commitBackfillWrites(ctx context.Context, plan worker.Plan, db *sql.DB, backfillPlan backfill.SafeBackfillPlan, overviewSample *nntp.OverviewSampleReport, redisAddr string, owner string, lockMode string) (backfill.WriteRehearsalResult, error) {
	client := newNativeRedisClient(redisAddr)
	defer client.Close()

	redisLock := workerlock.NewRedisLock(client, plan.Lock.RedisKey, owner, time.Duration(plan.Lock.Seconds)*time.Second)

	if lockMode == lockModeHeld {
		held, err := redisLock.IsHeldByOwner(ctx)
		if err != nil {
			return backfill.WriteRehearsalResult{}, err
		}
		if !held {
			return backfill.WriteRehearsalResult{}, fmt.Errorf("native worker lock is not held by owner")
		}

		return commitBackfillPlanOrSample(ctx, db, backfillPlan, overviewSample)
	}

	acquired, err := redisLock.TryAcquire(ctx)
	if err != nil {
		return backfill.WriteRehearsalResult{}, err
	}
	if !acquired {
		return backfill.WriteRehearsalResult{}, fmt.Errorf("native worker lock is already held")
	}

	commit, err := commitBackfillPlanOrSample(ctx, db, backfillPlan, overviewSample)
	if releaseErr := releaseNativeWorkerLock(ctx, redisLock); releaseErr != nil {
		if err != nil {
			return commit, fmt.Errorf("%w; also failed to release native worker lock: %v", err, releaseErr)
		}

		return commit, releaseErr
	}
	if err != nil {
		return commit, err
	}

	return commit, nil
}

func commitBackfillPlanOrSample(ctx context.Context, db *sql.DB, backfillPlan backfill.SafeBackfillPlan, overviewSample *nntp.OverviewSampleReport) (backfill.WriteRehearsalResult, error) {
	if overviewSample != nil {
		return backfill.CommitOverviewSampleWrites(ctx, db, *overviewSample)
	}

	return backfill.CommitSafeBackfillWrites(ctx, db, backfillPlan)
}

func commitReleaseWrites(ctx context.Context, plan worker.Plan, db *sql.DB, releasesPlan releases.Plan, redisAddr string, owner string, lockMode string) (releases.WriteRehearsalResult, error) {
	client := newNativeRedisClient(redisAddr)
	defer client.Close()

	redisLock := workerlock.NewRedisLock(client, plan.Lock.RedisKey, owner, time.Duration(plan.Lock.Seconds)*time.Second)

	if lockMode == lockModeHeld {
		held, err := redisLock.IsHeldByOwner(ctx)
		if err != nil {
			return releases.WriteRehearsalResult{}, err
		}
		if !held {
			return releases.WriteRehearsalResult{}, fmt.Errorf("native worker lock is not held by owner")
		}

		return releases.CommitReleaseWrites(ctx, db, releasesPlan)
	}

	acquired, err := redisLock.TryAcquire(ctx)
	if err != nil {
		return releases.WriteRehearsalResult{}, err
	}
	if !acquired {
		return releases.WriteRehearsalResult{}, fmt.Errorf("native worker lock is already held")
	}

	commit, err := releases.CommitReleaseWrites(ctx, db, releasesPlan)
	if releaseErr := releaseNativeWorkerLock(ctx, redisLock); releaseErr != nil {
		if err != nil {
			return commit, fmt.Errorf("%w; also failed to release native worker lock: %v", err, releaseErr)
		}

		return commit, releaseErr
	}
	if err != nil {
		return commit, err
	}

	return commit, nil
}

func commitRemoveCrapWrites(ctx context.Context, plan worker.Plan, db *sql.DB, removeCrapPlan removecrap.Plan, redisAddr string, owner string, lockMode string) (removecrap.WriteRehearsalResult, error) {
	client := newNativeRedisClient(redisAddr)
	defer client.Close()

	redisLock := workerlock.NewRedisLock(client, plan.Lock.RedisKey, owner, time.Duration(plan.Lock.Seconds)*time.Second)

	if lockMode == lockModeHeld {
		held, err := redisLock.IsHeldByOwner(ctx)
		if err != nil {
			return removecrap.WriteRehearsalResult{}, err
		}
		if !held {
			return removecrap.WriteRehearsalResult{}, fmt.Errorf("native worker lock is not held by owner")
		}

		return removecrap.CommitRemoveCrapWrites(ctx, db, removeCrapPlan)
	}

	acquired, err := redisLock.TryAcquire(ctx)
	if err != nil {
		return removecrap.WriteRehearsalResult{}, err
	}
	if !acquired {
		return removecrap.WriteRehearsalResult{}, fmt.Errorf("native worker lock is already held")
	}

	commit, err := removecrap.CommitRemoveCrapWrites(ctx, db, removeCrapPlan)
	if releaseErr := releaseNativeWorkerLock(ctx, redisLock); releaseErr != nil {
		if err != nil {
			return commit, fmt.Errorf("%w; also failed to release native worker lock: %v", err, releaseErr)
		}

		return commit, releaseErr
	}
	if err != nil {
		return commit, err
	}

	return commit, nil
}

func commitPostprocessWrites(ctx context.Context, plan worker.Plan, db *sql.DB, postprocessPlan postprocess.Plan, redisAddr string, owner string, lockMode string) (postprocess.WriteRehearsalResult, error) {
	client := newNativeRedisClient(redisAddr)
	defer client.Close()

	redisLock := workerlock.NewRedisLock(client, plan.Lock.RedisKey, owner, time.Duration(plan.Lock.Seconds)*time.Second)

	if lockMode == lockModeHeld {
		held, err := redisLock.IsHeldByOwner(ctx)
		if err != nil {
			return postprocess.WriteRehearsalResult{}, err
		}
		if !held {
			return postprocess.WriteRehearsalResult{}, fmt.Errorf("native worker lock is not held by owner")
		}

		return postprocess.CommitPostprocessWrites(ctx, db, postprocessPlan)
	}

	acquired, err := redisLock.TryAcquire(ctx)
	if err != nil {
		return postprocess.WriteRehearsalResult{}, err
	}
	if !acquired {
		return postprocess.WriteRehearsalResult{}, fmt.Errorf("native worker lock is already held")
	}

	commit, err := postprocess.CommitPostprocessWrites(ctx, db, postprocessPlan)
	if releaseErr := releaseNativeWorkerLock(ctx, redisLock); releaseErr != nil {
		if err != nil {
			return commit, fmt.Errorf("%w; also failed to release native worker lock: %v", err, releaseErr)
		}

		return commit, releaseErr
	}
	if err != nil {
		return commit, err
	}

	return commit, nil
}

func commitPerGroupWrites(ctx context.Context, plan worker.Plan, db *sql.DB, perGroupPlan pergroup.Plan, redisAddr string, owner string, lockMode string) (pergroup.WriteRehearsalResult, error) {
	client := newNativeRedisClient(redisAddr)
	defer client.Close()

	redisLock := workerlock.NewRedisLock(client, plan.Lock.RedisKey, owner, time.Duration(plan.Lock.Seconds)*time.Second)

	if lockMode == lockModeHeld {
		held, err := redisLock.IsHeldByOwner(ctx)
		if err != nil {
			return pergroup.WriteRehearsalResult{}, err
		}
		if !held {
			return pergroup.WriteRehearsalResult{}, fmt.Errorf("native worker lock is not held by owner")
		}

		return pergroup.CommitPerGroupWrites(ctx, db, perGroupPlan)
	}

	acquired, err := redisLock.TryAcquire(ctx)
	if err != nil {
		return pergroup.WriteRehearsalResult{}, err
	}
	if !acquired {
		return pergroup.WriteRehearsalResult{}, fmt.Errorf("native worker lock is already held")
	}

	commit, err := pergroup.CommitPerGroupWrites(ctx, db, perGroupPlan)
	if releaseErr := releaseNativeWorkerLock(ctx, redisLock); releaseErr != nil {
		if err != nil {
			return commit, fmt.Errorf("%w; also failed to release native worker lock: %v", err, releaseErr)
		}

		return commit, releaseErr
	}
	if err != nil {
		return commit, err
	}

	return commit, nil
}

func releaseNativeWorkerLock(ctx context.Context, redisLock workerlock.RedisLock) error {
	released, err := redisLock.Release(ctx)
	if err != nil {
		return err
	}
	if !released {
		return fmt.Errorf("native worker lock was not released by owner")
	}

	return nil
}

func parseLegacyLaneCommands(commands []string) ([]laneexec.CommandSpec, error) {
	specs := make([]laneexec.CommandSpec, 0, len(commands))
	for _, command := range commands {
		spec, err := laneexec.ParseLegacyCommand(command)
		if err != nil {
			return nil, err
		}
		specs = append(specs, spec)
	}

	return specs, nil
}

func validateRunLaneCommandEnvelope(plan worker.Plan, allowDeferredPostAdditional bool) error {
	switch plan.Job.Name {
	case "binaries":
		return validateSafeRunLaneCommandEnvelope(plan, "binaries")
	case "backfill":
		return validateSafeRunLaneCommandEnvelope(plan, "backfill")
	case "releases":
		requested, err := releasesRequest(plan)
		if err != nil {
			return err
		}
		if !requested {
			return fmt.Errorf("releases lane execution requires multiprocessing:releases command")
		}

		return nil
	case "per-group":
		requested, err := perGroupRequest(plan)
		if err != nil {
			return err
		}
		if !requested {
			return fmt.Errorf("per-group lane execution requires multiprocessing:update-per-group command")
		}

		return nil
	case "post-tv", "post-movies", "post-amazon":
		requests, err := postprocessRequests(plan)
		if err != nil {
			return err
		}
		if len(requests) == 0 {
			return fmt.Errorf("%s lane execution requires multiprocessing:postprocess command", plan.Job.Name)
		}

		return nil
	case "post-additional":
		if postAdditionalHasDeferredCommands(plan) && !allowDeferredPostAdditional {
			return fmt.Errorf("--allow-deferred-post-additional is required for post-additional lane execution")
		}

		requests, err := postprocessRequests(plan)
		if err != nil {
			return err
		}
		if len(requests) == 0 {
			return fmt.Errorf("post-additional lane execution requires multiprocessing:postprocess command")
		}

		return nil
	case "removecrap":
		requests, err := removeCrapRequests(plan)
		if err != nil {
			return err
		}
		if len(requests) == 0 {
			return fmt.Errorf("removecrap lane execution requires releases:remove-crap command")
		}

		return nil
	case "fixnames":
		commands, err := fixNamesLaneCommands(plan)
		if err != nil {
			return err
		}
		if len(commands) == 0 {
			return fmt.Errorf("fixnames lane execution requires releases:fix-names command")
		}

		return nil
	case "metadata-refresh":
		commands, err := metadataRefreshLaneCommands(plan)
		if err != nil {
			return err
		}
		if len(commands) == 0 {
			return fmt.Errorf("metadata-refresh lane execution requires predb:refresh-external-metadata or releases:fix-names command")
		}

		return nil
	case "hashed-fixnames":
		commands, err := hashedFixNamesLaneCommands(plan)
		if err != nil {
			return err
		}
		if len(commands) == 0 {
			return fmt.Errorf("hashed-fixnames lane execution requires releases:fix-names command")
		}

		return nil
	case "irc":
		commands, err := ircLaneCommands(plan)
		if err != nil {
			return err
		}
		if len(commands) == 0 {
			return fmt.Errorf("irc lane execution requires irc:scrape command")
		}

		return nil
	default:
		return fmt.Errorf("--run-lane is only supported for binaries, backfill, releases, per-group, removecrap, post-tv, post-movies, post-amazon, post-additional, metadata-refresh, fixnames, hashed-fixnames, and irc in this native worker slice; got %q", plan.Job.Name)
	}
}

func supportsNativeLaneExecution(job string) bool {
	switch job {
	case "binaries", "backfill", "releases", "per-group", "removecrap", "post-tv", "post-movies", "post-amazon", "post-additional", "metadata-refresh", "fixnames", "hashed-fixnames", "irc":
		return true
	default:
		return false
	}
}

func isCommandOnlyNativeLane(job string) bool {
	switch job {
	case "metadata-refresh", "fixnames", "hashed-fixnames":
		return true
	default:
		return false
	}
}

func validateSafeRunLaneCommandEnvelope(plan worker.Plan, lane string) error {
	if len(plan.Commands) == 0 {
		return fmt.Errorf("%s lane execution requires multiprocessing:safe type=%s command", lane, lane)
	}

	for _, command := range plan.Commands {
		if command.Command != "multiprocessing:safe" {
			return fmt.Errorf("unsupported %s command %q in native lane execution", lane, command.Command)
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok || len(arguments) != 1 || arguments["type"] != lane {
			return fmt.Errorf("%s lane execution requires multiprocessing:safe type=%s command", lane, lane)
		}
	}

	return nil
}

func binariesQueueCommands(queues []binaries.QueueEntry) []string {
	commands := make([]string, 0, len(queues))
	for _, queue := range queues {
		commands = append(commands, queue.Command)
	}

	return commands
}

func backfillQueueCommands(queues []backfill.QueueEntry) []string {
	commands := make([]string, 0, len(queues))
	for _, queue := range queues {
		commands = append(commands, queue.Command)
	}

	return commands
}

func releasesQueueCommands(queues []releases.QueueEntry) []string {
	commands := make([]string, 0, len(queues))
	for _, queue := range queues {
		commands = append(commands, queue.Command)
	}

	return commands
}

func binariesProbeGroups(queues []binaries.QueueEntry) []string {
	groups := []string{}
	seen := map[string]struct{}{}
	for _, queue := range queues {
		if queue.Group == "" {
			continue
		}
		if _, ok := seen[queue.Group]; ok {
			continue
		}
		seen[queue.Group] = struct{}{}
		groups = append(groups, queue.Group)
	}

	return groups
}

func backfillProbeGroups(queues []backfill.QueueEntry) []string {
	groups := []string{}
	seen := map[string]struct{}{}
	for _, queue := range queues {
		if queue.Group == "" {
			continue
		}
		if _, ok := seen[queue.Group]; ok {
			continue
		}
		seen[queue.Group] = struct{}{}
		groups = append(groups, queue.Group)
	}

	return groups
}

func binariesOverviewRanges(queues []binaries.QueueEntry) []nntp.OverviewRange {
	ranges := []nntp.OverviewRange{}
	for _, queue := range queues {
		if queue.Action != "get_range" || queue.Group == "" || queue.Start < 1 || queue.End < queue.Start {
			continue
		}
		ranges = append(ranges, nntp.OverviewRange{
			Group: queue.Group,
			Start: queue.Start,
			End:   queue.End,
		})
	}

	return ranges
}

func backfillOverviewRanges(queues []backfill.QueueEntry) []nntp.OverviewRange {
	ranges := []nntp.OverviewRange{}
	for _, queue := range queues {
		if queue.Action != "get_range" || queue.Group == "" || queue.Start < 1 || queue.End < queue.Start {
			continue
		}
		ranges = append(ranges, nntp.OverviewRange{
			Group: queue.Group,
			Start: queue.Start,
			End:   queue.End,
		})
	}

	return ranges
}

func perGroupQueueCommands(queues []pergroup.QueueEntry) []string {
	commands := make([]string, 0, len(queues))
	for _, queue := range queues {
		commands = append(commands, queue.Command)
	}

	return commands
}

func postprocessQueueCommands(plan postprocess.Plan) []string {
	commands := []string{}
	for _, result := range plan.Results {
		for _, bucket := range result.Buckets {
			commands = append(commands, bucket.Command)
		}
	}

	return commands
}

func postprocessMaxProcesses(plan postprocess.Plan) int {
	maxProcesses := 0
	for _, result := range plan.Results {
		if result.MaxProcesses > maxProcesses {
			maxProcesses = result.MaxProcesses
		}
	}

	return maxProcesses
}

func removeCrapLaneCommands(requests []removecrap.Request) []laneexec.CommandSpec {
	commands := make([]laneexec.CommandSpec, 0, len(requests))
	for _, request := range requests {
		arguments := []string{}
		if strings.TrimSpace(request.Type) != "" {
			arguments = append(arguments, "--type="+request.Type)
		}
		if strings.TrimSpace(request.Time) != "" {
			arguments = append(arguments, "--time="+request.Time)
		}
		if strings.TrimSpace(request.BlacklistID) != "" {
			arguments = append(arguments, "--blacklist-id="+request.BlacklistID)
		}
		if request.DeleteRequested {
			arguments = append(arguments, "--delete")
		}

		commands = append(commands, laneexec.CommandSpec{
			Name:      "releases:remove-crap",
			Arguments: arguments,
		})
	}

	return commands
}

func fixNamesLaneCommands(plan worker.Plan) ([]laneexec.CommandSpec, error) {
	if _, err := fixnames.BuildPlan(plan); err != nil {
		return nil, err
	}

	return fixNameCommandSpecs(plan.Commands, "fixnames", false)
}

func metadataRefreshLaneCommands(plan worker.Plan) ([]laneexec.CommandSpec, error) {
	if plan.Job.Name != "metadata-refresh" {
		return nil, fmt.Errorf("metadata-refresh lane commands require job %q", "metadata-refresh")
	}

	commands := make([]laneexec.CommandSpec, 0, len(plan.Commands))
	hasRefreshCommand := false
	for _, command := range plan.Commands {
		switch command.Command {
		case "predb:refresh-external-metadata":
			spec, err := metadataRefreshCommandSpec(command)
			if err != nil {
				return nil, err
			}
			commands = append(commands, spec)
			hasRefreshCommand = true
		case "releases:fix-names":
			specs, err := fixNameCommandSpecs([]worker.Command{command}, "metadata-refresh", true)
			if err != nil {
				return nil, err
			}
			commands = append(commands, specs...)
		default:
			return nil, fmt.Errorf("unsupported metadata-refresh command %q in native lane execution", command.Command)
		}
	}
	if !hasRefreshCommand {
		return nil, fmt.Errorf("metadata-refresh lane execution requires predb:refresh-external-metadata command")
	}

	return commands, nil
}

func hashedFixNamesLaneCommands(plan worker.Plan) ([]laneexec.CommandSpec, error) {
	if plan.Job.Name != "hashed-fixnames" {
		return nil, fmt.Errorf("hashed-fixnames lane commands require job %q", "hashed-fixnames")
	}

	return fixNameCommandSpecs(plan.Commands, "hashed-fixnames", true)
}

func metadataRefreshCommandSpec(command worker.Command) (laneexec.CommandSpec, error) {
	arguments, ok := command.Arguments.(map[string]any)
	if !ok {
		return laneexec.CommandSpec{}, fmt.Errorf("metadata-refresh command arguments must be an object")
	}

	specArguments := []string{}
	for _, source := range stringSliceArgument(arguments["--source"]) {
		specArguments = append(specArguments, "--source="+source)
	}
	if limit := intArgument(arguments["--limit"], 0); limit > 0 {
		specArguments = append(specArguments, fmt.Sprintf("--limit=%d", limit))
	}
	if sleepMS := intArgument(arguments["--sleep-ms"], 0); sleepMS > 0 {
		specArguments = append(specArguments, fmt.Sprintf("--sleep-ms=%d", sleepMS))
	}

	return laneexec.CommandSpec{
		Name:      "predb:refresh-external-metadata",
		Arguments: specArguments,
	}, nil
}

func fixNameCommandSpecs(commands []worker.Command, lane string, requireHashedCategory bool) ([]laneexec.CommandSpec, error) {
	specs := make([]laneexec.CommandSpec, 0, len(commands))
	for _, command := range commands {
		if command.Command != "releases:fix-names" {
			return nil, fmt.Errorf("unsupported %s command %q in native lane execution", lane, command.Command)
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			return nil, fmt.Errorf("%s command arguments must be an object", lane)
		}

		method := stringArgument(arguments["method"], "")
		if method == "" {
			return nil, fmt.Errorf("%s command method is required", lane)
		}

		category := stringArgument(arguments["--category"], "")
		if requireHashedCategory && category != "hashed" {
			return nil, fmt.Errorf("%s command category must be hashed", lane)
		}

		specArguments := []string{method}
		if category != "" {
			specArguments = append(specArguments, "--category="+category)
		}
		if limit := intArgument(arguments["--limit"], 0); limit > 0 {
			specArguments = append(specArguments, fmt.Sprintf("--limit=%d", limit))
		}
		if boolArgument(arguments["--update"], false) {
			specArguments = append(specArguments, "--update")
		}
		if boolArgument(arguments["--set-status"], false) {
			specArguments = append(specArguments, "--set-status")
		}
		if boolArgument(arguments["--show"], false) {
			specArguments = append(specArguments, "--show")
		}

		specs = append(specs, laneexec.CommandSpec{
			Name:      "releases:fix-names",
			Arguments: specArguments,
		})
	}

	return specs, nil
}

func ircLaneCommands(plan worker.Plan) ([]laneexec.CommandSpec, error) {
	if _, err := irc.BuildPlan(plan); err != nil {
		return nil, err
	}

	commands := make([]laneexec.CommandSpec, 0, len(plan.Commands))
	for range plan.Commands {
		commands = append(commands, laneexec.CommandSpec{Name: "irc:scrape"})
	}

	return commands, nil
}

func writeRehearsalSummary(job string, queueEntries int, rolledBack bool, writesCommitted int) string {
	var buffer bytes.Buffer

	fmt.Fprintf(&buffer, "%s write rehearsal\n", job)
	fmt.Fprintf(&buffer, "queue-entries=%d\n", queueEntries)
	fmt.Fprintf(&buffer, "rolled-back=%t\n", rolledBack)
	fmt.Fprintf(&buffer, "writes-committed=%d\n", writesCommitted)

	return buffer.String()
}

func nntpProbeSummary(job string, report nntp.ProbeReport) string {
	var buffer bytes.Buffer

	fmt.Fprintf(&buffer, "%s nntp probe\n", job)
	fmt.Fprintf(&buffer, "groups=%d\n", report.Groups)
	fmt.Fprintf(&buffer, "successful=%d\n", report.Successful)
	fmt.Fprintf(&buffer, "failed=%d\n", report.Failed)
	fmt.Fprintf(&buffer, "total-count=%d\n", report.TotalCount)
	if report.Successful > 0 {
		fmt.Fprintf(&buffer, "lowest-low=%d\n", report.LowestLow)
		fmt.Fprintf(&buffer, "highest-high=%d\n", report.HighestHigh)
	}

	return buffer.String()
}

func nntpOverviewSampleSummary(job string, report nntp.OverviewSampleReport) string {
	var buffer bytes.Buffer

	fmt.Fprintf(&buffer, "%s nntp overview sample\n", job)
	fmt.Fprintf(&buffer, "ranges=%d\n", report.Ranges)
	fmt.Fprintf(&buffer, "requested=%d\n", report.Requested)
	fmt.Fprintf(&buffer, "received=%d\n", report.Received)
	fmt.Fprintf(&buffer, "empty=%d\n", report.Empty)
	fmt.Fprintf(&buffer, "parsed=%d\n", report.Parsed)
	fmt.Fprintf(&buffer, "malformed=%d\n", report.Malformed)
	fmt.Fprintf(&buffer, "bytes=%d\n", report.Bytes)
	fmt.Fprintf(&buffer, "lines=%d\n", report.Lines)
	fmt.Fprintf(&buffer, "header-candidates=%d\n", report.HeaderCandidates)
	fmt.Fprintf(&buffer, "part-candidates=%d\n", report.PartCandidates)
	fmt.Fprintf(&buffer, "unique-message-ids=%d\n", report.UniqueMessageIDs)
	fmt.Fprintf(&buffer, "duplicate-message-ids=%d\n", report.DuplicateMessageIDs)
	fmt.Fprintf(&buffer, "failed=%d\n", report.Failed)

	return buffer.String()
}

func rehearseHashedFixWrites(ctx context.Context, db *sql.DB, contract namefix.HashedFixWriteContract, resolvedWriteContractPath string) (namefix.WriteRehearsalResult, error) {
	if resolvedWriteContractPath == "" {
		return namefix.RehearseHashedFixWriteContract(ctx, db, contract)
	}

	file, err := os.Open(resolvedWriteContractPath)
	if err != nil {
		return namefix.WriteRehearsalResult{}, fmt.Errorf("open resolved write contract oracle: %w", err)
	}
	defer file.Close()

	oracle, err := namefix.DecodeResolvedWriteContractOracle(file)
	if err != nil {
		return namefix.WriteRehearsalResult{}, err
	}

	return namefix.RehearseResolvedHashedFixWriteContract(ctx, db, contract, oracle)
}

type dryRunReport struct {
	SchemaVersion                      int                                  `json:"schema_version"`
	Mode                               string                               `json:"mode"`
	DryRun                             bool                                 `json:"dry_run"`
	NativeWorker                       nativeWorkerReport                   `json:"native_worker"`
	MetadataRefresh                    *metadataRefreshReport               `json:"metadata_refresh,omitempty"`
	MetadataRefreshSrrdbFetch          *metadata.SrrdbFetchSummary          `json:"metadata_refresh_srrdb_fetch,omitempty"`
	MetadataRefreshSearchProviderFetch *metadata.SearchProviderFetchSummary `json:"metadata_refresh_search_provider_fetch,omitempty"`
	MetadataRefreshPreviewSourceFetch  *metadata.PreviewSourceFetchSummary  `json:"metadata_refresh_preview_source_fetch,omitempty"`
	Binaries                           *binariesReport                      `json:"binaries,omitempty"`
	Backfill                           *backfillReport                      `json:"backfill,omitempty"`
	RemoveCrap                         *removecrap.Plan                     `json:"removecrap,omitempty"`
	Postprocess                        *postprocess.Plan                    `json:"postprocess,omitempty"`
	Releases                           *releases.Plan                       `json:"releases,omitempty"`
	PerGroup                           *pergroup.Plan                       `json:"per_group,omitempty"`
	NNTPProbe                          *nntp.ProbeReport                    `json:"nntp_probe,omitempty"`
	NNTPOverviewSample                 *nntp.OverviewSampleReport           `json:"nntp_overview_sample,omitempty"`
	Fixnames                           *fixnames.Plan                       `json:"fixnames,omitempty"`
	FixnamesWriteCommit                *namefix.MissStatusCommitResult      `json:"fixnames_write_commit,omitempty"`
	Irc                                *irc.Plan                            `json:"irc,omitempty"`
	IrcSession                         *irc.SessionReport                   `json:"irc_session,omitempty"`
	IrcWriteRehearsal                  *irc.WriteRehearsalResult            `json:"irc_write_rehearsal,omitempty"`
	IrcWriteCommit                     *irc.WriteRehearsalResult            `json:"irc_write_commit,omitempty"`
	HashedFixnames                     *hashedFixnamesReport                `json:"hashed_fixnames,omitempty"`
	SearchDocuments                    *searchdoc.ParityReport              `json:"search_documents,omitempty"`
	NativeLane                         *laneexec.Report                     `json:"native_lane,omitempty"`
	MetadataRefreshWriteRehearsal      *metadata.WriteRehearsalResult       `json:"metadata_refresh_write_rehearsal,omitempty"`
	MetadataRefreshWriteCommit         *metadata.WriteRehearsalResult       `json:"metadata_refresh_write_commit,omitempty"`
	BinariesWriteRehearsal             *binaries.WriteRehearsalResult       `json:"binaries_write_rehearsal,omitempty"`
	BinariesWriteCommit                *binaries.WriteRehearsalResult       `json:"binaries_write_commit,omitempty"`
	BackfillWriteRehearsal             *backfill.WriteRehearsalResult       `json:"backfill_write_rehearsal,omitempty"`
	BackfillWriteCommit                *backfill.WriteRehearsalResult       `json:"backfill_write_commit,omitempty"`
	ReleasesWriteRehearsal             *releases.WriteRehearsalResult       `json:"releases_write_rehearsal,omitempty"`
	ReleasesWriteCommit                *releases.WriteRehearsalResult       `json:"releases_write_commit,omitempty"`
	PerGroupWriteRehearsal             *pergroup.WriteRehearsalResult       `json:"per_group_write_rehearsal,omitempty"`
	PerGroupWriteCommit                *pergroup.WriteRehearsalResult       `json:"per_group_write_commit,omitempty"`
	RemoveCrapWriteRehearsal           *removecrap.WriteRehearsalResult     `json:"removecrap_write_rehearsal,omitempty"`
	RemoveCrapWriteCommit              *removecrap.WriteRehearsalResult     `json:"removecrap_write_commit,omitempty"`
	PostprocessWriteRehearsal          *postprocess.WriteRehearsalResult    `json:"postprocess_write_rehearsal,omitempty"`
	PostprocessWriteCommit             *postprocess.WriteRehearsalResult    `json:"postprocess_write_commit,omitempty"`
}

type nativeWorkerReport struct {
	Job                  string                     `json:"job"`
	Enabled              bool                       `json:"enabled"`
	Sleep                int                        `json:"sleep"`
	DisabledReason       *string                    `json:"disabled_reason,omitempty"`
	Lock                 string                     `json:"lock"`
	LockSeconds          int                        `json:"lock_seconds"`
	Commands             int                        `json:"commands"`
	CommandNames         []string                   `json:"command_names"`
	ReplacementReady     bool                       `json:"replacement_ready"`
	ReplacementReadiness replacementReadinessReport `json:"replacement_readiness"`
	Writes               int                        `json:"writes"`
}

type metadataRefreshReport struct {
	SrrdbTitleCandidates int `json:"srrdb_title_candidates"`
	ArchiveCRCCandidates int `json:"archive_crc_candidates"`
	SearchQueries        int `json:"search_queries"`
	Writes               int `json:"writes"`
}

type binariesReport struct {
	Groups        int `json:"groups"`
	QueueEntries  int `json:"queue_entries"`
	HeaderUpdates int `json:"header_updates"`
	PartRepair    int `json:"part_repair"`
	Ranges        int `json:"ranges"`
	Writes        int `json:"writes"`
}

type backfillReport struct {
	Groups           int `json:"groups"`
	QueueEntries     int `json:"queue_entries"`
	Ranges           int `json:"ranges"`
	SkippedInvalid   int `json:"skipped_invalid"`
	SkippedNoWork    int `json:"skipped_no_work"`
	SkippedNearFloor int `json:"skipped_near_floor"`
	Writes           int `json:"writes"`
}

type hashedFixnamesReport struct {
	CRCMutations         int                             `json:"crc_mutations"`
	CRCStatusOnly        int                             `json:"crc_status_only"`
	ParHashMutations     int                             `json:"par_hash_mutations"`
	ParHashStatusOnly    int                             `json:"par_hash_status_only"`
	ReplacementReady     bool                            `json:"replacement_ready"`
	ReplacementReadiness replacementReadinessReport      `json:"replacement_readiness"`
	WriteContract        *namefix.HashedFixWriteContract `json:"write_contract,omitempty"`
	WriteRehearsal       *namefix.WriteRehearsalResult   `json:"write_rehearsal,omitempty"`
	WriteCommit          *namefix.MissStatusCommitResult `json:"write_commit,omitempty"`
	Writes               int                             `json:"writes"`
}

type replacementReadinessReport struct {
	SupportedMethods    []string `json:"supported_methods"`
	UnsupportedMethods  []string `json:"unsupported_methods"`
	UnsupportedCommands int      `json:"unsupported_commands"`
	Blockers            []string `json:"blockers"`
}

func replacementReadinessBlockers(plan worker.Plan, fixNamesPlan fixnames.Plan, ircPlan irc.Plan) []string {
	switch plan.Job.Name {
	case "fixnames":
		if fixNamesPlan.ReplacementReady {
			return nil
		}
		return fixNamesPlan.ReplacementReadiness.Blockers
	case "irc":
		if ircPlan.ReplacementReady {
			return nil
		}
		return ircPlan.ReplacementReadiness.Blockers
	case "metadata-refresh":
		return metadataRefreshReplacementReadinessBlockers(plan)
	case "hashed-fixnames":
		return hashedFixReplacementReadiness(plan).Blockers
	default:
		return genericReplacementReadinessBlockers(plan.Job.Name)
	}
}

func metadataRefreshReplacementReadinessBlockers(plan worker.Plan) []string {
	if hasAnyHashedFixNameCommands(plan) {
		return []string{"metadata-refresh embedded hashed fix-name commands are deferred to PHP"}
	}

	return nil
}

func genericReplacementReadinessBlockers(job string) []string {
	switch job {
	case "binaries":
		return []string{"production binary header acquisition, full header persistence, and cursor ownership remain PHP-owned"}
	case "backfill":
		return []string{"production backfill acquisition, full header persistence, and cursor ownership remain PHP-owned"}
	case "releases":
		return []string{"full release creation, categorization, and release-processing side effects remain PHP-owned"}
	case "removecrap":
		return []string{"removecrap production commit requires live rollout proof"}
	case "post-tv", "post-movies", "post-amazon":
		return []string{"metadata-provider lookups, NZB/NFO reads, release events, and full postprocess side effects remain PHP-owned"}
	case "post-additional":
		return []string{"additional/NFO provider processing, NNTP/NZB/NFO reads, release events, and deferred metadata-refresh/hashed-fixnames side effects remain PHP-owned"}
	case "per-group":
		return []string{"group update, backfill, release creation, and post-processing side effects remain PHP-owned"}
	default:
		return []string{"no explicit replacement-ready implementation", "native replacement behavior has not been proven"}
	}
}

func newWorkerReport(plan worker.Plan, dryRun bool, replacementBlockers []string) dryRunReport {
	commandNames := make([]string, 0, len(plan.Commands))
	for _, command := range plan.Commands {
		commandNames = append(commandNames, command.Command)
	}

	return dryRunReport{
		SchemaVersion: 1,
		Mode:          plan.Mode,
		DryRun:        dryRun,
		NativeWorker: nativeWorkerReport{
			Job:                  plan.Job.Name,
			Enabled:              plan.Job.Enabled,
			Sleep:                plan.Job.Sleep,
			DisabledReason:       plan.Job.DisabledReason,
			Lock:                 plan.Lock.Name,
			LockSeconds:          plan.Lock.Seconds,
			Commands:             len(plan.Commands),
			CommandNames:         commandNames,
			ReplacementReady:     len(replacementBlockers) == 0,
			ReplacementReadiness: newGenericReplacementReadinessReport(replacementBlockers),
			Writes:               0,
		},
	}
}

func newGenericReplacementReadinessReport(blockers []string) replacementReadinessReport {
	if blockers == nil {
		blockers = []string{}
	}

	return replacementReadinessReport{
		SupportedMethods:    []string{},
		UnsupportedMethods:  []string{},
		UnsupportedCommands: 0,
		Blockers:            blockers,
	}
}

func hasSafeBinariesCommands(plan worker.Plan) bool {
	if plan.Job.Name != "binaries" {
		return false
	}

	for _, command := range plan.Commands {
		if command.Command != "multiprocessing:safe" {
			continue
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			continue
		}
		if arguments["type"] == "binaries" {
			return true
		}
	}

	return false
}

func hasSafeBackfillCommands(plan worker.Plan) bool {
	if plan.Job.Name != "backfill" {
		return false
	}

	for _, command := range plan.Commands {
		if command.Command != "multiprocessing:safe" {
			continue
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			continue
		}
		if arguments["type"] == "backfill" {
			return true
		}
	}

	return false
}

func releasesRequest(plan worker.Plan) (bool, error) {
	if plan.Job.Name != "releases" {
		return false, nil
	}

	for _, command := range plan.Commands {
		if command.Command != "multiprocessing:releases" {
			return false, fmt.Errorf("unsupported releases command %q in native dry-run planner", command.Command)
		}
		if !emptyArguments(command.Arguments) {
			return false, fmt.Errorf("releases command arguments must be empty")
		}
	}

	return len(plan.Commands) > 0, nil
}

func perGroupRequest(plan worker.Plan) (bool, error) {
	if plan.Job.Name != "per-group" {
		return false, nil
	}

	for _, command := range plan.Commands {
		if command.Command != "multiprocessing:update-per-group" {
			return false, fmt.Errorf("unsupported per-group command %q in native dry-run planner", command.Command)
		}
		if !emptyArguments(command.Arguments) {
			return false, fmt.Errorf("per-group command arguments must be empty")
		}
	}

	return len(plan.Commands) > 0, nil
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

func removeCrapRequests(plan worker.Plan) ([]removecrap.Request, error) {
	if plan.Job.Name != "removecrap" {
		return nil, nil
	}

	requests := []removecrap.Request{}
	for _, command := range plan.Commands {
		if command.Command != "releases:remove-crap" {
			return nil, fmt.Errorf("unsupported removecrap command %q in native dry-run planner", command.Command)
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			return nil, fmt.Errorf("removecrap command arguments must be an object")
		}

		requests = append(requests, removecrap.Request{
			Type:            stringArgument(arguments["--type"], ""),
			Time:            stringArgument(arguments["--time"], "full"),
			BlacklistID:     stringArgument(arguments["--blacklist-id"], ""),
			DeleteRequested: boolArgument(arguments["--delete"], false),
		})
	}

	return requests, nil
}

func postprocessRequests(plan worker.Plan) ([]postprocess.Request, error) {
	if !isPostprocessPlannerJob(plan.Job.Name) {
		return nil, nil
	}

	requests := []postprocess.Request{}
	for _, command := range plan.Commands {
		if command.Command != "multiprocessing:postprocess" {
			if plan.Job.Name == "post-additional" {
				if isDeferredPostAdditionalCommand(command) {
					continue
				}

				return nil, fmt.Errorf("unsupported post-additional command %q in native dry-run planner", command.Command)
			}

			return nil, fmt.Errorf("unsupported postprocess command %q in native dry-run planner", command.Command)
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			return nil, fmt.Errorf("postprocess command arguments must be an object")
		}

		requestType := stringArgument(arguments["type"], "")
		if plan.Job.Name == "post-additional" && !isPostAdditionalPostprocessType(requestType) {
			return nil, fmt.Errorf("unsupported post-additional postprocess type %q in native dry-run planner", requestType)
		}

		requests = append(requests, postprocess.Request{
			Type:        requestType,
			RenamedOnly: boolArgument(arguments["renamed"], false),
		})
	}

	return requests, nil
}

func isPostprocessPlannerJob(job string) bool {
	switch job {
	case "post-tv", "post-movies", "post-amazon", "post-additional":
		return true
	default:
		return false
	}
}

func isExecutablePostprocessLane(job string) bool {
	switch job {
	case "post-tv", "post-movies", "post-amazon", "post-additional":
		return true
	default:
		return false
	}
}

func isPostAdditionalPostprocessType(value string) bool {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "add", "additional", "nfo":
		return true
	default:
		return false
	}
}

func isDeferredPostAdditionalCommand(command worker.Command) bool {
	switch command.Command {
	case "predb:refresh-external-metadata":
		return true
	case "releases:fix-names":
		method, ok := hashedFixNameMethod(command)
		return ok && isNativeHashedFixNameMethodSupported(method)
	default:
		return false
	}
}

func postAdditionalHasDeferredCommands(plan worker.Plan) bool {
	if plan.Job.Name != "post-additional" {
		return false
	}

	for _, command := range plan.Commands {
		if isDeferredPostAdditionalCommand(command) {
			return true
		}
	}

	return false
}

func metadataRefreshLimit(plan worker.Plan) int {
	for _, command := range plan.Commands {
		if command.Command != "predb:refresh-external-metadata" {
			continue
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			return 25
		}

		switch value := arguments["--limit"].(type) {
		case float64:
			if value >= 1 {
				return int(value)
			}
		case string:
			limit, err := strconv.Atoi(value)
			if err == nil && limit >= 1 {
				return limit
			}
		}

		return 25
	}

	return 25
}

func metadataRefreshSleepMS(plan worker.Plan) int {
	for _, command := range plan.Commands {
		if command.Command != "predb:refresh-external-metadata" {
			continue
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			return 0
		}

		return max(0, intArgument(arguments["--sleep-ms"], 0))
	}

	return 0
}

func metadataRefreshIncludesSrrdb(plan worker.Plan) bool {
	sources := metadataRefreshSources(plan)
	if len(sources) == 0 {
		return true
	}
	for _, source := range sources {
		if source == "all" || source == "srrdb" {
			return true
		}
	}

	return false
}

func metadataRefreshSources(plan worker.Plan) []string {
	for _, command := range plan.Commands {
		if command.Command != "predb:refresh-external-metadata" {
			continue
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			return nil
		}

		sources := stringSliceArgument(arguments["--source"])
		normalized := make([]string, 0, len(sources))
		for _, source := range sources {
			source = strings.ToLower(strings.TrimSpace(source))
			if source != "" {
				normalized = append(normalized, source)
			}
		}

		return normalized
	}

	return nil
}

func hasMetadataRefreshCommand(plan worker.Plan) bool {
	if plan.Job.Name != "metadata-refresh" {
		return false
	}

	for _, command := range plan.Commands {
		if command.Command == "predb:refresh-external-metadata" {
			return true
		}
	}

	return false
}

func hasHashedFixNameCommands(plan worker.Plan) bool {
	for _, command := range plan.Commands {
		method, ok := hashedFixNameMethod(command)
		if !ok {
			continue
		}

		if method == "16" || method == "20" {
			return true
		}
	}

	return false
}

func hasHashedFixNamePlannerCommands(plan worker.Plan) bool {
	switch plan.Job.Name {
	case "metadata-refresh", "hashed-fixnames":
		return hasHashedFixNameCommands(plan)
	default:
		return false
	}
}

func regularFixNameRequests(plan worker.Plan) ([]namefix.RegularFixRequest, error) {
	if plan.Job.Name != "fixnames" {
		return nil, nil
	}

	requests := []namefix.RegularFixRequest{}
	for _, command := range plan.Commands {
		if command.Command != "releases:fix-names" {
			return nil, fmt.Errorf("unsupported fixnames command %q in native dry-run planner", command.Command)
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			return nil, fmt.Errorf("fixnames command arguments must be an object")
		}

		method := methodArgument(arguments["method"])
		if method != "15" && method != "19" {
			continue
		}

		category := stringArgument(arguments["--category"], "")
		if category != "other" && category != "movies" {
			return nil, fmt.Errorf("unsupported regular fix-name category %q", category)
		}

		requests = append(requests, namefix.RegularFixRequest{
			Method:   method,
			Category: category,
			Limit:    positiveIntArgument(arguments["--limit"]),
		})
	}

	return requests, nil
}

func hasAnyHashedFixNameCommands(plan worker.Plan) bool {
	for _, command := range plan.Commands {
		if _, ok := hashedFixNameMethod(command); ok {
			return true
		}
	}

	return false
}

func hasAnyHashedFixNamePlannerCommands(plan worker.Plan) bool {
	switch plan.Job.Name {
	case "metadata-refresh", "hashed-fixnames":
		return hasAnyHashedFixNameCommands(plan)
	default:
		return false
	}
}

func newHashedFixnamesReport(plan worker.Plan) *hashedFixnamesReport {
	readiness := hashedFixReplacementReadiness(plan)

	return &hashedFixnamesReport{
		ReplacementReady:     len(readiness.Blockers) == 0,
		ReplacementReadiness: readiness,
		Writes:               0,
	}
}

func hashedFixReplacementReadiness(plan worker.Plan) replacementReadinessReport {
	supportedMethods := map[string]bool{}
	unsupportedMethods := map[string]bool{}
	unsupportedCommands := 0

	for _, command := range plan.Commands {
		method, ok := hashedFixNameMethod(command)
		if !ok {
			continue
		}

		if isNativeHashedFixNameMethodSupported(method) {
			supportedMethods[method] = true
			continue
		}

		unsupportedMethods[method] = true
		unsupportedCommands++
	}

	report := replacementReadinessReport{
		SupportedMethods:    sortedMethodKeys(supportedMethods),
		UnsupportedMethods:  sortedMethodKeys(unsupportedMethods),
		UnsupportedCommands: unsupportedCommands,
		Blockers:            []string{},
	}
	if len(report.UnsupportedMethods) > 0 {
		report.Blockers = append(report.Blockers, fmt.Sprintf(
			"unsupported hashed fix-name methods: %s",
			strings.Join(report.UnsupportedMethods, ","),
		))
	}
	report.Blockers = append(report.Blockers, "release rename, category, event, and search side effects remain PHP-owned")

	return report
}

func isNativeHashedFixNameMethodSupported(method string) bool {
	return method == "16" || method == "20"
}

func sortedMethodKeys(methods map[string]bool) []string {
	keys := make([]string, 0, len(methods))
	for method := range methods {
		keys = append(keys, method)
	}
	sort.Slice(keys, func(i, j int) bool {
		left, leftErr := strconv.Atoi(keys[i])
		right, rightErr := strconv.Atoi(keys[j])
		if leftErr == nil && rightErr == nil {
			return left < right
		}

		return keys[i] < keys[j]
	})

	return keys
}

func hashedFixCRCLimit(plan worker.Plan) int {
	for _, command := range plan.Commands {
		method, ok := hashedFixNameMethod(command)
		if !ok || method != "20" {
			continue
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			return 0
		}

		return intArgument(arguments["--limit"], 0)
	}

	return 0
}

func hashedFixMethodOrder(plan worker.Plan) []string {
	methods := []string{}
	for _, command := range plan.Commands {
		method, ok := hashedFixNameMethod(command)
		if !ok {
			continue
		}

		if method == "16" || method == "20" {
			methods = append(methods, method)
		}
	}

	return methods
}

func hashedFixSetStatus(plan worker.Plan) bool {
	for _, command := range plan.Commands {
		method, ok := hashedFixNameMethod(command)
		if !ok || (method != "16" && method != "20") {
			continue
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			return false
		}

		return boolArgument(arguments["--set-status"], false)
	}

	return false
}

func hashedFixNameMethod(command worker.Command) (string, bool) {
	if command.Command != "releases:fix-names" {
		return "", false
	}

	arguments, ok := command.Arguments.(map[string]any)
	if !ok {
		return "", false
	}

	category, ok := arguments["--category"].(string)
	if !ok || category != "hashed" {
		return "", false
	}

	switch method := arguments["method"].(type) {
	case string:
		return method, true
	case float64:
		return strconv.Itoa(int(method)), true
	default:
		return "", false
	}
}

func boolArgument(value any, fallback bool) bool {
	switch value := value.(type) {
	case bool:
		return value
	case string:
		parsed, err := strconv.ParseBool(value)
		if err == nil {
			return parsed
		}
	}

	return fallback
}

func stringArgument(value any, fallback string) string {
	switch value := value.(type) {
	case string:
		if value != "" {
			return value
		}
	case float64:
		return strconv.Itoa(int(value))
	}

	return fallback
}

func methodArgument(value any) string {
	return strings.TrimSpace(stringArgument(value, ""))
}

func positiveIntArgument(value any) int {
	switch value := value.(type) {
	case float64:
		if value > 0 {
			return int(value)
		}
	case string:
		parsed, err := strconv.Atoi(strings.TrimSpace(value))
		if err == nil && parsed > 0 {
			return parsed
		}
	}

	return 0
}

func stringSliceArgument(value any) []string {
	switch value := value.(type) {
	case []any:
		values := make([]string, 0, len(value))
		for _, item := range value {
			if stringValue := stringArgument(item, ""); stringValue != "" {
				values = append(values, stringValue)
			}
		}

		return values
	case []string:
		values := make([]string, 0, len(value))
		for _, item := range value {
			if item != "" {
				values = append(values, item)
			}
		}

		return values
	case string:
		if value != "" {
			return []string{value}
		}
	}

	return nil
}

func intArgument(value any, fallback int) int {
	switch value := value.(type) {
	case float64:
		if value >= 1 {
			return int(value)
		}
	case string:
		limit, err := strconv.Atoi(value)
		if err == nil && limit >= 1 {
			return limit
		}
	}

	return fallback
}

type stringListFlag []string

func (flag *stringListFlag) String() string {
	return strings.Join(*flag, ",")
}

func (flag *stringListFlag) Set(value string) error {
	*flag = append(*flag, value)

	return nil
}
