package searchdoc

import (
	"context"
	"database/sql"
	"encoding/json"
	"os"
	"strings"
	"testing"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

func TestBuildPendingOutboxParityReportHydratesFingerprintsOnly(t *testing.T) {
	dsn := nativeTestDSN(t)
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	if err := resetSearchDocumentTables(ctx, db); err != nil {
		t.Fatal(err)
	}
	if err := seedSearchDocumentRows(ctx, db); err != nil {
		t.Fatal(err)
	}

	report, err := BuildPendingOutboxParityReport(ctx, db, Options{
		Limit: 10,
		Now:   time.Date(2026, 6, 15, 11, 0, 0, 0, time.UTC),
	})
	if err != nil {
		t.Fatalf("BuildPendingOutboxParityReport returned error: %v", err)
	}

	if report.Mode != "native-search-document-parity" || !report.DryRun {
		t.Fatalf("unexpected report mode: %#v", report)
	}
	if report.SearchDocumentsSeen != 1 {
		t.Fatalf("search_documents_seen = %d", report.SearchDocumentsSeen)
	}
	if len(report.ReleaseDocuments) != 1 {
		t.Fatalf("release_documents = %#v", report.ReleaseDocuments)
	}
	if report.ReleaseDocuments[0].ReleaseID != 91001 {
		t.Fatalf("release_id = %d", report.ReleaseDocuments[0].ReleaseID)
	}
	if len(report.ReleaseDocuments[0].Fingerprint) != 64 {
		t.Fatalf("fingerprint = %q", report.ReleaseDocuments[0].Fingerprint)
	}
	if report.Writes != 0 {
		t.Fatalf("writes = %d", report.Writes)
	}

	encoded, err := json.Marshal(report)
	if err != nil {
		t.Fatalf("marshal report: %v", err)
	}
	for _, leaked := range []string{
		"Resolved.Release.2026.1080p-GRP",
		"native-worker@example.invalid",
		"resolved.sample.mkv",
		"nntmux:nntmux",
	} {
		if strings.Contains(string(encoded), leaked) {
			t.Fatalf("report leaked %q: %s", leaked, encoded)
		}
	}
}

func nativeTestDSN(t testing.TB) string {
	t.Helper()

	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native searchdoc integration tests")
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

func resetSearchDocumentTables(ctx context.Context, db *sql.DB) error {
	statements := []string{
		"DROP TABLE IF EXISTS native_worker_side_effects",
		"DROP TABLE IF EXISTS release_files",
		"DROP TABLE IF EXISTS releases",
		"DROP TABLE IF EXISTS movieinfo",
		"DROP TABLE IF EXISTS videos",
		`CREATE TABLE native_worker_side_effects (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			operation_key VARCHAR(191) NOT NULL UNIQUE,
			job VARCHAR(64) NOT NULL,
			effect VARCHAR(64) NOT NULL,
			release_id BIGINT UNSIGNED NOT NULL,
			status_column VARCHAR(32) NOT NULL,
			status_reason VARCHAR(64) NOT NULL,
			status_value TINYINT UNSIGNED NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			available_at TIMESTAMP NULL,
			processed_at TIMESTAMP NULL,
			last_error_code VARCHAR(64) NULL,
			created_at TIMESTAMP NULL,
			updated_at TIMESTAMP NULL,
			INDEX ix_native_worker_side_effects_status_available (status, available_at, id)
		)`,
		`CREATE TABLE movieinfo (
			id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			tmdbid INT UNSIGNED NOT NULL DEFAULT 0,
			traktid INT UNSIGNED NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE videos (
			id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			tvdb INT UNSIGNED NOT NULL DEFAULT 0,
			tvmaze INT UNSIGNED NOT NULL DEFAULT 0,
			tvrage INT UNSIGNED NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE releases (
			id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			name VARCHAR(255) NOT NULL,
			searchname VARCHAR(255) NOT NULL,
			fromname VARCHAR(255) NULL,
			categories_id INT UNSIGNED NOT NULL DEFAULT 0,
			size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			postdate DATETIME NULL,
			adddate DATETIME NULL,
			totalpart INT NOT NULL DEFAULT 0,
			grabs INT NOT NULL DEFAULT 0,
			passwordstatus INT NOT NULL DEFAULT 0,
			groups_id INT UNSIGNED NOT NULL DEFAULT 0,
			nzbstatus INT NOT NULL DEFAULT 0,
			haspreview INT NOT NULL DEFAULT 0,
			imdbid VARCHAR(32) NOT NULL DEFAULT '',
			videos_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			movieinfo_id BIGINT UNSIGNED NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE release_files (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			releases_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL,
			INDEX ix_release_files_releases_id (releases_id)
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			return err
		}
	}

	return nil
}

func seedSearchDocumentRows(ctx context.Context, db *sql.DB) error {
	statements := []string{
		"INSERT INTO movieinfo (id, tmdbid, traktid) VALUES (5001, 7001, 8001)",
		"INSERT INTO videos (id, tvdb, tvmaze, tvrage) VALUES (6001, 9001, 9002, 9003)",
		`INSERT INTO releases (
			id, name, searchname, fromname, categories_id, size, postdate, adddate,
			totalpart, grabs, passwordstatus, groups_id, nzbstatus, haspreview,
			imdbid, videos_id, movieinfo_id
		) VALUES (
			91001, 'Resolved.Release.2026.1080p-GRP', 'Resolved.Release.2026.1080p-GRP',
			'native-worker@example.invalid', 5040, 12345678, '2026-06-15 09:00:00',
			'2026-06-15 10:00:00', 42, 3, 0, 101, 1, 0, 'tt7654321', 6001, 5001
		)`,
		"INSERT INTO release_files (releases_id, name) VALUES (91001, 'resolved.sample.mkv'), (91001, 'resolved.sample.nfo')",
		`INSERT INTO native_worker_side_effects (
			operation_key, job, effect, release_id, status_column, status_reason,
			status_value, status, attempts, available_at, created_at, updated_at
		) VALUES (
			'hashed-fixnames:miss-status:v1:91001:proc_crc32:1:crc-miss',
			'hashed-fixnames', 'release-search-sync', 91001, 'proc_crc32',
			'crc-miss', 1, 'pending', 0, NULL, NOW(), NOW()
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			return err
		}
	}

	return nil
}
