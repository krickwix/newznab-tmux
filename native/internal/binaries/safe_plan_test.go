package binaries

import (
	"context"
	"database/sql"
	"encoding/json"
	"reflect"
	"strings"
	"testing"

	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestSafeBinaryQueueEntriesMatchPHPRunnerBoundaries(t *testing.T) {
	t.Parallel()

	entries := SafeBinaryQueueEntries(SafeBinaryGroup{
		Name:      "alt.binaries.movies",
		OurLast:   1000,
		TheirLast: 100000,
	}, 10000, 25000, 1)

	want := []QueueEntry{
		{Index: 1, Group: "alt.binaries.movies", Action: "part_repair", Command: "part_repair  alt.binaries.movies"},
		{Index: 2, Group: "alt.binaries.movies", Action: "get_range", Start: 1001, End: 11000, Command: "get_range  binaries  alt.binaries.movies  1001  11000  2"},
		{Index: 3, Group: "alt.binaries.movies", Action: "get_range", Start: 11001, End: 21000, Command: "get_range  binaries  alt.binaries.movies  11001  21000  3"},
		{Index: 4, Group: "alt.binaries.movies", Action: "get_range", Start: 21001, End: 26000, Command: "get_range  binaries  alt.binaries.movies  21001  26000  4"},
	}
	if !reflect.DeepEqual(entries, want) {
		t.Fatalf("SafeBinaryQueueEntries = %#v, want %#v", entries, want)
	}
}

func TestSafeBinaryQueueEntriesUseHeaderUpdateForNewOrSmallGroups(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name  string
		group SafeBinaryGroup
	}{
		{
			name:  "new group",
			group: SafeBinaryGroup{Name: "alt.binaries.new", OurLast: 0, TheirLast: 1000},
		},
		{
			name:  "small backlog after skip window",
			group: SafeBinaryGroup{Name: "alt.binaries.small", OurLast: 10000, TheirLast: 50000},
		},
	}

	for _, test := range tests {
		test := test
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()

			entries := SafeBinaryQueueEntries(test.group, 10000, 25000, 7)
			want := []QueueEntry{{
				Index:   7,
				Group:   test.group.Name,
				Action:  "update_group_headers",
				Command: "update_group_headers  " + test.group.Name,
			}}
			if !reflect.DeepEqual(entries, want) {
				t.Fatalf("SafeBinaryQueueEntries = %#v, want %#v", entries, want)
			}
		})
	}
}

func TestBuildSafeBinariesDryRunPlanReadsQueueWithoutChangingMariaDB(t *testing.T) {
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
	resetSafeBinariesTables(t, ctx, db)
	seedSafeBinariesRows(t, ctx, db)

	fingerprintBefore := safeBinariesFingerprint(t, ctx, db)

	plan, err := BuildSafeBinariesDryRunPlan(ctx, db, 10000, 25000)
	if err != nil {
		t.Fatalf("BuildSafeBinariesDryRunPlan: %v", err)
	}

	if plan.Groups != 3 {
		t.Fatalf("Groups = %d, want 3", plan.Groups)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}
	wantCommands := []string{
		"part_repair  alt.binaries.movies",
		"get_range  binaries  alt.binaries.movies  1001  11000  2",
		"get_range  binaries  alt.binaries.movies  11001  21000  3",
		"get_range  binaries  alt.binaries.movies  21001  26000  4",
		"update_group_headers  alt.binaries.small",
		"update_group_headers  alt.binaries.new",
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
		"binaries mysql dry-run",
		"groups=3",
		"queue-entries=6",
		"part-repair=1",
		"ranges=3",
		"header-updates=2",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("summary = %q, missing %q", summary, want)
		}
	}

	if fingerprintAfter := safeBinariesFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestSafeBinariesPlanJSONDoesNotExposePerGroupQueues(t *testing.T) {
	t.Parallel()

	plan := SafeBinariesPlan{
		Groups:       1,
		QueueEntries: 1,
		Queues: []QueueEntry{{
			Group:   "alt.binaries.movies",
			Command: "get_range  binaries  alt.binaries.movies  1  100  1",
		}},
	}

	encoded, err := json.Marshal(plan)
	if err != nil {
		t.Fatalf("marshal SafeBinariesPlan: %v", err)
	}

	for _, forbidden := range []string{"queues", "alt.binaries.movies", "get_range"} {
		if strings.Contains(string(encoded), forbidden) {
			t.Fatalf("SafeBinariesPlan JSON exposed %q: %s", forbidden, encoded)
		}
	}
}

func resetSafeBinariesTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS short_groups",
		"DROP TABLE IF EXISTS usenet_groups",
		`CREATE TABLE usenet_groups (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			first_record BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_record BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_updated DATETIME NULL,
			active TINYINT NOT NULL DEFAULT 0,
			backfill TINYINT NOT NULL DEFAULT 0
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

func seedSafeBinariesRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO usenet_groups (id, name, first_record, last_record, last_updated, active, backfill) VALUES
			(1, 'alt.binaries.movies', 0, 1000, '2026-06-15 10:00:00', 1, 1),
			(2, 'alt.binaries.small', 10, 10000, '2026-06-15 10:00:00', 1, 1),
			(3, 'alt.binaries.new', 0, 0, NULL, 1, 0),
			(4, 'alt.binaries.inactive', 0, 0, NULL, 0, 0),
			(5, 'alt.binaries.no-short-row', 0, 0, NULL, 1, 0)`,
		`INSERT INTO short_groups (name, first_record, last_record) VALUES
			('alt.binaries.movies', 1, 100000),
			('alt.binaries.small', 1, 50000),
			('alt.binaries.new', 1, 1000),
			('alt.binaries.inactive', 1, 999999)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func safeBinariesFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"usenet_groups": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, first_record, last_record, COALESCE(last_updated, ''), active, backfill) ORDER BY id SEPARATOR '|'), '') FROM usenet_groups",
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
