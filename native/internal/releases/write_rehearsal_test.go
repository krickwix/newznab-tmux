package releases

import (
	"context"
	"database/sql"
	"reflect"
	"testing"

	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestRehearseReleaseWritesRollsBack(t *testing.T) {
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
	createReleasesRehearsalTables(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db)
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}
	fingerprintBefore := releasesWriteFingerprint(t, ctx, db)

	result, err := RehearseReleaseWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("RehearseReleaseWrites: %v", err)
	}

	if !result.RolledBack {
		t.Fatalf("RolledBack = false, want true")
	}
	if result.WritesCommitted != 0 {
		t.Fatalf("WritesCommitted = %d, want 0", result.WritesCommitted)
	}
	if result.QueueEntries != 2 || result.ReleaseRowsAttempted != 2 || result.CollectionRowsAttempted != 2 {
		t.Fatalf("result = %#v", result)
	}
	if result.ReleaseRowsAffected == 0 || result.CollectionRowsAffected == 0 {
		t.Fatalf("result rows affected = %#v, want rehearsal writes attempted", result)
	}
	if len(result.CommittedReleaseIDs) != 0 {
		t.Fatalf("CommittedReleaseIDs = %#v, want no committed IDs for rollback-only rehearsal", result.CommittedReleaseIDs)
	}

	if fingerprintAfter := releasesWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitReleaseWritesCommitsRepresentativeRows(t *testing.T) {
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
	createReleasesRehearsalTables(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db)
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}
	fingerprintBefore := releasesWriteFingerprint(t, ctx, db)

	result, err := CommitReleaseWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("CommitReleaseWrites: %v", err)
	}

	if result.RolledBack {
		t.Fatalf("RolledBack = true, want committed writes")
	}
	if result.WritesCommitted == 0 {
		t.Fatalf("WritesCommitted = 0, want committed writes")
	}
	if result.QueueEntries != 2 || result.ReleaseRowsAttempted != 2 || result.CollectionRowsAttempted != 2 {
		t.Fatalf("result = %#v", result)
	}
	if result.ReleaseRowsAffected == 0 || result.CollectionRowsAffected == 0 {
		t.Fatalf("result rows affected = %#v, want committed writes", result)
	}
	if want := []int64{1, 2}; !reflect.DeepEqual(result.CommittedReleaseIDs, want) {
		t.Fatalf("CommittedReleaseIDs = %#v, want %#v", result.CommittedReleaseIDs, want)
	}

	var name, searchName, fromName string
	var groupID, totalPart, size, linkedReleaseID int
	var completion float64
	if err := db.QueryRowContext(ctx, `
		SELECT r.name, r.searchname, r.fromname, r.groups_id, r.totalpart, r.size, r.completion, c.releases_id
		FROM releases r
		INNER JOIN collections c ON c.releases_id = r.id
		WHERE r.id = 1`).Scan(&name, &searchName, &fromName, &groupID, &totalPart, &size, &completion, &linkedReleaseID); err != nil {
		t.Fatalf("read committed release: %v", err)
	}
	if name != "Movie.Release.Native.2026" || searchName != name || fromName != "poster@example.invalid" {
		t.Fatalf("release names/from = %q/%q/%q", name, searchName, fromName)
	}
	if groupID != 1 || totalPart != 2 || size != 3000 || completion != 100 || linkedReleaseID != 1 {
		t.Fatalf("release aggregate = group %d totalpart %d size %d completion %.2f linked %d", groupID, totalPart, size, completion, linkedReleaseID)
	}

	if fingerprintAfter := releasesWriteFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func createReleasesRehearsalTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS releases",
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			totalpart INT DEFAULT 0,
			groups_id INT NOT NULL DEFAULT 0,
			size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			postdate DATETIME NULL,
			categories_id INT NOT NULL DEFAULT 0,
			adddate DATETIME NULL,
			updatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			gid VARCHAR(32) NULL,
			guid VARCHAR(40) NOT NULL,
			leftguid CHAR(1) NOT NULL DEFAULT '',
			fromname VARCHAR(255) NULL,
			completion DOUBLE NOT NULL DEFAULT 0,
			nzbstatus TINYINT NOT NULL DEFAULT 0
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func releasesWriteFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"settings":      "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', name, COALESCE(value, '')) ORDER BY name SEPARATOR '|'), '') FROM settings",
		"usenet_groups": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, active, backfill) ORDER BY id SEPARATOR '|'), '') FROM usenet_groups",
		"collections":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, subject, fromname, groups_id, totalfiles, filesize, COALESCE(releases_id, '')) ORDER BY id SEPARATOR '|'), '') FROM collections",
		"binaries":      "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, collections_id, totalparts, currentparts, partsize) ORDER BY id SEPARATOR '|'), '') FROM binaries",
		"releases":      "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, guid, leftguid, name, searchname, totalpart, groups_id, size, COALESCE(postdate, ''), categories_id, fromname, completion, nzbstatus, COALESCE(adddate, '')) ORDER BY id SEPARATOR '|'), '') FROM releases",
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
