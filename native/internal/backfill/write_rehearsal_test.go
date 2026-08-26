package backfill

import (
	"context"
	"database/sql"
	"reflect"
	"testing"

	"nntmux-native/internal/nntp"
	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestRehearseSafeBackfillWritesRollsBack(t *testing.T) {
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
	createSafeBackfillRehearsalTables(t, ctx, db)

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
	fingerprintBefore := safeBackfillWriteFingerprint(t, ctx, db)

	result, err := RehearseSafeBackfillWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("RehearseSafeBackfillWrites: %v", err)
	}

	if !result.RolledBack {
		t.Fatalf("RolledBack = false, want true")
	}
	if result.WritesCommitted != 0 {
		t.Fatalf("WritesCommitted = %d, want 0", result.WritesCommitted)
	}
	if result.QueueEntries != 4 || result.CursorUpdatesAttempted != 4 || result.HeaderRowsAttempted != 4 || result.PartRowsAttempted != 4 {
		t.Fatalf("result = %#v", result)
	}
	if result.CursorRowsAffected == 0 || result.HeaderRowsAffected == 0 || result.PartRowsAffected == 0 {
		t.Fatalf("result rows affected = %#v, want rehearsal writes attempted", result)
	}

	if fingerprintAfter := safeBackfillWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRehearseOverviewSampleBackfillWritesRollsBack(t *testing.T) {
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
	createSafeBackfillRehearsalTables(t, ctx, db)
	fingerprintBefore := safeBackfillWriteFingerprint(t, ctx, db)

	result, err := RehearseOverviewSampleWrites(ctx, db, nntp.OverviewSampleReport{
		Candidates: []nntp.OverviewCandidate{
			{Group: "a.b.multimedia.movies", Article: 30000, Subject: "Backfill.One", MessageID: "<30000@example.test>", Bytes: 2234, Lines: 55},
			{Group: "a.b.multimedia.movies", Article: 30001, Subject: "Backfill.Two", MessageID: "<30001@example.test>", Bytes: 2235, Lines: 56},
		},
	})
	if err != nil {
		t.Fatalf("RehearseOverviewSampleWrites: %v", err)
	}

	if !result.RolledBack || result.WritesCommitted != 0 {
		t.Fatalf("overview write rehearsal rollback = %#v", result)
	}
	if result.QueueEntries != 2 || result.CursorUpdatesAttempted != 1 || result.HeaderRowsAttempted != 2 || result.PartRowsAttempted != 2 {
		t.Fatalf("overview write rehearsal = %#v", result)
	}
	if result.CursorRowsAffected == 0 || result.HeaderRowsAffected != 2 || result.PartRowsAffected != 2 {
		t.Fatalf("overview write rows = %#v", result)
	}

	if fingerprintAfter := safeBackfillWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("overview rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitOverviewSampleBackfillWritesCommitsRows(t *testing.T) {
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
	createSafeBackfillRehearsalTables(t, ctx, db)
	fingerprintBefore := safeBackfillWriteFingerprint(t, ctx, db)

	result, err := CommitOverviewSampleWrites(ctx, db, nntp.OverviewSampleReport{
		Candidates: []nntp.OverviewCandidate{
			{Group: "a.b.multimedia.movies", Article: 30000, Subject: "Backfill.One", MessageID: "<30000@example.test>", Bytes: 2234, Lines: 55},
			{Group: "a.b.multimedia.movies", Article: 30001, Subject: "Backfill.Two", MessageID: "<30001@example.test>", Bytes: 2235, Lines: 56},
		},
	})
	if err != nil {
		t.Fatalf("CommitOverviewSampleWrites: %v", err)
	}

	if result.RolledBack || result.WritesCommitted == 0 {
		t.Fatalf("overview write commit = %#v", result)
	}
	if result.QueueEntries != 2 || result.CursorUpdatesAttempted != 1 || result.HeaderRowsAttempted != 2 || result.PartRowsAttempted != 2 {
		t.Fatalf("overview write commit = %#v", result)
	}
	if result.CursorRowsAffected == 0 || result.HeaderRowsAffected != 2 || result.PartRowsAffected != 2 {
		t.Fatalf("overview write rows = %#v", result)
	}

	if fingerprintAfter := safeBackfillWriteFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("overview commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitOverviewSampleBackfillWritesAggregatesParsedParts(t *testing.T) {
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
	createSafeBackfillRehearsalTables(t, ctx, db)

	result, err := CommitOverviewSampleWrites(ctx, db, nntp.OverviewSampleReport{
		Candidates: []nntp.OverviewCandidate{
			{Group: "a.b.multimedia.movies", Article: 30000, Subject: `"Backfill.One.mkv" yEnc (1/2)`, MessageID: "<30000@example.test>", Bytes: 2234, Lines: 55},
			{Group: "a.b.multimedia.movies", Article: 30001, Subject: `"Backfill.One.mkv" yEnc (2/2)`, MessageID: "<30001@example.test>", Bytes: 2235, Lines: 56},
		},
	})
	if err != nil {
		t.Fatalf("CommitOverviewSampleWrites: %v", err)
	}

	if result.RolledBack {
		t.Fatalf("overview write commit = %#v", result)
	}

	var collectionsCount, binariesCount, partsCount int
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM collections").Scan(&collectionsCount); err != nil {
		t.Fatalf("count collections: %v", err)
	}
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM binaries").Scan(&binariesCount); err != nil {
		t.Fatalf("count binaries: %v", err)
	}
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM parts").Scan(&partsCount); err != nil {
		t.Fatalf("count parts: %v", err)
	}
	if collectionsCount != 1 || binariesCount != 1 || partsCount != 2 {
		t.Fatalf("collections/binaries/parts = %d/%d/%d, result = %#v, want 1/1/2", collectionsCount, binariesCount, partsCount, result)
	}

	var binaryName string
	var totalParts, currentParts, partSize int
	if err := db.QueryRowContext(ctx, "SELECT name, totalparts, currentparts, partsize FROM binaries").Scan(&binaryName, &totalParts, &currentParts, &partSize); err != nil {
		t.Fatalf("read binary aggregate: %v", err)
	}
	if binaryName != "Backfill.One.mkv" || totalParts != 2 || currentParts != 2 || partSize != 4469 {
		t.Fatalf("binary aggregate = %q %d/%d size=%d, want Backfill.One.mkv 2/2 size=4469", binaryName, currentParts, totalParts, partSize)
	}

	var partNumbers string
	if err := db.QueryRowContext(ctx, "SELECT GROUP_CONCAT(partnumber ORDER BY number SEPARATOR ',') FROM parts").Scan(&partNumbers); err != nil {
		t.Fatalf("read part numbers: %v", err)
	}
	if partNumbers != "1,2" {
		t.Fatalf("part numbers = %q, want 1,2", partNumbers)
	}
}

