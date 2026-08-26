package metadata

import (
	"context"
	"database/sql"
	"os"
	"reflect"
	"testing"

	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestQueryFromFileNameMatchesPHPCandidateRules(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name string
		want string
	}{
		{
			name: "Movie.Name.2026.1080p.BluRay.x264-GRP.r00",
			want: "Movie Name 2026 1080p BluRay x264 GRP",
		},
		{
			name: `folder\Show.Name.2025.S01E02.WEB.x265-GRP.part01.rar`,
			want: "Show Name 2025 S01E02 WEB x265 GRP",
		},
		{
			name: "abcdefghijklmnopqrstuvwxyz1234567890.rar",
			want: "",
		},
		{
			name: "Short.rar",
			want: "",
		},
		{
			name: "Readable.Release.Without.Year.rar",
			want: "",
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()

			if got := QueryFromFileName(test.name); got != test.want {
				t.Fatalf("QueryFromFileName(%q) = %q, want %q", test.name, got, test.want)
			}
		})
	}
}

func TestBuildRefreshDryRunPlanSelectsMetadataRefreshCandidatesFromMariaDB(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native metadata integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetMetadataTables(t, ctx, db)
	seedMetadataRefreshRows(t, ctx, db)

	fingerprintBefore := tableFingerprint(t, ctx, db)

	plan, err := BuildRefreshDryRunPlan(ctx, db, 2)
	if err != nil {
		t.Fatalf("BuildRefreshDryRunPlan: %v", err)
	}

	if got := plan.SrrdbTitleCandidates; !reflect.DeepEqual(got, []PredbTitleCandidate{
		{ID: 3, Title: "Another.Movie.2026.2160p.WEB.x265-GRP"},
		{ID: 2, Title: "Movie.Name.2026.1080p.BluRay.x264-GRP"},
	}) {
		t.Fatalf("SrrdbTitleCandidates = %#v", got)
	}

	if got := plan.ArchiveCRCCandidates; !reflect.DeepEqual(got, []ArchiveCRCCandidate{
		{Title: "Movie Name 2026 1080p BluRay x264 GRP", CRC: "AABBCCDD", Size: 15000000},
	}) {
		t.Fatalf("ArchiveCRCCandidates = %#v", got)
	}

	if got := plan.SearchQueries; !reflect.DeepEqual(got, []string{
		"Another Movie 2026 2160p WEB x265 GRP",
		"Movie Name 2026 1080p BluRay x264 GRP",
	}) {
		t.Fatalf("SearchQueries = %#v", got)
	}

	if fingerprintAfter := tableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRehearseMetadataRefreshWritesRollsBackRepresentativePredbInserts(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native metadata integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetMetadataTables(t, ctx, db)
	seedMetadataRefreshRows(t, ctx, db)

	plan, err := BuildRefreshDryRunPlan(ctx, db, 2)
	if err != nil {
		t.Fatalf("BuildRefreshDryRunPlan: %v", err)
	}
	plan.SrrdbTitleDetails = srrdbTitleDetailsFixture()
	plan.ArchiveCRCHits = srrdbArchiveHitsFixture()
	plan.SearchProviderHits = searchProviderHitsFixture()
	fingerprintBefore := tableFingerprint(t, ctx, db)

	rehearsal, err := RehearseMetadataRefreshWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("RehearseMetadataRefreshWrites: %v", err)
	}

	if rehearsal.SrrdbTitleCandidates != 2 {
		t.Fatalf("SrrdbTitleCandidates = %d, want 2", rehearsal.SrrdbTitleCandidates)
	}
	if rehearsal.ArchiveCRCCandidates != 1 {
		t.Fatalf("ArchiveCRCCandidates = %d, want 1", rehearsal.ArchiveCRCCandidates)
	}
	if rehearsal.SearchQueries != 2 {
		t.Fatalf("SearchQueries = %d, want 2", rehearsal.SearchQueries)
	}
	if rehearsal.SrrdbDetailsQueried != 2 || rehearsal.SrrdbDetailsFound != 2 || rehearsal.SrrdbDetailsFailed != 0 || rehearsal.SrrdbDetailFiles != 2 {
		t.Fatalf("srrdb details = %#v", rehearsal)
	}
	if rehearsal.SrrdbArchiveQueried != 1 || rehearsal.SrrdbArchiveFound != 1 || rehearsal.SrrdbArchiveFailed != 0 || rehearsal.SrrdbArchiveHits != 1 {
		t.Fatalf("srrdb archive = %#v", rehearsal)
	}
	if rehearsal.SearchProviderHits != 2 {
		t.Fatalf("search provider hits = %d, want 2", rehearsal.SearchProviderHits)
	}
	if rehearsal.PredbRowsAttempted != 3 || rehearsal.PredbRowsAffected != 3 {
		t.Fatalf("predb rows = attempted:%d affected:%d, want 3/3", rehearsal.PredbRowsAttempted, rehearsal.PredbRowsAffected)
	}
	if rehearsal.PredbCRCRowsAttempted != 3 || rehearsal.PredbCRCRowsAffected != 3 {
		t.Fatalf("predb crc rows = attempted:%d affected:%d, want 3/3", rehearsal.PredbCRCRowsAttempted, rehearsal.PredbCRCRowsAffected)
	}
	if rehearsal.SearchUpdatesEnqueued != 3 {
		t.Fatalf("search updates enqueued = %d, want 3", rehearsal.SearchUpdatesEnqueued)
	}
	if !rehearsal.RolledBack {
		t.Fatal("RolledBack = false, want true")
	}
	if rehearsal.WritesCommitted != 0 {
		t.Fatalf("WritesCommitted = %d, want 0", rehearsal.WritesCommitted)
	}

	if fingerprintAfter := tableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitMetadataRefreshWritesCommitsRepresentativePredbInserts(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native metadata integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetMetadataTables(t, ctx, db)
	seedMetadataRefreshRows(t, ctx, db)

	plan, err := BuildRefreshDryRunPlan(ctx, db, 2)
	if err != nil {
		t.Fatalf("BuildRefreshDryRunPlan: %v", err)
	}
	plan.SrrdbTitleDetails = srrdbTitleDetailsFixture()
	plan.ArchiveCRCHits = srrdbArchiveHitsFixture()
	plan.SearchProviderHits = searchProviderHitsFixture()
	fingerprintBefore := tableFingerprint(t, ctx, db)

	commit, err := CommitMetadataRefreshWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("CommitMetadataRefreshWrites: %v", err)
	}

	if commit.SrrdbTitleCandidates != 2 {
		t.Fatalf("SrrdbTitleCandidates = %d, want 2", commit.SrrdbTitleCandidates)
	}
	if commit.ArchiveCRCCandidates != 1 {
		t.Fatalf("ArchiveCRCCandidates = %d, want 1", commit.ArchiveCRCCandidates)
	}
	if commit.SearchQueries != 2 {
		t.Fatalf("SearchQueries = %d, want 2", commit.SearchQueries)
	}
	if commit.SrrdbDetailsQueried != 2 || commit.SrrdbDetailsFound != 2 || commit.SrrdbDetailsFailed != 0 || commit.SrrdbDetailFiles != 2 {
		t.Fatalf("srrdb details = %#v", commit)
	}
	if commit.SrrdbArchiveQueried != 1 || commit.SrrdbArchiveFound != 1 || commit.SrrdbArchiveFailed != 0 || commit.SrrdbArchiveHits != 1 {
		t.Fatalf("srrdb archive = %#v", commit)
	}
	if commit.SearchProviderHits != 2 {
		t.Fatalf("search provider hits = %d, want 2", commit.SearchProviderHits)
	}
	if commit.PredbRowsAttempted != 3 || commit.PredbRowsAffected != 3 {
		t.Fatalf("predb rows = attempted:%d affected:%d, want 3/3", commit.PredbRowsAttempted, commit.PredbRowsAffected)
	}
	if commit.PredbCRCRowsAttempted != 3 || commit.PredbCRCRowsAffected != 3 {
		t.Fatalf("predb crc rows = attempted:%d affected:%d, want 3/3", commit.PredbCRCRowsAttempted, commit.PredbCRCRowsAffected)
	}
	if commit.SearchUpdatesEnqueued != 3 {
		t.Fatalf("search updates enqueued = %d, want 3", commit.SearchUpdatesEnqueued)
	}
	if commit.RolledBack {
		t.Fatal("RolledBack = true, want committed writes")
	}
	if commit.WritesCommitted != 9 {
		t.Fatalf("WritesCommitted = %d, want 9", commit.WritesCommitted)
	}

	if fingerprintAfter := tableFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}

	var syntheticRows int
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM predb WHERE title LIKE 'native-metadata-rehearsal-%'").Scan(&syntheticRows); err != nil {
		t.Fatalf("count synthetic metadata rows: %v", err)
	}
	if syntheticRows != 0 {
		t.Fatalf("synthetic metadata rows = %d, want 0", syntheticRows)
	}

	var source string
	if err := db.QueryRowContext(ctx, "SELECT source FROM predb WHERE title = ?", "Provider.Movie.2026.1080p.BluRay.x264-GRP").Scan(&source); err != nil {
		t.Fatalf("select archive-derived predb source: %v", err)
	}
	if source != "srrdb" {
		t.Fatalf("archive-derived predb source = %q, want srrdb", source)
	}

	var archiveCRCs int
	if err := db.QueryRowContext(ctx, `
		SELECT COUNT(*)
		FROM predb_crcs c
		JOIN predb p ON p.id = c.predb_id
		WHERE p.title = 'Provider.Movie.2026.1080p.BluRay.x264-GRP'
		  AND c.crchash = 'AABBCCDD'
		  AND c.filesize = 15000000`).Scan(&archiveCRCs); err != nil {
		t.Fatalf("count provider-derived archive crc rows: %v", err)
	}
	if archiveCRCs != 1 {
		t.Fatalf("provider-derived archive crc rows = %d, want 1", archiveCRCs)
	}

	var searchProviderRows int
	if err := db.QueryRowContext(ctx, `
		SELECT COUNT(*)
		FROM predb
		WHERE (title = 'PredbNet.Movie.2026.1080p-GRP' AND source = 'predb-net')
		   OR (title = 'Xrel.Another.Movie.2026.2160p-GRP' AND source = 'xrel')`).Scan(&searchProviderRows); err != nil {
		t.Fatalf("count provider-derived search rows: %v", err)
	}
	if searchProviderRows != 2 {
		t.Fatalf("provider-derived search rows = %d, want 2", searchProviderRows)
	}

	var placeholderRows int
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM predb WHERE source = 'native-search-query'").Scan(&placeholderRows); err != nil {
		t.Fatalf("count native-search-query rows: %v", err)
	}
	if placeholderRows != 0 {
		t.Fatalf("native-search-query rows = %d, want 0", placeholderRows)
	}

	var sideEffects int
	if err := db.QueryRowContext(ctx, `
		SELECT COUNT(*)
		FROM native_worker_side_effects
		WHERE job = 'metadata-refresh'
		  AND effect = 'predb-search-sync'
		  AND status_column = 'predb_id'
		  AND status_reason = 'predb-import'
		  AND status_value = 1
		  AND status = 'pending'`).Scan(&sideEffects); err != nil {
		t.Fatalf("count metadata predb search side effects: %v", err)
	}
	if sideEffects != 3 {
		t.Fatalf("metadata predb search side effects = %d, want 3", sideEffects)
	}

	var srrdbCRCs int
	if err := db.QueryRowContext(ctx, `
		SELECT COUNT(*)
		FROM predb_crcs
		WHERE (predb_id = 2 AND crchash = '1111AAAA' AND filesize = 101)
		   OR (predb_id = 3 AND crchash = '2222BBBB' AND filesize = 202)`).Scan(&srrdbCRCs); err != nil {
		t.Fatalf("count provider-derived srrdb crcs: %v", err)
	}
	if srrdbCRCs != 2 {
		t.Fatalf("provider-derived srrdb crc rows = %d, want 2", srrdbCRCs)
	}
}

