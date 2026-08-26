package irc

import (
	"context"
	"database/sql"
	"os"
	"reflect"
	"testing"
	"time"

	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestRehearsePredbWritesRollsBackCandidateInsertsAndUpdates(t *testing.T) {
	db, dsn := openIRCTestDB(t)
	ctx := context.Background()
	unlock := acquireIRCIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetIRCTables(t, ctx, db)
	seedIRCPredbRows(t, ctx, db)

	before := ircTableFingerprint(t, ctx, db)
	result, err := RehearsePredbWrites(ctx, db, ircWriteCandidates())
	if err != nil {
		t.Fatalf("RehearsePredbWrites: %v", err)
	}

	if result.Candidates != 2 || result.PredbRowsAttempted != 2 || result.PredbRowsAffected != 2 {
		t.Fatalf("result = %#v", result)
	}
	if result.InsertedRows != 1 || result.UpdatedRows != 1 || result.SearchUpdatesEnqueued != 2 {
		t.Fatalf("insert/update/search = %#v", result)
	}
	if !result.RolledBack || result.WritesCommitted != 0 {
		t.Fatalf("rollback = %#v", result)
	}
	after := ircTableFingerprint(t, ctx, db)
	if !reflect.DeepEqual(after, before) {
		t.Fatalf("fingerprint after rollback = %#v, want %#v", after, before)
	}
}

func TestCommitPredbWritesPersistsCandidateInsertsAndUpdates(t *testing.T) {
	db, dsn := openIRCTestDB(t)
	ctx := context.Background()
	unlock := acquireIRCIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetIRCTables(t, ctx, db)
	seedIRCPredbRows(t, ctx, db)

	result, err := CommitPredbWrites(ctx, db, ircWriteCandidates())
	if err != nil {
		t.Fatalf("CommitPredbWrites: %v", err)
	}

	if result.RolledBack || result.WritesCommitted != 4 || result.InsertedRows != 1 || result.UpdatedRows != 1 || result.SearchUpdatesEnqueued != 2 {
		t.Fatalf("commit = %#v", result)
	}

	var insertedSource, insertedCategory, insertedFiles string
	var insertedRequestID, insertedGroupID, insertedNuked int
	if err := db.QueryRowContext(ctx, `
		SELECT source, category, files, requestid, groups_id, nuked
		FROM predb
		WHERE title = 'New.Movie.2026-GRP'`,
	).Scan(&insertedSource, &insertedCategory, &insertedFiles, &insertedRequestID, &insertedGroupID, &insertedNuked); err != nil {
		t.Fatalf("read inserted predb row: %v", err)
	}
	if insertedSource != "#a.b.movies" || insertedCategory != "MOVIE" || insertedFiles != "10F" || insertedRequestID != 44 || insertedGroupID != 88 || insertedNuked != PreNoNuke {
		t.Fatalf("inserted row = source:%q category:%q files:%q request:%d group:%d nuked:%d", insertedSource, insertedCategory, insertedFiles, insertedRequestID, insertedGroupID, insertedNuked)
	}

	var updatedCategory, updatedSize, updatedReason string
	var updatedGroupID, updatedNuked int
	if err := db.QueryRowContext(ctx, `
		SELECT category, size, nukereason, groups_id, nuked
		FROM predb
		WHERE title = 'Existing.Movie.2025-GRP'`,
	).Scan(&updatedCategory, &updatedSize, &updatedReason, &updatedGroupID, &updatedNuked); err != nil {
		t.Fatalf("read updated predb row: %v", err)
	}
	if updatedCategory != "OLD-CAT" || updatedSize != "7 GB" || updatedReason != "bad.pack" || updatedGroupID != 89 || updatedNuked != PreNuked {
		t.Fatalf("updated row = category:%q size:%q reason:%q group:%d nuked:%d", updatedCategory, updatedSize, updatedReason, updatedGroupID, updatedNuked)
	}

	var sideEffects int
	if err := db.QueryRowContext(ctx, `
		SELECT COUNT(*)
		FROM native_worker_side_effects
		WHERE job = 'irc'
		  AND effect = 'predb-search-sync'
		  AND status_column = 'predb_id'
		  AND status_reason = 'predb-import'
		  AND status_value = 1
		  AND status = 'pending'`).Scan(&sideEffects); err != nil {
		t.Fatalf("count irc predb search side effects: %v", err)
	}
	if sideEffects != 2 {
		t.Fatalf("irc predb search side effects = %d, want 2", sideEffects)
	}
}

func openIRCTestDB(t *testing.T) (*sql.DB, string) {
	t.Helper()

	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native irc integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	t.Cleanup(func() {
		if err := db.Close(); err != nil {
			t.Fatalf("close mysql: %v", err)
		}
	})

	return db, dsn
}

func acquireIRCIntegrationLock(t *testing.T, ctx context.Context, db *sql.DB) func() {
	t.Helper()

	conn, err := db.Conn(ctx)
	if err != nil {
		t.Fatalf("open mysql lock connection: %v", err)
	}

	var acquired int
	if err := conn.QueryRowContext(ctx, "SELECT GET_LOCK('nntmux_native_irc_schema_test', 30)").Scan(&acquired); err != nil {
		_ = conn.Close()
		t.Fatalf("acquire mysql integration lock: %v", err)
	}
	if acquired != 1 {
		_ = conn.Close()
		t.Fatalf("acquire mysql integration lock = %d, want 1", acquired)
	}

	return func() {
		t.Helper()
		defer conn.Close()

		if _, err := conn.ExecContext(ctx, "SELECT RELEASE_LOCK('nntmux_native_irc_schema_test')"); err != nil {
			t.Fatalf("release mysql integration lock: %v", err)
		}
	}
}

func resetIRCTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS native_worker_side_effects",
		"DROP TABLE IF EXISTS predb",
		"DROP TABLE IF EXISTS usenet_groups",
		`CREATE TABLE usenet_groups (
			id INT UNSIGNED NOT NULL PRIMARY KEY,
			name VARCHAR(255) NOT NULL UNIQUE
		)`,
		`CREATE TABLE predb (
			id INT AUTO_INCREMENT PRIMARY KEY,
			title VARCHAR(255) NOT NULL UNIQUE,
			nfo VARCHAR(255) NULL,
			size VARCHAR(50) NULL,
			category VARCHAR(255) NULL,
			predate DATETIME NULL,
			source VARCHAR(50) NOT NULL DEFAULT '',
			requestid INT UNSIGNED NOT NULL DEFAULT 0,
			groups_id INT UNSIGNED NOT NULL DEFAULT 0,
			nuked TINYINT NOT NULL DEFAULT 0,
			nukereason VARCHAR(255) NULL,
			files VARCHAR(50) NULL,
			filename VARCHAR(255) NOT NULL DEFAULT '',
			searched TINYINT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE native_worker_side_effects (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			operation_key VARCHAR(255) NOT NULL UNIQUE,
			job VARCHAR(64) NOT NULL,
			effect VARCHAR(64) NOT NULL,
			release_id BIGINT UNSIGNED NOT NULL,
			status_column VARCHAR(64) NOT NULL,
			status_reason VARCHAR(64) NOT NULL,
			status_value TINYINT NOT NULL,
			status VARCHAR(32) NOT NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			available_at DATETIME NULL,
			processed_at DATETIME NULL,
			last_error_code VARCHAR(64) NULL,
			created_at DATETIME NULL,
			updated_at DATETIME NULL
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func seedIRCPredbRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	if _, err := db.ExecContext(ctx, `INSERT INTO usenet_groups (id, name) VALUES (88, 'alt.binaries.movies'), (89, 'alt.binaries.tv')`); err != nil {
		t.Fatalf("seed irc groups: %v", err)
	}

	_, err := db.ExecContext(ctx, `
		INSERT INTO predb (id, title, source, category, size, predate, nuked, nukereason, files, filename)
		VALUES (1, 'Existing.Movie.2025-GRP', 'old-source', 'OLD-CAT', '1 GB', '2026-06-16 00:00:00', 0, NULL, '1F', 'old.r00')`)
	if err != nil {
		t.Fatalf("seed irc predb rows: %v", err)
	}
}

func ircWriteCandidates() []Candidate {
	return []Candidate{
		{
			Action:    "NEW",
			Predate:   time.Date(2026, 6, 17, 12, 34, 56, 0, time.UTC),
			Title:     "New.Movie.2026-GRP",
			Source:    "#a.b.movies",
			Category:  "MOVIE",
			RequestID: 44,
			GroupName: "alt.binaries.movies",
			Size:      "8 GB",
			Files:     "10F",
			Filename:  "new.r00",
		},
		{
			Action:     "NUK",
			Predate:    time.Date(2026, 6, 17, 13, 0, 0, 0, time.UTC),
			Title:      "Existing.Movie.2025-GRP",
			Source:     "srrdb",
			Category:   "NEW-CAT",
			RequestID:  45,
			GroupName:  "alt.binaries.tv",
			Size:       "7 GB",
			Files:      "2F",
			Filename:   "existing.r00",
			NukeStatus: PreNuked,
			NukeReason: "bad.pack",
		},
	}
}

func ircTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) string {
	t.Helper()

	var value string
	if err := db.QueryRowContext(ctx, "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, title, source, COALESCE(category, ''), COALESCE(size, ''), requestid, groups_id, nuked, COALESCE(nukereason, ''), COALESCE(files, ''), filename) ORDER BY id SEPARATOR '|'), '') FROM predb").Scan(&value); err != nil {
		t.Fatalf("fingerprint predb: %v", err)
	}

	return value
}