func TestCommitSafeBackfillWritesCommitsRepresentativeRows(t *testing.T) {
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
	createSafeBackfillRehearsalTables(t, ctx, db)

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
	fingerprintBefore := safeBackfillWriteFingerprint(t, ctx, db)

	result, err := CommitSafeBackfillWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("CommitSafeBackfillWrites: %v", err)
	}

	if result.RolledBack {
		t.Fatalf("RolledBack = true, want committed writes")
	}
	if result.WritesCommitted == 0 {
		t.Fatalf("WritesCommitted = 0, want committed writes")
	}
	if result.QueueEntries != 4 || result.CursorUpdatesAttempted != 4 || result.HeaderRowsAttempted != 4 || result.PartRowsAttempted != 4 {
		t.Fatalf("result = %#v", result)
	}
	if result.CursorRowsAffected == 0 || result.HeaderRowsAffected == 0 || result.PartRowsAffected == 0 {
		t.Fatalf("result rows affected = %#v, want committed writes", result)
	}

	if fingerprintAfter := safeBackfillWriteFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func createSafeBackfillRehearsalTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS parts",
		"DROP TABLE IF EXISTS binaries",
		"DROP TABLE IF EXISTS collections",
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

func safeBackfillWriteFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"usenet_groups": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, first_record, COALESCE(first_record_postdate, ''), backfill, backfill_target) ORDER BY id SEPARATOR '|'), '') FROM usenet_groups",
		"short_groups":  "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, first_record, last_record) ORDER BY id SEPARATOR '|'), '') FROM short_groups",
		"collections":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, subject, groups_id, totalfiles, collectionhash, COALESCE(dateadded, '')) ORDER BY id SEPARATOR '|'), '') FROM collections",
		"binaries":      "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, collections_id, totalparts, currentparts, filenumber, partsize) ORDER BY id SEPARATOR '|'), '') FROM binaries",
		"parts":         "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', binaries_id, number, partnumber, messageid, size) ORDER BY binaries_id, number SEPARATOR '|'), '') FROM parts",
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
