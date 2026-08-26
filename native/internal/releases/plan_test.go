package releases

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

func TestBuildReleasesDryRunPlanSelectsGroupsWithCollectionsWithoutChangingMariaDB(t *testing.T) {
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
	resetReleasesTables(t, ctx, db)
	seedReleasesRows(t, ctx, db)

	fingerprintBefore := releasesFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db)
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.CandidateGroups != 3 || plan.EligibleGroups != 2 || plan.SkippedNoCollections != 1 || plan.QueueEntries != 2 || plan.MaxProcesses != 3 || plan.Batches != 1 || plan.Writes != 0 {
		t.Fatalf("plan = %#v", plan)
	}
	gotCommands := make([]string, 0, len(plan.Queues))
	for _, queue := range plan.Queues {
		gotCommands = append(gotCommands, queue.Command)
	}
	wantCommands := []string{"releases  1", "releases  2"}
	sort.Strings(gotCommands)
	sort.Strings(wantCommands)
	if !reflect.DeepEqual(gotCommands, wantCommands) {
		t.Fatalf("commands = %#v, want %#v", gotCommands, wantCommands)
	}

	summary := DryRunSummary(plan)
	for _, want := range []string{
		"releases mysql dry-run",
		"candidate-groups=3",
		"eligible-groups=2",
		"skipped-no-collections=1",
		"queue-entries=2",
		"max-processes=3",
		"batches=1",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("summary = %q, missing %q", summary, want)
		}
	}

	if fingerprintAfter := releasesFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestReleasesPlanJSONDoesNotExposePerGroupQueues(t *testing.T) {
	t.Parallel()

	plan := Plan{
		CandidateGroups:      1,
		EligibleGroups:       1,
		SkippedNoCollections: 0,
		QueueEntries:         1,
		MaxProcesses:         3,
		Batches:              1,
		Queues: []QueueEntry{{
			GroupID: 1,
			Name:    "alt.binaries.movies",
			Command: "releases  1",
		}},
		Writes: 0,
	}

	encoded, err := json.Marshal(plan)
	if err != nil {
		t.Fatalf("marshal Plan: %v", err)
	}

	for _, forbidden := range []string{"queues", "alt.binaries.movies", "releases  1", "group_id"} {
		if strings.Contains(string(encoded), forbidden) {
			t.Fatalf("Plan JSON exposed %q: %s", forbidden, encoded)
		}
	}
}

func nativeTestDSN(t testing.TB) string {
	t.Helper()

	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native releases integration tests")
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

func resetReleasesTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS parts",
		"DROP TABLE IF EXISTS binaries",
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
			subject VARCHAR(255) NOT NULL DEFAULT '',
			fromname VARCHAR(255) NOT NULL DEFAULT '',
			date DATETIME NULL,
			xref VARCHAR(2000) NOT NULL DEFAULT '',
			totalfiles INT UNSIGNED NOT NULL DEFAULT 0,
			groups_id INT UNSIGNED NOT NULL DEFAULT 0,
			collectionhash VARCHAR(255) NOT NULL DEFAULT '0',
			collection_regexes_id INT NOT NULL DEFAULT 0,
			dateadded DATETIME NULL,
			added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			filecheck TINYINT NOT NULL DEFAULT 0,
			filesize BIGINT UNSIGNED NOT NULL DEFAULT 0,
			releases_id INT NULL,
			noise CHAR(32) NOT NULL DEFAULT '',
			UNIQUE KEY ix_collection_collectionhash (collectionhash)
		)`,
		`CREATE TABLE binaries (
			id INT AUTO_INCREMENT PRIMARY KEY,
			binaryhash BLOB NOT NULL DEFAULT '0',
			name VARCHAR(1000) NOT NULL DEFAULT '',
			collections_id INT UNSIGNED NOT NULL DEFAULT 0,
			filenumber INT UNSIGNED NOT NULL DEFAULT 0,
			totalparts INT UNSIGNED NOT NULL DEFAULT 0,
			currentparts INT UNSIGNED NOT NULL DEFAULT 0,
			partcheck TINYINT NOT NULL DEFAULT 0,
			partsize BIGINT UNSIGNED NOT NULL DEFAULT 0,
			UNIQUE KEY ux_collection_id_filenumber (collections_id, filenumber)
		)`,
		`CREATE TABLE parts (
			binaries_id INT UNSIGNED NOT NULL DEFAULT 0,
			messageid VARCHAR(255) NOT NULL DEFAULT '',
			number BIGINT UNSIGNED NOT NULL DEFAULT 0,
			partnumber INT UNSIGNED NOT NULL DEFAULT 0,
			size INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (binaries_id, number)
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func seedReleasesRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES ('releasethreads', '3')`,
		`INSERT INTO usenet_groups (id, name, active, backfill) VALUES
			(1, 'alt.binaries.movies', 1, 0),
			(2, 'alt.binaries.backfill', 0, 1),
			(3, 'alt.binaries.no-collections', 1, 0),
			(4, 'alt.binaries.inactive', 0, 0)`,
		`INSERT INTO collections (id, subject, fromname, date, totalfiles, groups_id, collectionhash, dateadded, filesize) VALUES
			(100, 'Movie.Release.Native.2026', 'poster@example.invalid', '2026-06-15 10:00:00', 2, 1, 'release-collection-100', '2026-06-15 10:05:00', 3000),
			(101, 'Movie.Release.Native.Extra.2026', 'poster@example.invalid', '2026-06-15 10:10:00', 1, 1, 'release-collection-101', '2026-06-15 10:15:00', 1000),
			(200, 'Backfill.Release.Native.2026', 'backfill@example.invalid', '2026-06-14 09:00:00', 1, 2, 'release-collection-200', '2026-06-14 09:05:00', 1500),
			(400, 'Inactive.Release.Native.2026', 'inactive@example.invalid', '2026-06-13 08:00:00', 1, 4, 'release-collection-400', '2026-06-13 08:05:00', 900)`,
		`INSERT INTO binaries (id, binaryhash, name, collections_id, filenumber, totalparts, currentparts, partsize) VALUES
			(1000, UNHEX(MD5('Movie.Release.Native.2026.part1')), 'Movie.Release.Native.2026.part1', 100, 1, 2, 2, 3000),
			(2000, UNHEX(MD5('Backfill.Release.Native.2026.part1')), 'Backfill.Release.Native.2026.part1', 200, 1, 1, 1, 1500)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func releasesFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"settings":      "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', name, COALESCE(value, '')) ORDER BY name SEPARATOR '|'), '') FROM settings",
		"usenet_groups": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, active, backfill) ORDER BY id SEPARATOR '|'), '') FROM usenet_groups",
		"collections":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, subject, fromname, groups_id, totalfiles, filesize, COALESCE(releases_id, '')) ORDER BY id SEPARATOR '|'), '') FROM collections",
		"binaries":      "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, collections_id, totalparts, currentparts, partsize) ORDER BY id SEPARATOR '|'), '') FROM binaries",
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
