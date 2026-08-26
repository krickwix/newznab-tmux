package backfill

import (
	"context"
	"database/sql"
	"encoding/json"
	"reflect"
	"strings"
	"testing"
	"time"

	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestSafeBackfillQueueEntriesMatchPHPRunnerBoundariesAndInterleaving(t *testing.T) {
	t.Parallel()

	queues, stats := SafeBackfillQueueEntries([]SafeBackfillGroup{
		{
			Name:       "a.b.multimedia.movies",
			OurFirst:   50000,
			TheirFirst: 1,
			TheirLast:  200000,
		},
		{
			Name:       "a.b.multimedia.vintage-film",
			OurFirst:   105,
			TheirFirst: 2,
			TheirLast:  200000,
		},
	}, 75000, 20000, 4, 100)

	want := []QueueEntry{
		{Key: "a.b.multimedia.movies#1", Chunk: 1, Group: "a.b.multimedia.movies", Action: "get_range", Start: 30000, End: 49999, Command: "get_range  backfill  a.b.multimedia.movies  30000  49999  1"},
		{Key: "a.b.multimedia.vintage-film#1", Chunk: 1, Group: "a.b.multimedia.vintage-film", Action: "get_range", Start: 2, End: 104, Command: "get_range  backfill  a.b.multimedia.vintage-film  2  104  1"},
		{Key: "a.b.multimedia.movies#2", Chunk: 2, Group: "a.b.multimedia.movies", Action: "get_range", Start: 10000, End: 29999, Command: "get_range  backfill  a.b.multimedia.movies  10000  29999  2"},
		{Key: "a.b.multimedia.movies#3", Chunk: 3, Group: "a.b.multimedia.movies", Action: "get_range", Start: 1, End: 9999, Command: "get_range  backfill  a.b.multimedia.movies  1  9999  3"},
	}
	if !reflect.DeepEqual(queues, want) {
		t.Fatalf("SafeBackfillQueueEntries = %#v, want %#v", queues, want)
	}

	if stats.Groups != 2 || stats.QueueEntries != 4 || stats.Ranges != 4 || stats.Writes != 0 {
		t.Fatalf("stats = %#v", stats)
	}
}

func TestSafeBackfillQueueEntriesSkipInvalidNoWorkAndNearFloor(t *testing.T) {
	t.Parallel()

	queues, stats := SafeBackfillQueueEntries([]SafeBackfillGroup{
		{
			Name:       "a.b.uninitialized",
			OurFirst:   0,
			TheirFirst: 2,
			TheirLast:  200000,
		},
		{
			Name:       "a.b.bad-provider-row",
			OurFirst:   1000,
			TheirFirst: 2000,
			TheirLast:  1000,
		},
		{
			Name:       "a.b.at-provider-floor",
			OurFirst:   100,
			TheirFirst: 100,
			TheirLast:  200000,
		},
		{
			Name:       "a.b.near-provider-floor",
			OurFirst:   50,
			TheirFirst: 2,
			TheirLast:  200000,
		},
	}, 75000, 0, 4, 100)

	if len(queues) != 0 {
		t.Fatalf("queues = %#v, want empty", queues)
	}
	if stats.Groups != 4 {
		t.Fatalf("stats.Groups = %d, want 4", stats.Groups)
	}
	if stats.SkippedInvalid != 2 {
		t.Fatalf("stats.SkippedInvalid = %d, want 2", stats.SkippedInvalid)
	}
	if stats.SkippedNoWork != 1 {
		t.Fatalf("stats.SkippedNoWork = %d, want 1", stats.SkippedNoWork)
	}
	if stats.SkippedNearFloor != 1 {
		t.Fatalf("stats.SkippedNearFloor = %d, want 1", stats.SkippedNearFloor)
	}
	if stats.Writes != 0 {
		t.Fatalf("stats.Writes = %d, want 0", stats.Writes)
	}
}

func TestSafeBackfillQueueEntriesFallBackWhenMaxMessagesIsInvalid(t *testing.T) {
	t.Parallel()

	queues, stats := SafeBackfillQueueEntries([]SafeBackfillGroup{
		{
			Name:       "a.b.multimedia.vintage-film",
			OurFirst:   105,
			TheirFirst: 2,
			TheirLast:  200000,
		},
	}, 75000, 0, 4, 100)

	want := []QueueEntry{{
		Key:     "a.b.multimedia.vintage-film#1",
		Chunk:   1,
		Group:   "a.b.multimedia.vintage-film",
		Action:  "get_range",
		Start:   2,
		End:     104,
		Command: "get_range  backfill  a.b.multimedia.vintage-film  2  104  1",
	}}
	if !reflect.DeepEqual(queues, want) {
		t.Fatalf("SafeBackfillQueueEntries = %#v, want %#v", queues, want)
	}
	if stats.QueueEntries != 1 || stats.Ranges != 1 || stats.Writes != 0 {
		t.Fatalf("stats = %#v", stats)
	}
}

func TestBuildSafeBackfillDryRunPlanReadsQueueWithoutChangingMariaDB(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetSafeBackfillTables(t, ctx, db)
	seedSafeBackfillRows(t, ctx, db)

	fingerprintBefore := safeBackfillFingerprint(t, ctx, db)

	plan, err := BuildSafeBackfillDryRunPlan(ctx, db, Options{
		BackfillQty:      75000,
		MaxMessages:      20000,
		Threads:          4,
		BackfillGroups:   10,
		BackfillDays:     1,
		MinimumSafeRange: 100,
	})
	if err != nil {
		t.Fatalf("BuildSafeBackfillDryRunPlan: %v", err)
	}

	if plan.Groups != 2 {
		t.Fatalf("Groups = %d, want 2", plan.Groups)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}
	wantCommands := []string{
		"get_range  backfill  a.b.multimedia.movies  30000  49999  1",
		"get_range  backfill  a.b.multimedia.vintage-film  2  104  1",
		"get_range  backfill  a.b.multimedia.movies  10000  29999  2",
		"get_range  backfill  a.b.multimedia.movies  1  9999  3",
	}
	gotCommands := make([]string, 0, len(plan.Queues))
	for _, queue := range plan.Queues {
		gotCommands = append(gotCommands, queue.Command)
	}
	if !reflect.DeepEqual(gotCommands, wantCommands) {
		t.Fatalf("commands = %#v, want %#v", gotCommands, wantCommands)
	}

	summary := DryRunSummary(plan)
	for _, want := range []string{
		"backfill mysql dry-run",
		"groups=2",
		"queue-entries=4",
		"ranges=4",
		"skipped-invalid=1",
		"skipped-no-work=1",
		"skipped-near-floor=1",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("summary = %q, missing %q", summary, want)
		}
	}

	if fingerprintAfter := safeBackfillFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildSafeBackfillDryRunPlanSupportsSafeDateMode(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetSafeBackfillTables(t, ctx, db)
	seedSafeBackfillRows(t, ctx, db)

	now := time.Now()
	plan, err := BuildSafeBackfillDryRunPlan(ctx, db, Options{
		BackfillQty:      75000,
		MaxMessages:      20000,
		Threads:          4,
		BackfillGroups:   10,
		BackfillDays:     2,
		SafeBackfillDate: now.AddDate(0, 0, -3),
		Now:              now,
		MinimumSafeRange: 100,
	})
	if err != nil {
		t.Fatalf("BuildSafeBackfillDryRunPlan: %v", err)
	}

	if plan.Groups != 2 || plan.QueueEntries != 4 || plan.Writes != 0 {
		t.Fatalf("plan = %#v", plan)
	}
	if plan.SkippedInvalid != 1 || plan.SkippedNoWork != 1 || plan.SkippedNearFloor != 1 {
		t.Fatalf("plan skip diagnostics = %#v", plan)
	}
}

func TestSafeBackfillPlanJSONDoesNotExposePerGroupQueues(t *testing.T) {
	t.Parallel()

	plan := SafeBackfillPlan{
		Groups:       1,
		QueueEntries: 1,
		Queues: []QueueEntry{{
			Group:   "a.b.multimedia.movies",
			Command: "get_range  backfill  a.b.multimedia.movies  1  100  1",
		}},
	}

	encoded, err := json.Marshal(plan)
	if err != nil {
		t.Fatalf("marshal SafeBackfillPlan: %v", err)
	}

	for _, forbidden := range []string{"queues", "a.b.multimedia.movies", "get_range"} {
		if strings.Contains(string(encoded), forbidden) {
			t.Fatalf("SafeBackfillPlan JSON exposed %q: %s", forbidden, encoded)
		}
	}
}

func resetSafeBackfillTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS short_groups",
		"DROP TABLE IF EXISTS usenet_groups",
		`CREATE TABLE usenet_groups (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			first_record BIGINT UNSIGNED NOT NULL DEFAULT 0,
			first_record_postdate DATETIME NULL,
			backfill TINYINT NOT NULL DEFAULT 0,
			backfill_target INT NOT NULL DEFAULT 1
		)`,
		`CREATE TABLE short_groups (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			first_record BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_record BIGINT UNSIGNED NOT NULL DEFAULT 0
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func seedSafeBackfillRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO usenet_groups (id, name, first_record, first_record_postdate, backfill, backfill_target) VALUES
			(1, 'a.b.multimedia.movies', 50000, NOW() - INTERVAL 1 HOUR, 1, 10),
			(2, 'a.b.multimedia.vintage-film', 105, NOW() - INTERVAL 2 HOUR, 1, 10),
			(3, 'a.b.near-provider-floor', 50, NOW() - INTERVAL 3 HOUR, 1, 10),
			(4, 'a.b.at-provider-floor', 1, NOW() - INTERVAL 4 HOUR, 1, 10),
			(5, 'a.b.bad-provider-row', 1000, NOW() - INTERVAL 5 HOUR, 1, 10),
			(6, 'a.b.target-reached', 1000, NOW() - INTERVAL 20 DAY, 1, 10),
			(7, 'a.b.no-short-row', 1000, NOW() - INTERVAL 6 HOUR, 1, 10),
			(8, 'a.b.backfill-disabled', 1000, NOW() - INTERVAL 7 HOUR, 0, 10)`,
		`INSERT INTO short_groups (name, first_record, last_record) VALUES
			('a.b.multimedia.movies', 1, 200000),
			('a.b.multimedia.vintage-film', 2, 200000),
			('a.b.near-provider-floor', 2, 200000),
			('a.b.at-provider-floor', 1, 200000),
			('a.b.bad-provider-row', 2000, 1000),
			('a.b.target-reached', 1, 200000),
			('a.b.backfill-disabled', 1, 200000)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func safeBackfillFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"usenet_groups": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, first_record, COALESCE(first_record_postdate, ''), backfill, backfill_target) ORDER BY id SEPARATOR '|'), '') FROM usenet_groups",
		"short_groups":  "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, first_record, last_record) ORDER BY id SEPARATOR '|'), '') FROM short_groups",
	}

	fingerprint := map[string]string{}
	for table, query := range queries {
		var value string
		if err := db.QueryRowContext(ctx, query).Scan(&value); err != nil {
			t.Fatalf("fingerprint %s: %v", table, err)
		}
		fingerprint[table] = value
	}

	return fingerprint
}
