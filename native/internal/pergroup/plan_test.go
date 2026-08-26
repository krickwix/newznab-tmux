package pergroup

import (
	"context"
	"database/sql"
	"encoding/json"
	"os"
	"reflect"
	"sort"
	"strings"
	"testing"

	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestBuildPerGroupDryRunPlanSelectsActiveAndBackfillGroupsWithoutChangingMariaDB(t *testing.T) {
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
	resetPerGroupTables(t, ctx, db)
	seedPerGroupRows(t, ctx, db)

	fingerprintBefore := perGroupFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db)
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.CandidateGroups != 5 || plan.QueueEntries != 5 || plan.MaxProcesses != 2 || plan.Batches != 3 || plan.Writes != 0 {
		t.Fatalf("plan = %#v", plan)
	}
	gotCommands := make([]string, 0, len(plan.Queues))
	for _, queue := range plan.Queues {
		gotCommands = append(gotCommands, queue.Command)
	}
	wantCommands := []string{
		"update_per_group  1",
		"update_per_group  2",
		"update_per_group  3",
		"update_per_group  4",
		"update_per_group  5",
	}
	sort.Strings(gotCommands)
	sort.Strings(wantCommands)
	if !reflect.DeepEqual(gotCommands, wantCommands) {
		t.Fatalf("commands = %#v, want %#v", gotCommands, wantCommands)
	}

	summary := DryRunSummary(plan)
	for _, want := range []string{
		"per-group mysql dry-run",
		"candidate-groups=5",
		"queue-entries=5",
		"max-processes=2",
		"batches=3",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("summary = %q, missing %q", summary, want)
		}
	}

	if fingerprintAfter := perGroupFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestPerGroupPlanJSONDoesNotExposePerGroupQueues(t *testing.T) {
	t.Parallel()

	plan := Plan{
		CandidateGroups: 1,
		QueueEntries:    1,
		MaxProcesses:    2,
		Batches:         1,
		Queues: []QueueEntry{{
			GroupID: 1,
			Name:    "alt.binaries.movies",
			Command: "update_per_group  1",
		}},
		Writes: 0,
	}

	encoded, err := json.Marshal(plan)
	if err != nil {
		t.Fatalf("marshal Plan: %v", err)
	}

	for _, forbidden := range []string{"queues", "alt.binaries.movies", "update_per_group", "group_id", "collections", "skipped_no_collections"} {
		if strings.Contains(string(encoded), forbidden) {
			t.Fatalf("Plan JSON exposed %q: %s", forbidden, encoded)
		}
	}
}

func TestRehearsePerGroupWritesRollsBackRepresentativeGroupUpdates(t *testing.T) {
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
	resetPerGroupTables(t, ctx, db)
	seedPerGroupRows(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db)
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}
	fingerprintBefore := perGroupFingerprint(t, ctx, db)

	rehearsal, err := RehearsePerGroupWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("RehearsePerGroupWrites: %v", err)
	}

	if rehearsal.QueueEntries != 5 || rehearsal.GroupUpdatesAttempted != 5 || rehearsal.GroupRowsAffected != 5 {
		t.Fatalf("rehearsal = %#v, want 5 queued attempted/affected group updates", rehearsal)
	}
	if !rehearsal.RolledBack {
		t.Fatal("RolledBack = false, want true")
	}
	if rehearsal.WritesCommitted != 0 {
		t.Fatalf("WritesCommitted = %d, want 0", rehearsal.WritesCommitted)
	}

	if fingerprintAfter := perGroupFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitPerGroupWritesCommitsRepresentativeGroupUpdates(t *testing.T) {
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
	resetPerGroupTables(t, ctx, db)
	seedPerGroupRows(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db)
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}
	fingerprintBefore := perGroupFingerprint(t, ctx, db)

	commit, err := CommitPerGroupWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("CommitPerGroupWrites: %v", err)
	}

	if commit.QueueEntries != 5 || commit.GroupUpdatesAttempted != 5 || commit.GroupRowsAffected != 5 {
		t.Fatalf("commit = %#v, want 5 queued attempted/affected group updates", commit)
	}
	if commit.RolledBack {
		t.Fatal("RolledBack = true, want committed writes")
	}
	if commit.WritesCommitted != 5 {
		t.Fatalf("WritesCommitted = %d, want 5", commit.WritesCommitted)
	}

	if fingerprintAfter := perGroupFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func nativeTestDSN(t testing.TB) string {
	t.Helper()

	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native per-group integration tests")
	}

	return dsn
}

func acquireIntegrationLock(t testing.TB, ctx context.Context, db *sql.DB) func() {
	t.Helper()

	conn, err := db.Conn(ctx)
	if err != nil {
		t.Fatalf("open mysql lock connection: %v", err)
	}

	var acquired int
	if err := conn.QueryRowContext(ctx, "SELECT GET_LOCK('nntmux_native_integration_schema_test', 30)").Scan(&acquired); err != nil {
		_ = conn.Close()
		t.Fatalf("get mysql lock: %v", err)
	}
	if acquired != 1 {
		_ = conn.Close()
		t.Fatalf("get mysql lock returned %d", acquired)
	}

	return func() {
		defer conn.Close()
		if _, err := conn.ExecContext(ctx, "SELECT RELEASE_LOCK('nntmux_native_integration_schema_test')"); err != nil {
			t.Fatalf("release mysql lock: %v", err)
		}
	}
}

func resetPerGroupTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS collections",
		"DROP TABLE IF EXISTS usenet_groups",
		"DROP TABLE IF EXISTS settings",
		`CREATE TABLE settings (
			name VARCHAR(255) PRIMARY KEY,
			value TEXT NULL
		)`,
		`CREATE TABLE usenet_groups (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			active TINYINT NOT NULL DEFAULT 0,
			backfill TINYINT NOT NULL DEFAULT 0,
			last_updated DATETIME(6) NULL
		)`,
		`CREATE TABLE collections (
			id INT AUTO_INCREMENT PRIMARY KEY,
			groups_id INT NOT NULL
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func seedPerGroupRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES ('releasethreads', '2')`,
		`INSERT INTO usenet_groups (id, name, active, backfill) VALUES
			(1, 'alt.binaries.active', 1, 0),
			(2, 'alt.binaries.backfill', 0, 1),
			(3, 'alt.binaries.both', 1, 1),
			(4, 'alt.binaries.no-collections', 1, 0),
			(5, 'alt.binaries.backfill-empty', 0, 1),
			(6, 'alt.binaries.inactive-with-collection', 0, 0)`,
		`INSERT INTO collections (id, groups_id) VALUES
			(100, 1),
			(600, 6)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func perGroupFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"settings":      "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', name, COALESCE(value, '')) ORDER BY name SEPARATOR '|'), '') FROM settings",
		"usenet_groups": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, active, backfill, COALESCE(DATE_FORMAT(last_updated, '%Y-%m-%d %H:%i:%s.%f'), '')) ORDER BY id SEPARATOR '|'), '') FROM usenet_groups",
		"collections":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, groups_id) ORDER BY id SEPARATOR '|'), '') FROM collections",
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
