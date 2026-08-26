package binaries

import (
	"context"
	"database/sql"
	"reflect"
	"testing"

	"nntmux-native/internal/nntp"
	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestRehearseSafeBinariesWritesRollsBack(t *testing.T) {
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
	createSafeBinariesRehearsalTables(t, ctx, db)

	plan, err := BuildSafeBinariesDryRunPlan(ctx, db, 10000, 25000)
	if err != nil {
		t.Fatalf("BuildSafeBinariesDryRunPlan: %v", err)
	}
	fingerprintBefore := safeBinariesWriteFingerprint(t, ctx, db)

	result, err := RehearseSafeBinariesWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("RehearseSafeBinariesWrites: %v", err)
	}

	if !result.RolledBack {
		t.Fatalf("RolledBack = false, want true")
	}
	if result.WritesCommitted != 0 {
		t.Fatalf("WritesCommitted = %d, want 0", result.WritesCommitted)
	}
	if result.QueueEntries != 6 || result.CursorUpdatesAttempted != 5 || result.HeaderRowsAttempted != 3 || result.PartRowsAttempted != 3 {
		t.Fatalf("result = %#v", result)
	}
	if result.CursorRowsAffected == 0 || result.HeaderRowsAffected == 0 || result.PartRowsAffected == 0 {
		t.Fatalf("result rows affected = %#v, want rehearsal writes attempted", result)
	}

	if fingerprintAfter := safeBinariesWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRehearseOverviewSampleBinariesWritesRollsBack(t *testing.T) {
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
	createSafeBinariesRehearsalTables(t, ctx, db)
	fingerprintBefore := safeBinariesWriteFingerprint(t, ctx, db)

	result, err := RehearseOverviewSampleWrites(ctx, db, nntp.OverviewSampleReport{
		Candidates: []nntp.OverviewCandidate{
			{Group: "alt.binaries.movies", Article: 1001, Subject: "Movie.One", MessageID: "<1001@example.test>", Bytes: 1234, Lines: 45},
			{Group: "alt.binaries.movies", Article: 1002, Subject: "Movie.Two", MessageID: "<1002@example.test>", Bytes: 1235, Lines: 46},
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

	if fingerprintAfter := safeBinariesWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("overview rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitOverviewSampleBinariesWritesCommitsRows(t *testing.T) {
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
	createSafeBinariesRehearsalTables(t, ctx, db)
	fingerprintBefore := safeBinariesWriteFingerprint(t, ctx, db)

	result, err := CommitOverviewSampleWrites(ctx, db, nntp.OverviewSampleReport{
		Candidates: []nntp.OverviewCandidate{
			{Group: "alt.binaries.movies", Article: 1001, Subject: "Movie.One", MessageID: "<1001@example.test>", Bytes: 1234, Lines: 45},
			{Group: "alt.binaries.movies", Article: 1002, Subject: "Movie.Two", MessageID: "<1002@example.test>", Bytes: 1235, Lines: 46},
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

	if fingerprintAfter := safeBinariesWriteFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("overview commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitOverviewSampleBinariesWritesAggregatesParsedParts(t *testing.T) {
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
	createSafeBinariesRehearsalTables(t, ctx, db)

	result, err := CommitOverviewSampleWrites(ctx, db, nntp.OverviewSampleReport{
		Candidates: []nntp.OverviewCandidate{
			{Group: "alt.binaries.movies", Article: 1001, Subject: `"Movie.One.mkv" yEnc (1/2)`, MessageID: "<1001@example.test>", Bytes: 1234, Lines: 45},
			{Group: "alt.binaries.movies", Article: 1002, Subject: `"Movie.One.mkv" yEnc (2/2)`, MessageID: "<1002@example.test>", Bytes: 1235, Lines: 46},
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
	if binaryName != "Movie.One.mkv" || totalParts != 2 || currentParts != 2 || partSize != 2469 {
		t.Fatalf("binary aggregate = %q %d/%d size=%d, want Movie.One.mkv 2/2 size=2469", binaryName, currentParts, totalParts, partSize)
	}

	var partNumbers string
	if err := db.QueryRowContext(ctx, "SELECT GROUP_CONCAT(partnumber ORDER BY number SEPARATOR ',') FROM parts").Scan(&partNumbers); err != nil {
		t.Fatalf("read part numbers: %v", err)
	}
	if partNumbers != "1,2" {
		t.Fatalf("part numbers = %q, want 1,2", partNumbers)
	}
}

func TestCommitSafeBinariesWritesCommitsRepresentativeRows(t *testing.T) {
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
	createSafeBinariesRehearsalTables(t, ctx, db)

	plan, err := BuildSafeBinariesDryRunPlan(ctx, db, 10000, 25000)
	if err != nil {
		t.Fatalf("BuildSafeBinariesDryRunPlan: %v", err)
	}
	fingerprintBefore := safeBinariesWriteFingerprint(t, ctx, db)

	result, err := CommitSafeBinariesWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("CommitSafeBinariesWrites: %v", err)
	}

	if result.RolledBack {
		t.Fatalf("RolledBack = true, want committed transaction")
	}
	if result.QueueEntries != 6 || result.CursorUpdatesAttempted != 5 || result.HeaderRowsAttempted != 3 || result.PartRowsAttempted != 3 {
		t.Fatalf("result = %#v", result)
	}
	if result.CursorRowsAffected == 0 || result.HeaderRowsAffected != 3 || result.PartRowsAffected != 3 {
		t.Fatalf("result rows affected = %#v, want committed representative writes", result)
	}
	if result.WritesCommitted != int(result.CursorRowsAffected+result.HeaderRowsAffected+result.PartRowsAffected) {
		t.Fatalf("WritesCommitted = %d, want total affected rows", result.WritesCommitted)
	}

	if fingerprintAfter := safeBinariesWriteFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func createSafeBinariesRehearsalTables(t *testing.T, ctx context.Context, db *sql.DB) {
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

func safeBinariesWriteFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"usenet_groups": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, first_record, last_record, COALESCE(last_updated, ''), active, backfill) ORDER BY id SEPARATOR '|'), '') FROM usenet_groups",
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