func srrdbTitleDetailsFixture() map[int64]SrrdbTitleDetails {
	return map[int64]SrrdbTitleDetails{
		2: {
			Files: []SrrdbFile{
				{Name: "movie.r00", CRC: "1111AAAA", Size: 101},
				{Name: "movie-dupe.r00", CRC: "1111AAAA", Size: 101},
				{Name: "movie-bad.r00", CRC: "not-crc", Size: 101},
			},
		},
		3: {
			Files: []SrrdbFile{
				{Name: "another.r00", CRC: "2222BBBB", Size: 202},
			},
		},
	}
}

func srrdbArchiveHitsFixture() map[string][]SrrdbArchiveHit {
	return map[string][]SrrdbArchiveHit{
		"AABBCCDD#15000000": {
			{Title: "Provider.Movie.2026.1080p.BluRay.x264-GRP"},
			{Title: "Provider.Movie.2026.1080p.BluRay.x264-GRP"},
			{Title: ""},
		},
	}
}

func searchProviderHitsFixture() map[string][]SearchProviderHit {
	return map[string][]SearchProviderHit{
		SearchProviderKey("predb-net", "Movie Name 2026 1080p BluRay x264 GRP"): {
			{Source: "predb-net", Title: "PredbNet.Movie.2026.1080p-GRP"},
			{Source: "predb-net", Title: "PredbNet.Movie.2026.1080p-GRP"},
		},
		SearchProviderKey("xrel", "Another Movie 2026 2160p WEB x265 GRP"): {
			{Source: "xrel", Title: "Xrel.Another.Movie.2026.2160p-GRP"},
		},
	}
}

func resetMetadataTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS native_worker_side_effects",
		"DROP TABLE IF EXISTS predb_crcs",
		"DROP TABLE IF EXISTS predb",
		"DROP TABLE IF EXISTS release_files",
		`CREATE TABLE predb (
			id INT AUTO_INCREMENT PRIMARY KEY,
			title VARCHAR(255) NOT NULL UNIQUE,
			source VARCHAR(64) NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE predb_crcs (
			id INT AUTO_INCREMENT PRIMARY KEY,
			predb_id INT NOT NULL,
			crchash VARCHAR(8) NOT NULL,
			filesize BIGINT NOT NULL DEFAULT 0,
			UNIQUE KEY predb_crc_unique (predb_id, crchash, filesize)
		)`,
		`CREATE TABLE release_files (
			id INT AUTO_INCREMENT PRIMARY KEY,
			releases_id INT NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			size BIGINT NOT NULL DEFAULT 0,
			crc32 VARCHAR(8) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL
		)`,
		`CREATE TABLE native_worker_side_effects (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			operation_key VARCHAR(191) NOT NULL UNIQUE,
			job VARCHAR(64) NOT NULL,
			effect VARCHAR(64) NOT NULL,
			release_id BIGINT UNSIGNED NOT NULL,
			status_column VARCHAR(32) NOT NULL,
			status_reason VARCHAR(64) NOT NULL,
			status_value TINYINT UNSIGNED NOT NULL,
			payload_text VARCHAR(255) NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			available_at TIMESTAMP NULL,
			processed_at TIMESTAMP NULL,
			last_error_code VARCHAR(64) NULL,
			created_at TIMESTAMP NULL,
			updated_at TIMESTAMP NULL,
			INDEX ix_native_worker_side_effects_status_available (status, available_at, id),
			INDEX ix_native_worker_side_effects_release_status (release_id, status),
			INDEX ix_native_worker_side_effects_job_effect_status (job, effect, status)
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func acquireMetadataIntegrationLock(t *testing.T, ctx context.Context, db *sql.DB) func() {
	t.Helper()

	conn, err := db.Conn(ctx)
	if err != nil {
		t.Fatalf("open mysql lock connection: %v", err)
	}

	var acquired int
	if err := conn.QueryRowContext(ctx, "SELECT GET_LOCK('nntmux_native_integration_schema_test', 30)").Scan(&acquired); err != nil {
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

		if _, err := conn.ExecContext(ctx, "SELECT RELEASE_LOCK('nntmux_native_integration_schema_test')"); err != nil {
			t.Fatalf("release mysql integration lock: %v", err)
		}
	}
}

func seedMetadataRefreshRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO predb (id, title, source) VALUES
			(1, 'Already.Has.CRC.2026.1080p.BluRay.x264-GRP', 'srrdb'),
			(2, 'Movie.Name.2026.1080p.BluRay.x264-GRP', 'srrdb'),
			(3, 'Another.Movie.2026.2160p.WEB.x265-GRP', 'srrdb'),
			(4, 'Other.Source.2026.1080p.WEB.x264-GRP', 'predb-net')`,
		`INSERT INTO predb_crcs (predb_id, crchash, filesize) VALUES
			(1, 'EEFF0011', 10),
			(4, 'DDCCBBAA', 15000000)`,
		`INSERT INTO release_files (releases_id, name, size, crc32, created_at) VALUES
			(10, 'Movie.Name.2026.1080p.BluRay.x264-GRP.r00', 15000000, 'aabbccdd', '2026-06-15 12:00:00'),
			(11, 'Movie.Name.2026.1080p.BluRay.x264-GRP.r01', 15000000, 'AABBCCDD', '2026-06-15 12:00:01'),
			(12, 'Another.Movie.2026.2160p.WEB.x265-GRP.part01.rar', 33000000, 'bad-crc', '2026-06-15 12:00:02'),
			(13, 'Existing.CRC.No.Signal-GRP.r00', 15000000, 'DDCCBBAA', '2026-06-15 12:00:03'),
			(14, 'abcdefghijklmnopqrstuvwxyz1234567890.rar', 0, '12345678', '2026-06-15 12:00:04')`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func tableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"predb":         "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, title, source) ORDER BY id SEPARATOR '|'), '') FROM predb",
		"predb_crcs":    "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, predb_id, crchash, filesize) ORDER BY id SEPARATOR '|'), '') FROM predb_crcs",
		"release_files": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, releases_id, name, size, crc32, created_at) ORDER BY id SEPARATOR '|'), '') FROM release_files",
		"side_effects":  "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', operation_key, job, effect, release_id, status_column, status_reason, status_value, status, attempts, COALESCE(last_error_code, '')) ORDER BY id SEPARATOR '|'), '') FROM native_worker_side_effects",
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
