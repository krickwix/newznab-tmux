package main

import (
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"html"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"

	workerlock "nntmux-native/internal/lock"
	"nntmux-native/internal/safety"
	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
	"github.com/redis/go-redis/v9"
)

func TestRunPrintsMetadataRefreshMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerMetadataTables(t, ctx, db)
	seedWorkerMetadataRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/metadata-refresh-plan.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"native worker dry-run",
		"job=metadata-refresh",
		"metadata-refresh mysql dry-run",
		"srrdb-title-candidates=1",
		"archive-crc-candidates=1",
		"search-queries=1",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}
}

func TestRunPrintsMetadataRefreshJSONReport(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerMetadataTables(t, ctx, db)
	seedWorkerMetadataRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/metadata-refresh-plan.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "metadata-refresh mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}

	var report struct {
		MetadataRefresh struct {
			SrrdbTitleCandidates int `json:"srrdb_title_candidates"`
			ArchiveCRCCandidates int `json:"archive_crc_candidates"`
			SearchQueries        int `json:"search_queries"`
			Writes               int `json:"writes"`
		} `json:"metadata_refresh"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.MetadataRefresh.SrrdbTitleCandidates != 1 {
		t.Fatalf("metadata_refresh.srrdb_title_candidates = %d", report.MetadataRefresh.SrrdbTitleCandidates)
	}
	if report.MetadataRefresh.ArchiveCRCCandidates != 1 {
		t.Fatalf("metadata_refresh.archive_crc_candidates = %d", report.MetadataRefresh.ArchiveCRCCandidates)
	}
	if report.MetadataRefresh.SearchQueries != 1 {
		t.Fatalf("metadata_refresh.search_queries = %d", report.MetadataRefresh.SearchQueries)
	}
	if report.MetadataRefresh.Writes != 0 {
		t.Fatalf("metadata_refresh.writes = %d, want 0", report.MetadataRefresh.Writes)
	}
}

func TestRunPrintsMetadataRefreshJSONReportWithWriteRehearsal(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerMetadataTables(t, ctx, db)
	seedWorkerMetadataRows(t, ctx, db)
	fingerprintBefore := workerMetadataTableFingerprint(t, ctx, db)
	srrdbServer := newWorkerSrrdbDetailsServer(t)
	t.Setenv("NNTMUX_SRRDB_BASE_URL", srrdbServer.URL)
	t.Setenv("NNTMUX_PREDB_NET_BASE_URL", srrdbServer.URL)
	t.Setenv("NNTMUX_PREDB_OVH_BASE_URL", srrdbServer.URL)
	t.Setenv("NNTMUX_XREL_BASE_URL", srrdbServer.URL)
	t.Setenv("NNTMUX_METADATA_SOURCE_NZBINDEX", "true")
	t.Setenv("NNTMUX_NZBINDEX_BASE_URL", srrdbServer.URL)
	t.Setenv("NNTMUX_NZBINDEX_API_KEY", "nzbindex-secret")
	t.Setenv("NNTMUX_METADATA_SOURCE_IA_PREDB", "true")
	t.Setenv("NNTMUX_METADATA_REFRESH_TIMEOUT", "2")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/metadata-refresh-plan.json",
		"--dry-run",
		"--rehearse-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	var report struct {
		MetadataRefreshPreviewSourceFetch struct {
			Queries     int `json:"queries"`
			Queried     int `json:"queried"`
			Found       int `json:"found"`
			Hits        int `json:"hits"`
			Skipped     int `json:"skipped"`
			BulkSkipped int `json:"bulk_skipped"`
		} `json:"metadata_refresh_preview_source_fetch"`
		MetadataRefreshWriteRehearsal struct {
			SrrdbTitleCandidates  int  `json:"srrdb_title_candidates"`
			ArchiveCRCCandidates  int  `json:"archive_crc_candidates"`
			SearchQueries         int  `json:"search_queries"`
			SrrdbDetailsQueried   int  `json:"srrdb_details_queried"`
			SrrdbDetailsFound     int  `json:"srrdb_details_found"`
			SrrdbDetailsFailed    int  `json:"srrdb_details_failed"`
			SrrdbDetailFiles      int  `json:"srrdb_detail_files"`
			SrrdbArchiveQueried   int  `json:"srrdb_archive_queried"`
			SrrdbArchiveFound     int  `json:"srrdb_archive_found"`
			SrrdbArchiveFailed    int  `json:"srrdb_archive_failed"`
			SrrdbArchiveHits      int  `json:"srrdb_archive_hits"`
			SearchProviderHits    int  `json:"search_provider_hits"`
			PredbRowsAttempted    int  `json:"predb_rows_attempted"`
			PredbRowsAffected     int  `json:"predb_rows_affected"`
			PredbCRCRowsAttempted int  `json:"predb_crc_rows_attempted"`
			PredbCRCRowsAffected  int  `json:"predb_crc_rows_affected"`
			SearchUpdatesEnqueued int  `json:"search_updates_enqueued"`
			RolledBack            bool `json:"rolled_back"`
			WritesCommitted       int  `json:"writes_committed"`
		} `json:"metadata_refresh_write_rehearsal"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	preview := report.MetadataRefreshPreviewSourceFetch
	if preview.Queries != 1 || preview.Queried != 1 || preview.Found != 1 || preview.Hits != 2 || preview.Skipped != 1 || preview.BulkSkipped != 1 {
		t.Fatalf("metadata-refresh preview source fetch = %#v", preview)
	}
	rehearsal := report.MetadataRefreshWriteRehearsal
	if rehearsal.SrrdbTitleCandidates != 1 || rehearsal.ArchiveCRCCandidates != 1 || rehearsal.SearchQueries != 1 {
		t.Fatalf("metadata-refresh write rehearsal candidates = %#v", rehearsal)
	}
	if rehearsal.SrrdbDetailsQueried != 1 || rehearsal.SrrdbDetailsFound != 1 || rehearsal.SrrdbDetailsFailed != 0 || rehearsal.SrrdbDetailFiles != 1 {
		t.Fatalf("metadata-refresh srrdb details = %#v", rehearsal)
	}
	if rehearsal.SrrdbArchiveQueried != 1 || rehearsal.SrrdbArchiveFound != 1 || rehearsal.SrrdbArchiveFailed != 0 || rehearsal.SrrdbArchiveHits != 1 {
		t.Fatalf("metadata-refresh srrdb archive = %#v", rehearsal)
	}
	if rehearsal.SearchProviderHits != 4 {
		t.Fatalf("metadata-refresh provider search hits = %#v", rehearsal)
	}
	if rehearsal.PredbRowsAttempted != 5 || rehearsal.PredbRowsAffected != 5 {
		t.Fatalf("metadata-refresh predb rows = %#v", rehearsal)
	}
	if rehearsal.PredbCRCRowsAttempted != 2 || rehearsal.PredbCRCRowsAffected != 2 {
		t.Fatalf("metadata-refresh predb crc rows = %#v", rehearsal)
	}
	if rehearsal.SearchUpdatesEnqueued != 5 {
		t.Fatalf("metadata-refresh search updates enqueued = %#v", rehearsal)
	}
	if !rehearsal.RolledBack || rehearsal.WritesCommitted != 0 {
		t.Fatalf("metadata-refresh write rehearsal rollback = %#v", rehearsal)
	}
	if fingerprintAfter := workerMetadataTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("metadata-refresh rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunCommitsMetadataRefreshWritesWithRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerMetadataTables(t, ctx, db)
	seedWorkerMetadataRows(t, ctx, db)
	fingerprintBefore := workerMetadataTableFingerprint(t, ctx, db)
	srrdbServer := newWorkerSrrdbDetailsServer(t)
	t.Setenv("NNTMUX_SRRDB_BASE_URL", srrdbServer.URL)
	t.Setenv("NNTMUX_PREDB_NET_BASE_URL", srrdbServer.URL)
	t.Setenv("NNTMUX_PREDB_OVH_BASE_URL", srrdbServer.URL)
	t.Setenv("NNTMUX_XREL_BASE_URL", srrdbServer.URL)
	t.Setenv("NNTMUX_METADATA_SOURCE_NZBINDEX", "true")
	t.Setenv("NNTMUX_NZBINDEX_BASE_URL", srrdbServer.URL)
	t.Setenv("NNTMUX_NZBINDEX_API_KEY", "nzbindex-secret")
	t.Setenv("NNTMUX_METADATA_SOURCE_IA_PREDB", "true")
	t.Setenv("NNTMUX_METADATA_REFRESH_TIMEOUT", "2")

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:metadata-refresh"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/metadata-refresh-plan.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "metadata-refresh-commit-proof",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "nzbindex-secret", "Movie.Release.2026", "Provider.Movie.2026", "PredbNet.Movie.2026", "PredbOvh.Movie.2026", "Xrel.Movie.2026", "NzbIndex.Movie.2026", "0123ABCD", "1122AABB", "AABBCCDD", "Obscure"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("commit json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Writes int `json:"writes"`
		} `json:"native_worker"`
		MetadataRefreshPreviewSourceFetch struct {
			Queries     int `json:"queries"`
			Queried     int `json:"queried"`
			Found       int `json:"found"`
			Hits        int `json:"hits"`
			Skipped     int `json:"skipped"`
			BulkSkipped int `json:"bulk_skipped"`
		} `json:"metadata_refresh_preview_source_fetch"`
		MetadataRefreshWriteCommit struct {
			SrrdbTitleCandidates  int  `json:"srrdb_title_candidates"`
			ArchiveCRCCandidates  int  `json:"archive_crc_candidates"`
			SearchQueries         int  `json:"search_queries"`
			SrrdbDetailsQueried   int  `json:"srrdb_details_queried"`
			SrrdbDetailsFound     int  `json:"srrdb_details_found"`
			SrrdbDetailsFailed    int  `json:"srrdb_details_failed"`
			SrrdbDetailFiles      int  `json:"srrdb_detail_files"`
			SrrdbArchiveQueried   int  `json:"srrdb_archive_queried"`
			SrrdbArchiveFound     int  `json:"srrdb_archive_found"`
			SrrdbArchiveFailed    int  `json:"srrdb_archive_failed"`
			SrrdbArchiveHits      int  `json:"srrdb_archive_hits"`
			SearchProviderHits    int  `json:"search_provider_hits"`
			PredbRowsAttempted    int  `json:"predb_rows_attempted"`
			PredbRowsAffected     int  `json:"predb_rows_affected"`
			PredbCRCRowsAttempted int  `json:"predb_crc_rows_attempted"`
			PredbCRCRowsAffected  int  `json:"predb_crc_rows_affected"`
			SearchUpdatesEnqueued int  `json:"search_updates_enqueued"`
			RolledBack            bool `json:"rolled_back"`
			WritesCommitted       int  `json:"writes_committed"`
		} `json:"metadata_refresh_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	preview := report.MetadataRefreshPreviewSourceFetch
	if preview.Queries != 1 || preview.Queried != 1 || preview.Found != 1 || preview.Hits != 2 || preview.Skipped != 1 || preview.BulkSkipped != 1 {
		t.Fatalf("metadata-refresh preview source fetch = %#v", preview)
	}
	commit := report.MetadataRefreshWriteCommit
	if commit.SrrdbTitleCandidates != 1 || commit.ArchiveCRCCandidates != 1 || commit.SearchQueries != 1 {
		t.Fatalf("metadata-refresh write commit candidates = %#v", commit)
	}
	if commit.SrrdbDetailsQueried != 1 || commit.SrrdbDetailsFound != 1 || commit.SrrdbDetailsFailed != 0 || commit.SrrdbDetailFiles != 1 {
		t.Fatalf("metadata-refresh srrdb details = %#v", commit)
	}
	if commit.SrrdbArchiveQueried != 1 || commit.SrrdbArchiveFound != 1 || commit.SrrdbArchiveFailed != 0 || commit.SrrdbArchiveHits != 1 {
		t.Fatalf("metadata-refresh srrdb archive = %#v", commit)
	}
	if commit.SearchProviderHits != 4 {
		t.Fatalf("metadata-refresh provider search hits = %#v", commit)
	}
	if commit.PredbRowsAttempted != 5 || commit.PredbRowsAffected != 5 {
		t.Fatalf("metadata-refresh predb rows = %#v", commit)
	}
	if commit.PredbCRCRowsAttempted != 2 || commit.PredbCRCRowsAffected != 2 {
		t.Fatalf("metadata-refresh predb crc rows = %#v", commit)
	}
	if commit.SearchUpdatesEnqueued != 5 {
		t.Fatalf("metadata-refresh search updates enqueued = %#v", commit)
	}
	if commit.RolledBack {
		t.Fatalf("rolled_back = true, want committed writes")
	}
	if commit.WritesCommitted == 0 || report.NativeWorker.Writes != commit.WritesCommitted {
		t.Fatalf("writes committed = %#v native writes=%d", commit, report.NativeWorker.Writes)
	}
	if commit.WritesCommitted != 12 {
		t.Fatalf("writes_committed = %d, want 12", commit.WritesCommitted)
	}
	if fingerprintAfter := workerMetadataTableFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("metadata-refresh commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
	var syntheticRows int
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM predb WHERE title LIKE 'native-metadata-rehearsal-%'").Scan(&syntheticRows); err != nil {
		t.Fatalf("count synthetic metadata rows: %v", err)
	}
	if syntheticRows != 0 {
		t.Fatalf("synthetic metadata rows = %d, want 0", syntheticRows)
	}
	var providerCRCs int
	if err := db.QueryRowContext(ctx, `
		SELECT COUNT(*)
		FROM predb_crcs
		WHERE predb_id = 1
		  AND crchash = '1122AABB'
		  AND filesize = 123456`).Scan(&providerCRCs); err != nil {
		t.Fatalf("count provider-derived metadata crc rows: %v", err)
	}
	if providerCRCs != 1 {
		t.Fatalf("provider-derived metadata crc rows = %d, want 1", providerCRCs)
	}
	var archiveCRCs int
	if err := db.QueryRowContext(ctx, `
		SELECT COUNT(*)
		FROM predb_crcs c
		JOIN predb p ON p.id = c.predb_id
		WHERE p.title = 'Provider.Movie.2026.1080p.BluRay.x264-GRP'
		  AND p.source = 'srrdb'
		  AND c.crchash = 'AABBCCDD'
		  AND c.filesize = 15000000`).Scan(&archiveCRCs); err != nil {
		t.Fatalf("count provider-derived archive crc rows: %v", err)
	}
	if archiveCRCs != 1 {
		t.Fatalf("provider-derived archive crc rows = %d, want 1", archiveCRCs)
	}
	var providerRows int
	if err := db.QueryRowContext(ctx, `
		SELECT COUNT(*)
		FROM predb
		WHERE (title = 'PredbNet.Movie.2026.1080p-GRP' AND source = 'predb-net')
		   OR (title = 'PredbOvh.Movie.2026.1080p-GRP' AND source = 'predb-ovh')
		   OR (title = 'Xrel.Movie.2026.1080p-GRP' AND source = 'xrel')
		   OR (title = 'XrelP2P.Movie.2026.1080p-GRP' AND source = 'xrel-p2p')`).Scan(&providerRows); err != nil {
		t.Fatalf("count provider-derived search rows: %v", err)
	}
	if providerRows != 4 {
		t.Fatalf("provider-derived search rows = %d, want 4", providerRows)
	}
	var placeholderRows int
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM predb WHERE source = 'native-search-query'").Scan(&placeholderRows); err != nil {
		t.Fatalf("count native search-query placeholders: %v", err)
	}
	if placeholderRows != 0 {
		t.Fatalf("native search-query placeholder rows = %d, want 0", placeholderRows)
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
	if sideEffects != 5 {
		t.Fatalf("metadata predb search side effects = %d, want 5", sideEffects)
	}
}

func TestRunPrintsBinariesMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBinariesTables(t, ctx, db)
	seedWorkerBinariesRows(t, ctx, db)

	fingerprintBefore := workerBinariesTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--dry-run",
		"--mysql-dsn", dsn,
		"--binaries-max-messages", "10000",
		"--binaries-max-headers", "25000",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"native worker dry-run",
		"job=binaries",
		"binaries mysql dry-run",
		"groups=3",
		"queue-entries=6",
		"part-repair=1",
		"ranges=3",
		"header-updates=2",
		"writes=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerBinariesTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("binaries dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsBinariesJSONReport(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBinariesTables(t, ctx, db)
	seedWorkerBinariesRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--binaries-max-messages", "10000",
		"--binaries-max-headers", "25000",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "binaries mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		Binaries struct {
			Groups        int `json:"groups"`
			QueueEntries  int `json:"queue_entries"`
			HeaderUpdates int `json:"header_updates"`
			PartRepair    int `json:"part_repair"`
			Ranges        int `json:"ranges"`
			Writes        int `json:"writes"`
		} `json:"binaries"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "binaries" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.Binaries.Writes != 0 {
		t.Fatalf("writes = native:%d binaries:%d, want 0", report.NativeWorker.Writes, report.Binaries.Writes)
	}
	if report.Binaries.Groups != 3 || report.Binaries.QueueEntries != 6 || report.Binaries.Ranges != 3 {
		t.Fatalf("binaries report = %#v", report.Binaries)
	}
	if report.Binaries.HeaderUpdates != 2 || report.Binaries.PartRepair != 1 {
		t.Fatalf("binaries report = %#v", report.Binaries)
	}
}

func TestRunProbesBinariesNNTPGroups(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBinariesTables(t, ctx, db)
	seedWorkerBinariesRows(t, ctx, db)

	server := newWorkerFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.movies":
			return "211 100000 1 100000 alt.binaries.movies"
		case "GROUP alt.binaries.small":
			return "211 50000 1 50000 alt.binaries.small"
		case "GROUP alt.binaries.new":
			return "211 1000 1 1000 alt.binaries.new"
		case "OVER 1001-1002":
			return "224 overview follows\r\n1001\tMovie.One\tposter@example.test\t17 Jun 2026 10:00:00 +0000\t<1001@example.test>\t\t1234\t45\r\n1002\tMovie.Two\tposter@example.test\t17 Jun 2026 10:01:00 +0000\t<1002@example.test>\t\t1235\t46\r\n."
		case "OVER 11001-11002":
			return "224 overview follows\r\n11001\tMovie.Three\tposter@example.test\t17 Jun 2026 10:02:00 +0000\t<11001@example.test>\t\t1236\t47\r\n11002\tMovie.Four\tposter@example.test\t17 Jun 2026 10:03:00 +0000\t<11002@example.test>\t\t1237\t48\r\n."
		case "OVER 21001-21002":
			return "224 overview follows\r\n21001\tMovie.Five\tposter@example.test\t17 Jun 2026 10:04:00 +0000\t<21001@example.test>\t\t1238\t49\r\n21002\tMovie.Six\tposter@example.test\t17 Jun 2026 10:05:00 +0000\t<21002@example.test>\t\t1239\t50\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	t.Setenv("NNTP_SERVER", host)
	t.Setenv("NNTP_PORT", port)
	t.Setenv("NNTP_USERNAME", "")
	t.Setenv("NNTP_PASSWORD", "")
	t.Setenv("NNTP_SSLENABLED", "false")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--binaries-max-messages", "10000",
		"--binaries-max-headers", "25000",
		"--nntp-probe",
		"--nntp-overview-sample", "2",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", host, port, "alt.binaries.movies", "alt.binaries.small", "alt.binaries.new", "Movie.One", "<1001@example.test>"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("nntp probe json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NNTPProbe struct {
			Groups      int   `json:"groups"`
			Successful  int   `json:"successful"`
			Failed      int   `json:"failed"`
			TotalCount  int64 `json:"total_count"`
			LowestLow   int64 `json:"lowest_low"`
			HighestHigh int64 `json:"highest_high"`
			Stats       []struct {
				Count int64 `json:"count"`
				Low   int64 `json:"low"`
				High  int64 `json:"high"`
			} `json:"stats"`
		} `json:"nntp_probe"`
		NNTPOverviewSample struct {
			Ranges              int `json:"ranges"`
			Requested           int `json:"requested"`
			Received            int `json:"received"`
			Parsed              int `json:"parsed"`
			Malformed           int `json:"malformed"`
			Bytes               int `json:"bytes"`
			Lines               int `json:"lines"`
			HeaderCandidates    int `json:"header_candidates"`
			PartCandidates      int `json:"part_candidates"`
			UniqueMessageIDs    int `json:"unique_message_ids"`
			DuplicateMessageIDs int `json:"duplicate_message_ids"`
			Failed              int `json:"failed"`
		} `json:"nntp_overview_sample"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.NNTPProbe.Groups != 3 || report.NNTPProbe.Successful != 3 || report.NNTPProbe.Failed != 0 {
		t.Fatalf("nntp probe report = %#v", report.NNTPProbe)
	}
	if report.NNTPProbe.TotalCount == 0 || report.NNTPProbe.LowestLow == 0 || report.NNTPProbe.HighestHigh == 0 || len(report.NNTPProbe.Stats) != 3 {
		t.Fatalf("nntp probe stats = %#v", report.NNTPProbe)
	}
	if report.NNTPOverviewSample.Ranges != 3 || report.NNTPOverviewSample.Requested != 6 || report.NNTPOverviewSample.Received != 6 || report.NNTPOverviewSample.Failed != 0 {
		t.Fatalf("nntp overview sample report = %#v", report.NNTPOverviewSample)
	}
	if report.NNTPOverviewSample.Parsed != 6 || report.NNTPOverviewSample.Malformed != 0 || report.NNTPOverviewSample.Bytes != 7419 || report.NNTPOverviewSample.Lines != 285 {
		t.Fatalf("nntp overview sample aggregates = %#v", report.NNTPOverviewSample)
	}
	if report.NNTPOverviewSample.HeaderCandidates != 6 || report.NNTPOverviewSample.PartCandidates != 6 || report.NNTPOverviewSample.UniqueMessageIDs != 6 || report.NNTPOverviewSample.DuplicateMessageIDs != 0 {
		t.Fatalf("nntp overview sample write contract = %#v", report.NNTPOverviewSample)
	}
}

func TestRunExecutesBinariesNativeLaneQueue(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBinariesTables(t, ctx, db)
	seedWorkerBinariesRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "binaries", "laravel-binaries-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-binaries-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
		"--lane-max-processes", "2",
		"--binaries-max-messages", "10000",
		"--binaries-max-headers", "25000",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=binaries",
		"commands=6",
		"succeeded=6",
		"failed=0",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan binaries:part-repair alt.binaries.movies",
		"artisan articles:get-range binaries alt.binaries.movies 1001 11000",
		"artisan articles:get-range binaries alt.binaries.movies 11001 21000",
		"artisan articles:get-range binaries alt.binaries.movies 21001 26000",
		"artisan group:update-headers alt.binaries.small",
		"artisan group:update-headers alt.binaries.new",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
}

func TestRunLaneRefusesExecutionWithoutHeldRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBinariesTables(t, ctx, db)
	seedWorkerBinariesRows(t, ctx, db)

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
		"--binaries-max-messages", "10000",
		"--binaries-max-headers", "25000",
	}, strings.NewReader(""), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run exit = 0, want held-lock refusal; stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), "--run-lane requires --lock-mode=held") {
		t.Fatalf("stderr = %q, want held-lock requirement", stderr.String())
	}
	if logged := readFakeArtisanLog(t, logPath); logged != "" {
		t.Fatalf("fake artisan log = %q, want no commands before lock validation", logged)
	}
}

func TestRunExecutesFixnamesNativeLaneCommands(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native worker integration tests")
	}

	ctx := context.Background()
	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "fixnames", "laravel-fixnames-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/fixnames.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-fixnames-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=fixnames",
		"commands=15",
		"succeeded=15",
		"failed=0",
		"max-processes=1",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan releases:fix-names 3 --category=other --update --set-status --show",
		"artisan releases:fix-names 6 --category=movies --limit=500 --update --set-status --show",
		"artisan releases:fix-names 8 --category=other --limit=50 --update --set-status --show",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
}

func TestRunExecutesNativeIrcSessionAndPredbWrites(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerIRCTables(t, ctx, db)
	seedWorkerIRCPredbRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "irc", "laravel-irc-owner")
	defer releaseLock()

	server := newWorkerFakeIRCServer(t)
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	t.Setenv("SCRAPE_IRC_SERVER", host)
	t.Setenv("SCRAPE_IRC_PORT", port)
	t.Setenv("SCRAPE_IRC_TLS", "false")
	t.Setenv("SCRAPE_IRC_USERNAME", "nntmuxbot")
	t.Setenv("SCRAPE_IRC_PASSWORD", "")
	t.Setenv("NNTMUX_NATIVE_WORKER_IRC_CHANNEL", "#PreNNTmux")
	t.Setenv("NNTMUX_NATIVE_WORKER_IRC_MAX_CANDIDATES", "2")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/irc.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-irc-owner",
		"--lock-mode", "held",
		"--output", "json",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", host, port, "#PreNNTmux", "New.Movie.2026-GRP", "Existing.Movie.2025-GRP", "password", "redis_key"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("irc run-lane json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Writes int `json:"writes"`
		} `json:"native_worker"`
		NativeLane struct {
			Commands  int `json:"commands"`
			Succeeded int `json:"succeeded"`
			Failed    int `json:"failed"`
			ExitCode  int `json:"exit_code"`
		} `json:"native_lane"`
		IrcSession struct {
			Messages   int  `json:"messages"`
			Candidates int  `json:"candidates"`
			Joins      int  `json:"joins"`
			LoggedIn   bool `json:"logged_in"`
		} `json:"irc_session"`
		IrcWriteCommit struct {
			InsertedRows          int  `json:"inserted_rows"`
			UpdatedRows           int  `json:"updated_rows"`
			SearchUpdatesEnqueued int  `json:"search_updates_enqueued"`
			RolledBack            bool `json:"rolled_back"`
			WritesCommitted       int  `json:"writes_committed"`
		} `json:"irc_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.NativeLane.Commands != 1 || report.NativeLane.Succeeded != 1 || report.NativeLane.Failed != 0 || report.NativeLane.ExitCode != 0 {
		t.Fatalf("native lane = %#v", report.NativeLane)
	}
	if !report.IrcSession.LoggedIn || report.IrcSession.Joins != 1 || report.IrcSession.Messages != 2 || report.IrcSession.Candidates != 2 {
		t.Fatalf("irc session = %#v", report.IrcSession)
	}
	if report.IrcWriteCommit.RolledBack || report.IrcWriteCommit.InsertedRows != 1 || report.IrcWriteCommit.UpdatedRows != 1 || report.IrcWriteCommit.SearchUpdatesEnqueued != 2 || report.IrcWriteCommit.WritesCommitted != 4 || report.NativeWorker.Writes != 4 {
		t.Fatalf("irc write commit = %#v native writes=%d", report.IrcWriteCommit, report.NativeWorker.Writes)
	}

	var insertedGroupID, updatedGroupID int
	if err := db.QueryRowContext(ctx, "SELECT groups_id FROM predb WHERE title = 'New.Movie.2026-GRP'").Scan(&insertedGroupID); err != nil {
		t.Fatalf("read inserted predb group: %v", err)
	}
	if err := db.QueryRowContext(ctx, "SELECT groups_id FROM predb WHERE title = 'Existing.Movie.2025-GRP'").Scan(&updatedGroupID); err != nil {
		t.Fatalf("read updated predb group: %v", err)
	}
	if insertedGroupID != 88 || updatedGroupID != 89 {
		t.Fatalf("groups = inserted:%d updated:%d", insertedGroupID, updatedGroupID)
	}
}

func TestRunPrintsIrcJSONReportWithPredbWriteRehearsal(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	t.Setenv(safety.AllowCommittedTestDBEnv, "1")
	t.Setenv(safety.AllowDestructiveTestDBEnv, "1")
	resetWorkerIRCTables(t, ctx, db)
	seedWorkerIRCPredbRows(t, ctx, db)
	before := workerIRCTableFingerprint(t, ctx, db)

	samplePath := writeWorkerIRCSample(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/irc.json",
		"--dry-run",
		"--rehearse-writes",
		"--mysql-dsn", dsn,
		"--output", "json",
		"--irc-sample", samplePath,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{"New.Movie.2026-GRP", "#PreNNTmux", "redis_key", "password"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		IrcWriteRehearsal struct {
			Candidates            int  `json:"candidates"`
			PredbRowsAffected     int  `json:"predb_rows_affected"`
			InsertedRows          int  `json:"inserted_rows"`
			UpdatedRows           int  `json:"updated_rows"`
			SearchUpdatesEnqueued int  `json:"search_updates_enqueued"`
			RolledBack            bool `json:"rolled_back"`
			WritesCommitted       int  `json:"writes_committed"`
		} `json:"irc_write_rehearsal"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.IrcWriteRehearsal.Candidates != 2 || report.IrcWriteRehearsal.PredbRowsAffected != 2 || report.IrcWriteRehearsal.InsertedRows != 1 || report.IrcWriteRehearsal.UpdatedRows != 1 || report.IrcWriteRehearsal.SearchUpdatesEnqueued != 2 {
		t.Fatalf("irc write rehearsal = %#v", report.IrcWriteRehearsal)
	}
	if !report.IrcWriteRehearsal.RolledBack || report.IrcWriteRehearsal.WritesCommitted != 0 {
		t.Fatalf("irc write rollback = %#v", report.IrcWriteRehearsal)
	}
	after := workerIRCTableFingerprint(t, ctx, db)
	if after != before {
		t.Fatalf("fingerprint after rollback = %q, want %q", after, before)
	}
}

func TestRunCommitsIrcPredbWritesWithRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	t.Setenv(safety.AllowCommittedTestDBEnv, "1")
	t.Setenv(safety.AllowDestructiveTestDBEnv, "1")
	resetWorkerIRCTables(t, ctx, db)
	seedWorkerIRCPredbRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "irc", "laravel-irc-owner")
	defer releaseLock()

	samplePath := writeWorkerIRCSample(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/irc.json",
		"--commit-lane-writes",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-irc-owner",
		"--lock-mode", "held",
		"--output", "json",
		"--irc-sample", samplePath,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	var report struct {
		NativeWorker struct {
			Writes int `json:"writes"`
		} `json:"native_worker"`
		IrcWriteCommit struct {
			InsertedRows          int  `json:"inserted_rows"`
			UpdatedRows           int  `json:"updated_rows"`
			SearchUpdatesEnqueued int  `json:"search_updates_enqueued"`
			RolledBack            bool `json:"rolled_back"`
			WritesCommitted       int  `json:"writes_committed"`
		} `json:"irc_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.IrcWriteCommit.RolledBack || report.IrcWriteCommit.WritesCommitted != 4 || report.NativeWorker.Writes != 4 {
		t.Fatalf("irc commit = %#v native writes=%d", report.IrcWriteCommit, report.NativeWorker.Writes)
	}
	if report.IrcWriteCommit.InsertedRows != 1 || report.IrcWriteCommit.UpdatedRows != 1 || report.IrcWriteCommit.SearchUpdatesEnqueued != 2 {
		t.Fatalf("irc insert/update = %#v", report.IrcWriteCommit)
	}

	var insertedSource string
	var insertedGroupID int
	if err := db.QueryRowContext(ctx, "SELECT source, groups_id FROM predb WHERE title = 'New.Movie.2026-GRP'").Scan(&insertedSource, &insertedGroupID); err != nil {
		t.Fatalf("read inserted predb row: %v", err)
	}
	var updatedReason string
	var updatedGroupID int
	if err := db.QueryRowContext(ctx, "SELECT nukereason, groups_id FROM predb WHERE title = 'Existing.Movie.2025-GRP'").Scan(&updatedReason, &updatedGroupID); err != nil {
		t.Fatalf("read updated predb row: %v", err)
	}
	if insertedSource != "#a.b.movies" || insertedGroupID != 88 || updatedReason != "bad.pack" || updatedGroupID != 89 {
		t.Fatalf("inserted source=%q group=%d updated reason=%q group=%d", insertedSource, insertedGroupID, updatedReason, updatedGroupID)
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

func TestRunExecutesMetadataRefreshNativeLaneCommands(t *testing.T) {
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native worker integration tests")
	}

	ctx := context.Background()
	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "metadata-refresh", "laravel-metadata-refresh-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/metadata-refresh.json",
		"--run-lane",
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-metadata-refresh-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=metadata-refresh",
		"commands=3",
		"succeeded=3",
		"failed=0",
		"max-processes=1",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan predb:refresh-external-metadata --source=all --limit=25 --sleep-ms=250",
		"artisan releases:fix-names 20 --category=hashed --limit=500 --update --set-status --show",
		"artisan releases:fix-names 16 --category=hashed --limit=500 --update --set-status --show",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
}

func TestRunExecutesHashedFixnamesNativeLaneCommands(t *testing.T) {
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native worker integration tests")
	}

	ctx := context.Background()
	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "hashed-fixnames", "laravel-hashed-fixnames-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--run-lane",
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-hashed-fixnames-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=hashed-fixnames",
		"commands=10",
		"succeeded=10",
		"failed=0",
		"max-processes=1",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan releases:fix-names 4 --category=hashed --update --set-status --show",
		"artisan releases:fix-names 16 --category=hashed --update --set-status --show",
		"artisan releases:fix-names 20 --category=hashed --update --set-status --show",
		"artisan releases:fix-names 8 --category=hashed --update --set-status --show",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
}

func TestRunRehearsesBinariesWritesAndRollsBack(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBinariesTables(t, ctx, db)
	seedWorkerBinariesRows(t, ctx, db)
	createWorkerHeaderRehearsalTables(t, ctx, db)
	fingerprintBefore := workerHeaderWriteFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--dry-run",
		"--rehearse-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--binaries-max-messages", "10000",
		"--binaries-max-headers", "25000",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	var report struct {
		BinariesWriteRehearsal struct {
			QueueEntries           int  `json:"queue_entries"`
			CursorUpdatesAttempted int  `json:"cursor_updates_attempted"`
			HeaderRowsAttempted    int  `json:"header_rows_attempted"`
			PartRowsAttempted      int  `json:"part_rows_attempted"`
			RolledBack             bool `json:"rolled_back"`
			WritesCommitted        int  `json:"writes_committed"`
		} `json:"binaries_write_rehearsal"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.BinariesWriteRehearsal.QueueEntries != 6 || report.BinariesWriteRehearsal.CursorUpdatesAttempted != 5 || report.BinariesWriteRehearsal.HeaderRowsAttempted != 3 || report.BinariesWriteRehearsal.PartRowsAttempted != 3 {
		t.Fatalf("binaries write rehearsal = %#v", report.BinariesWriteRehearsal)
	}
	if !report.BinariesWriteRehearsal.RolledBack || report.BinariesWriteRehearsal.WritesCommitted != 0 {
		t.Fatalf("binaries write rehearsal rollback = %#v", report.BinariesWriteRehearsal)
	}
	if fingerprintAfter := workerHeaderWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("binaries rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunRehearsesBinariesOverviewSampleWritesAndRollsBack(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBinariesTables(t, ctx, db)
	seedWorkerBinariesRows(t, ctx, db)
	createWorkerHeaderRehearsalTables(t, ctx, db)
	fingerprintBefore := workerHeaderWriteFingerprint(t, ctx, db)

	server := newWorkerFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.movies":
			return "211 100000 1 100000 alt.binaries.movies"
		case "OVER 1001-1002":
			return "224 overview follows\r\n1001\tMovie.One\tposter@example.test\t17 Jun 2026 10:00:00 +0000\t<1001@example.test>\t\t1234\t45\r\n1002\tMovie.Two\tposter@example.test\t17 Jun 2026 10:01:00 +0000\t<1002@example.test>\t\t1235\t46\r\n."
		case "OVER 11001-11002":
			return "224 overview follows\r\n11001\tMovie.Three\tposter@example.test\t17 Jun 2026 10:02:00 +0000\t<11001@example.test>\t\t1236\t47\r\n11002\tMovie.Four\tposter@example.test\t17 Jun 2026 10:03:00 +0000\t<11002@example.test>\t\t1237\t48\r\n."
		case "OVER 21001-21002":
			return "224 overview follows\r\n21001\tMovie.Five\tposter@example.test\t17 Jun 2026 10:04:00 +0000\t<21001@example.test>\t\t1238\t49\r\n21002\tMovie.Six\tposter@example.test\t17 Jun 2026 10:05:00 +0000\t<21002@example.test>\t\t1239\t50\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	t.Setenv("NNTP_SERVER", host)
	t.Setenv("NNTP_PORT", port)
	t.Setenv("NNTP_USERNAME", "")
	t.Setenv("NNTP_PASSWORD", "")
	t.Setenv("NNTP_SSLENABLED", "false")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--dry-run",
		"--rehearse-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--binaries-max-messages", "10000",
		"--binaries-max-headers", "25000",
		"--nntp-overview-sample", "2",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", host, port, "alt.binaries.movies", "Movie.One", "<1001@example.test>"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("nntp overview rehearsal json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NNTPOverviewSample struct {
			HeaderCandidates int `json:"header_candidates"`
			PartCandidates   int `json:"part_candidates"`
		} `json:"nntp_overview_sample"`
		BinariesWriteRehearsal struct {
			QueueEntries           int   `json:"queue_entries"`
			CursorUpdatesAttempted int   `json:"cursor_updates_attempted"`
			HeaderRowsAttempted    int   `json:"header_rows_attempted"`
			HeaderRowsAffected     int64 `json:"header_rows_affected"`
			PartRowsAttempted      int   `json:"part_rows_attempted"`
			PartRowsAffected       int64 `json:"part_rows_affected"`
			RolledBack             bool  `json:"rolled_back"`
			WritesCommitted        int   `json:"writes_committed"`
		} `json:"binaries_write_rehearsal"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.NNTPOverviewSample.HeaderCandidates != 6 || report.NNTPOverviewSample.PartCandidates != 6 {
		t.Fatalf("nntp overview sample = %#v", report.NNTPOverviewSample)
	}
	if report.BinariesWriteRehearsal.QueueEntries != 6 || report.BinariesWriteRehearsal.CursorUpdatesAttempted != 1 || report.BinariesWriteRehearsal.HeaderRowsAttempted != 6 || report.BinariesWriteRehearsal.PartRowsAttempted != 6 {
		t.Fatalf("binaries overview write rehearsal = %#v", report.BinariesWriteRehearsal)
	}
	if report.BinariesWriteRehearsal.HeaderRowsAffected != 6 || report.BinariesWriteRehearsal.PartRowsAffected != 6 {
		t.Fatalf("binaries overview write rows = %#v", report.BinariesWriteRehearsal)
	}
	if !report.BinariesWriteRehearsal.RolledBack || report.BinariesWriteRehearsal.WritesCommitted != 0 {
		t.Fatalf("binaries overview write rehearsal rollback = %#v", report.BinariesWriteRehearsal)
	}
	if fingerprintAfter := workerHeaderWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("binaries overview rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunRejectsBinariesCommitWithoutOverviewSample(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBinariesTables(t, ctx, db)
	seedWorkerBinariesRows(t, ctx, db)
	createWorkerHeaderRehearsalTables(t, ctx, db)
	fingerprintBefore := workerHeaderWriteFingerprint(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:binaries"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "binaries-commit-proof",
		"--binaries-max-messages", "10000",
		"--binaries-max-headers", "25000",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want config error; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--commit-lane-writes for binaries requires --nntp-overview-sample") {
		t.Fatalf("stderr = %q, want overview sample requirement", stderr.String())
	}
	if stdout.String() != "" {
		t.Fatalf("stdout = %q, want no report for config error", stdout.String())
	}
	if fingerprintAfter := workerHeaderWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("binaries rejected commit changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunCommitsBinariesOverviewSampleWritesWithRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBinariesTables(t, ctx, db)
	seedWorkerBinariesRows(t, ctx, db)
	createWorkerHeaderRehearsalTables(t, ctx, db)
	fingerprintBefore := workerHeaderWriteFingerprint(t, ctx, db)

	server := newWorkerFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP alt.binaries.movies":
			return "211 100000 1 100000 alt.binaries.movies"
		case "OVER 1001-1002":
			return "224 overview follows\r\n1001\tMovie.One\tposter@example.test\t17 Jun 2026 10:00:00 +0000\t<1001@example.test>\t\t1234\t45\r\n1002\tMovie.Two\tposter@example.test\t17 Jun 2026 10:01:00 +0000\t<1002@example.test>\t\t1235\t46\r\n."
		case "OVER 11001-11002":
			return "224 overview follows\r\n11001\tMovie.Three\tposter@example.test\t17 Jun 2026 10:02:00 +0000\t<11001@example.test>\t\t1236\t47\r\n11002\tMovie.Four\tposter@example.test\t17 Jun 2026 10:03:00 +0000\t<11002@example.test>\t\t1237\t48\r\n."
		case "OVER 21001-21002":
			return "224 overview follows\r\n21001\tMovie.Five\tposter@example.test\t17 Jun 2026 10:04:00 +0000\t<21001@example.test>\t\t1238\t49\r\n21002\tMovie.Six\tposter@example.test\t17 Jun 2026 10:05:00 +0000\t<21002@example.test>\t\t1239\t50\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	t.Setenv("NNTP_SERVER", host)
	t.Setenv("NNTP_PORT", port)
	t.Setenv("NNTP_USERNAME", "")
	t.Setenv("NNTP_PASSWORD", "")
	t.Setenv("NNTP_SSLENABLED", "false")

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:binaries"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/binaries.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "binaries-overview-commit-proof",
		"--binaries-max-messages", "10000",
		"--binaries-max-headers", "25000",
		"--nntp-overview-sample", "2",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", host, port, "arguments", "redis_key", "nntmux_database", "alt.binaries.movies", "Movie.One", "<1001@example.test>"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("overview commit json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Writes int `json:"writes"`
		} `json:"native_worker"`
		NNTPOverviewSample struct {
			HeaderCandidates int `json:"header_candidates"`
			PartCandidates   int `json:"part_candidates"`
		} `json:"nntp_overview_sample"`
		BinariesWriteCommit struct {
			QueueEntries           int   `json:"queue_entries"`
			CursorUpdatesAttempted int   `json:"cursor_updates_attempted"`
			HeaderRowsAttempted    int   `json:"header_rows_attempted"`
			HeaderRowsAffected     int64 `json:"header_rows_affected"`
			PartRowsAttempted      int   `json:"part_rows_attempted"`
			PartRowsAffected       int64 `json:"part_rows_affected"`
			RolledBack             bool  `json:"rolled_back"`
			WritesCommitted        int   `json:"writes_committed"`
		} `json:"binaries_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.NNTPOverviewSample.HeaderCandidates != 6 || report.NNTPOverviewSample.PartCandidates != 6 {
		t.Fatalf("nntp overview sample = %#v", report.NNTPOverviewSample)
	}
	commit := report.BinariesWriteCommit
	if commit.QueueEntries != 6 || commit.CursorUpdatesAttempted != 1 || commit.HeaderRowsAttempted != 6 || commit.PartRowsAttempted != 6 {
		t.Fatalf("binaries overview write commit = %#v", commit)
	}
	if commit.HeaderRowsAffected != 6 || commit.PartRowsAffected != 6 || commit.WritesCommitted == 0 {
		t.Fatalf("binaries overview write rows = %#v", commit)
	}
	if commit.RolledBack || report.NativeWorker.Writes != commit.WritesCommitted {
		t.Fatalf("binaries overview write commit rollback = %#v native writes=%d", commit, report.NativeWorker.Writes)
	}
	if fingerprintAfter := workerHeaderWriteFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("binaries overview commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsReleasesMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerReleaseQueueTables(t, ctx, db)
	seedWorkerReleaseQueueRows(t, ctx, db)

	fingerprintBefore := workerReleaseQueueTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/releases.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"native worker dry-run",
		"job=releases",
		"releases mysql dry-run",
		"candidate-groups=3",
		"eligible-groups=2",
		"skipped-no-collections=1",
		"queue-entries=2",
		"max-processes=3",
		"batches=1",
		"writes=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerReleaseQueueTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("releases dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsReleasesJSONReport(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerReleaseQueueTables(t, ctx, db)
	seedWorkerReleaseQueueRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/releases.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "releases mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "alt.binaries.movies", "alt.binaries.backfill", "releases  1", "group_id"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		Releases struct {
			CandidateGroups      int `json:"candidate_groups"`
			EligibleGroups       int `json:"eligible_groups"`
			SkippedNoCollections int `json:"skipped_no_collections"`
			QueueEntries         int `json:"queue_entries"`
			MaxProcesses         int `json:"max_processes"`
			Batches              int `json:"batches"`
			Writes               int `json:"writes"`
		} `json:"releases"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "releases" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.Releases.Writes != 0 {
		t.Fatalf("writes = native:%d releases:%d, want 0", report.NativeWorker.Writes, report.Releases.Writes)
	}
	if report.Releases.CandidateGroups != 3 || report.Releases.EligibleGroups != 2 || report.Releases.SkippedNoCollections != 1 {
		t.Fatalf("releases report = %#v", report.Releases)
	}
	if report.Releases.QueueEntries != 2 || report.Releases.MaxProcesses != 3 || report.Releases.Batches != 1 {
		t.Fatalf("releases report = %#v", report.Releases)
	}
}

func TestRunExecutesReleasesNativeLaneQueue(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerReleaseQueueTables(t, ctx, db)
	seedWorkerReleaseQueueRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "releases", "laravel-releases-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/releases.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-releases-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=releases",
		"commands=2",
		"succeeded=2",
		"failed=0",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan releases:process 1",
		"artisan releases:process 2",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
	if strings.Contains(logged, "releases:process 3") || strings.Contains(logged, "releases:process 4") {
		t.Fatalf("fake artisan log = %q, contains ineligible release groups", logged)
	}
}

func TestRunRehearsesReleasesWritesAndRollsBack(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerReleaseQueueTables(t, ctx, db)
	seedWorkerReleaseQueueRows(t, ctx, db)
	createWorkerReleaseRehearsalTables(t, ctx, db)
	fingerprintBefore := workerReleaseWriteFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/releases.json",
		"--dry-run",
		"--rehearse-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	var report struct {
		ReleasesWriteRehearsal struct {
			QueueEntries            int  `json:"queue_entries"`
			ReleaseRowsAttempted    int  `json:"release_rows_attempted"`
			CollectionRowsAttempted int  `json:"collection_rows_attempted"`
			RolledBack              bool `json:"rolled_back"`
			WritesCommitted         int  `json:"writes_committed"`
		} `json:"releases_write_rehearsal"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.ReleasesWriteRehearsal.QueueEntries != 2 || report.ReleasesWriteRehearsal.ReleaseRowsAttempted != 2 || report.ReleasesWriteRehearsal.CollectionRowsAttempted != 2 {
		t.Fatalf("releases write rehearsal = %#v", report.ReleasesWriteRehearsal)
	}
	if !report.ReleasesWriteRehearsal.RolledBack || report.ReleasesWriteRehearsal.WritesCommitted != 0 {
		t.Fatalf("releases write rehearsal rollback = %#v", report.ReleasesWriteRehearsal)
	}
	if fingerprintAfter := workerReleaseWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("releases rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunCommitsReleasesWritesWithRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerReleaseQueueTables(t, ctx, db)
	seedWorkerReleaseQueueRows(t, ctx, db)
	createWorkerReleaseRehearsalTables(t, ctx, db)
	fingerprintBefore := workerReleaseWriteFingerprint(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:releases"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/releases.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "releases-commit-proof",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "alt.binaries.movies", "alt.binaries.backfill", "releases  1", "group_id"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("commit json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Writes int `json:"writes"`
		} `json:"native_worker"`
		ReleasesWriteCommit struct {
			QueueEntries            int     `json:"queue_entries"`
			ReleaseRowsAttempted    int     `json:"release_rows_attempted"`
			ReleaseRowsAffected     int64   `json:"release_rows_affected"`
			CommittedReleaseIDs     []int64 `json:"committed_release_ids"`
			CollectionRowsAttempted int     `json:"collection_rows_attempted"`
			RolledBack              bool    `json:"rolled_back"`
			WritesCommitted         int     `json:"writes_committed"`
		} `json:"releases_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	commit := report.ReleasesWriteCommit
	if commit.QueueEntries != 2 || commit.ReleaseRowsAttempted != 2 || commit.CollectionRowsAttempted != 2 {
		t.Fatalf("releases write commit = %#v", commit)
	}
	if commit.RolledBack {
		t.Fatalf("rolled_back = true, want committed writes")
	}
	if commit.WritesCommitted == 0 || report.NativeWorker.Writes != commit.WritesCommitted {
		t.Fatalf("writes committed = %#v native writes=%d", commit, report.NativeWorker.Writes)
	}
	if commit.ReleaseRowsAffected != 2 {
		t.Fatalf("release_rows_affected = %d, want 2", commit.ReleaseRowsAffected)
	}
	if want := []int64{1, 2}; !reflect.DeepEqual(commit.CommittedReleaseIDs, want) {
		t.Fatalf("committed_release_ids = %#v, want %#v", commit.CommittedReleaseIDs, want)
	}
	if fingerprintAfter := workerReleaseWriteFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("releases commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsPerGroupMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPerGroupQueueTables(t, ctx, db)
	seedWorkerPerGroupQueueRows(t, ctx, db)

	fingerprintBefore := workerPerGroupQueueTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/per-group.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"native worker dry-run",
		"job=per-group",
		"per-group mysql dry-run",
		"candidate-groups=5",
		"queue-entries=5",
		"max-processes=2",
		"batches=3",
		"writes=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerPerGroupQueueTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("per-group dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsPerGroupJSONReport(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPerGroupQueueTables(t, ctx, db)
	seedWorkerPerGroupQueueRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/per-group.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "per-group mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "alt.binaries.active", "alt.binaries.backfill", "update_per_group", "group_id"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		PerGroup struct {
			CandidateGroups int `json:"candidate_groups"`
			QueueEntries    int `json:"queue_entries"`
			MaxProcesses    int `json:"max_processes"`
			Batches         int `json:"batches"`
			Writes          int `json:"writes"`
		} `json:"per_group"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "per-group" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.PerGroup.Writes != 0 {
		t.Fatalf("writes = native:%d per_group:%d, want 0", report.NativeWorker.Writes, report.PerGroup.Writes)
	}
	if report.PerGroup.CandidateGroups != 5 || report.PerGroup.QueueEntries != 5 || report.PerGroup.MaxProcesses != 2 || report.PerGroup.Batches != 3 {
		t.Fatalf("per_group report = %#v", report.PerGroup)
	}
}

func TestRunCommitsPerGroupWritesWithRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPerGroupQueueTables(t, ctx, db)
	seedWorkerPerGroupQueueRows(t, ctx, db)
	fingerprintBefore := workerPerGroupQueueTableFingerprint(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:per-group"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/per-group.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "per-group-commit-proof",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "alt.binaries.active", "alt.binaries.backfill", "update_per_group", "group_id"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("commit json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Writes int `json:"writes"`
		} `json:"native_worker"`
		PerGroupWriteCommit struct {
			QueueEntries          int  `json:"queue_entries"`
			GroupUpdatesAttempted int  `json:"group_updates_attempted"`
			GroupRowsAffected     int  `json:"group_rows_affected"`
			RolledBack            bool `json:"rolled_back"`
			WritesCommitted       int  `json:"writes_committed"`
		} `json:"per_group_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	commit := report.PerGroupWriteCommit
	if commit.QueueEntries != 5 || commit.GroupUpdatesAttempted != 5 || commit.GroupRowsAffected != 5 {
		t.Fatalf("per-group write commit = %#v", commit)
	}
	if commit.RolledBack {
		t.Fatalf("rolled_back = true, want committed writes")
	}
	if commit.WritesCommitted != 5 || report.NativeWorker.Writes != commit.WritesCommitted {
		t.Fatalf("writes committed = %#v native writes=%d", commit, report.NativeWorker.Writes)
	}
	if fingerprintAfter := workerPerGroupQueueTableFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("per-group commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunExecutesPerGroupNativeLaneQueue(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPerGroupQueueTables(t, ctx, db)
	seedWorkerPerGroupQueueRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "per-group", "laravel-per-group-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/per-group.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-per-group-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=per-group",
		"commands=5",
		"succeeded=5",
		"failed=0",
		"max-processes=2",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan group:update-all 1",
		"artisan group:update-all 2",
		"artisan group:update-all 3",
		"artisan group:update-all 4",
		"artisan group:update-all 5",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
	if strings.Contains(logged, "group:update-all 6") {
		t.Fatalf("fake artisan log = %q, contains inactive per-group lane", logged)
	}
}

func TestRunPrintsBackfillMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBackfillTables(t, ctx, db)
	seedWorkerBackfillRows(t, ctx, db)

	fingerprintBefore := workerBackfillTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/backfill.json",
		"--dry-run",
		"--mysql-dsn", dsn,
		"--backfill-qty", "75000",
		"--backfill-max-messages", "20000",
		"--backfill-threads", "4",
		"--backfill-groups", "10",
		"--backfill-days", "1",
		"--backfill-min-articles", "100",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"native worker dry-run",
		"job=backfill",
		"backfill mysql dry-run",
		"groups=2",
		"queue-entries=4",
		"ranges=4",
		"skipped-invalid=1",
		"skipped-no-work=1",
		"skipped-near-floor=1",
		"writes=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerBackfillTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("backfill dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsBackfillJSONReport(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBackfillTables(t, ctx, db)
	seedWorkerBackfillRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/backfill.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--backfill-qty", "75000",
		"--backfill-max-messages", "20000",
		"--backfill-threads", "4",
		"--backfill-groups", "10",
		"--backfill-days", "1",
		"--backfill-min-articles", "100",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "backfill mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "get_range", "a.b.multimedia.movies"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		Backfill struct {
			Groups           int `json:"groups"`
			QueueEntries     int `json:"queue_entries"`
			Ranges           int `json:"ranges"`
			SkippedInvalid   int `json:"skipped_invalid"`
			SkippedNoWork    int `json:"skipped_no_work"`
			SkippedNearFloor int `json:"skipped_near_floor"`
			Writes           int `json:"writes"`
		} `json:"backfill"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "backfill" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.Backfill.Writes != 0 {
		t.Fatalf("writes = native:%d backfill:%d, want 0", report.NativeWorker.Writes, report.Backfill.Writes)
	}
	if report.Backfill.Groups != 2 || report.Backfill.QueueEntries != 4 || report.Backfill.Ranges != 4 {
		t.Fatalf("backfill report = %#v", report.Backfill)
	}
	if report.Backfill.SkippedInvalid != 1 || report.Backfill.SkippedNoWork != 1 || report.Backfill.SkippedNearFloor != 1 {
		t.Fatalf("backfill report = %#v", report.Backfill)
	}
}

func TestRunProbesBackfillNNTPGroups(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBackfillTables(t, ctx, db)
	seedWorkerBackfillRows(t, ctx, db)

	server := newWorkerFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP a.b.multimedia.movies":
			return "211 200000 1 200000 a.b.multimedia.movies"
		case "GROUP a.b.multimedia.vintage-film":
			return "211 200000 2 200000 a.b.multimedia.vintage-film"
		case "OVER 30000-30001":
			return "224 overview follows\r\n30000\tBackfill.One\tposter@example.test\t17 Jun 2026 11:00:00 +0000\t<30000@example.test>\t\t2234\t55\r\n30001\tBackfill.Two\tposter@example.test\t17 Jun 2026 11:01:00 +0000\t<30001@example.test>\t\t2235\t56\r\n."
		case "OVER 2-3":
			return "224 overview follows\r\n2\tVintage.One\tposter@example.test\t17 Jun 2026 11:02:00 +0000\t<2@example.test>\t\t2236\t57\r\n3\tVintage.Two\tposter@example.test\t17 Jun 2026 11:03:00 +0000\t<3@example.test>\t\t2237\t58\r\n."
		case "OVER 10000-10001":
			return "224 overview follows\r\n10000\tBackfill.Three\tposter@example.test\t17 Jun 2026 11:04:00 +0000\t<10000@example.test>\t\t2238\t59\r\n10001\tBackfill.Four\tposter@example.test\t17 Jun 2026 11:05:00 +0000\t<10001@example.test>\t\t2239\t60\r\n."
		case "OVER 1-2":
			return "224 overview follows\r\n1\tBackfill.Five\tposter@example.test\t17 Jun 2026 11:06:00 +0000\t<1@example.test>\t\t2240\t61\r\n2\tBackfill.Six\tposter@example.test\t17 Jun 2026 11:07:00 +0000\t<2b@example.test>\t\t2241\t62\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	t.Setenv("NNTP_SERVER", host)
	t.Setenv("NNTP_PORT", port)
	t.Setenv("NNTP_USERNAME", "")
	t.Setenv("NNTP_PASSWORD", "")
	t.Setenv("NNTP_SSLENABLED", "false")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/backfill.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--backfill-qty", "75000",
		"--backfill-max-messages", "20000",
		"--backfill-threads", "4",
		"--backfill-groups", "10",
		"--backfill-days", "1",
		"--backfill-min-articles", "100",
		"--nntp-probe",
		"--nntp-overview-sample", "2",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", host, port, "a.b.multimedia.movies", "a.b.multimedia.vintage-film", "Backfill.One", "<30000@example.test>"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("nntp probe json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NNTPProbe struct {
			Groups      int   `json:"groups"`
			Successful  int   `json:"successful"`
			Failed      int   `json:"failed"`
			TotalCount  int64 `json:"total_count"`
			LowestLow   int64 `json:"lowest_low"`
			HighestHigh int64 `json:"highest_high"`
			Stats       []struct {
				Count int64 `json:"count"`
				Low   int64 `json:"low"`
				High  int64 `json:"high"`
			} `json:"stats"`
		} `json:"nntp_probe"`
		NNTPOverviewSample struct {
			Ranges              int `json:"ranges"`
			Requested           int `json:"requested"`
			Received            int `json:"received"`
			Parsed              int `json:"parsed"`
			Malformed           int `json:"malformed"`
			Bytes               int `json:"bytes"`
			Lines               int `json:"lines"`
			HeaderCandidates    int `json:"header_candidates"`
			PartCandidates      int `json:"part_candidates"`
			UniqueMessageIDs    int `json:"unique_message_ids"`
			DuplicateMessageIDs int `json:"duplicate_message_ids"`
			Failed              int `json:"failed"`
		} `json:"nntp_overview_sample"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.NNTPProbe.Groups != 2 || report.NNTPProbe.Successful != 2 || report.NNTPProbe.Failed != 0 {
		t.Fatalf("nntp probe report = %#v", report.NNTPProbe)
	}
	if report.NNTPProbe.TotalCount == 0 || report.NNTPProbe.LowestLow == 0 || report.NNTPProbe.HighestHigh == 0 || len(report.NNTPProbe.Stats) != 2 {
		t.Fatalf("nntp probe stats = %#v", report.NNTPProbe)
	}
	if report.NNTPOverviewSample.Ranges != 4 || report.NNTPOverviewSample.Requested != 8 || report.NNTPOverviewSample.Received != 8 || report.NNTPOverviewSample.Failed != 0 {
		t.Fatalf("nntp overview sample report = %#v", report.NNTPOverviewSample)
	}
	if report.NNTPOverviewSample.Parsed != 8 || report.NNTPOverviewSample.Malformed != 0 || report.NNTPOverviewSample.Bytes != 17900 || report.NNTPOverviewSample.Lines != 468 {
		t.Fatalf("nntp overview sample aggregates = %#v", report.NNTPOverviewSample)
	}
	if report.NNTPOverviewSample.HeaderCandidates != 8 || report.NNTPOverviewSample.PartCandidates != 8 || report.NNTPOverviewSample.UniqueMessageIDs != 8 || report.NNTPOverviewSample.DuplicateMessageIDs != 0 {
		t.Fatalf("nntp overview sample write contract = %#v", report.NNTPOverviewSample)
	}
}

func TestRunExecutesBackfillNativeLaneQueue(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBackfillTables(t, ctx, db)
	seedWorkerBackfillRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "backfill", "laravel-backfill-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/backfill.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-backfill-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
		"--backfill-qty", "75000",
		"--backfill-max-messages", "20000",
		"--backfill-threads", "4",
		"--backfill-groups", "10",
		"--backfill-days", "1",
		"--backfill-min-articles", "100",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=backfill",
		"commands=4",
		"succeeded=4",
		"failed=0",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan articles:get-range backfill a.b.multimedia.movies 30000 49999",
		"artisan articles:get-range backfill a.b.multimedia.vintage-film 2 104",
		"artisan articles:get-range backfill a.b.multimedia.movies 10000 29999",
		"artisan articles:get-range backfill a.b.multimedia.movies 1 9999",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
}

func TestRunRehearsesBackfillWritesAndRollsBack(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBackfillTables(t, ctx, db)
	seedWorkerBackfillRows(t, ctx, db)
	createWorkerHeaderRehearsalTables(t, ctx, db)
	fingerprintBefore := workerHeaderBackfillWriteFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/backfill.json",
		"--dry-run",
		"--rehearse-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--backfill-qty", "75000",
		"--backfill-max-messages", "20000",
		"--backfill-threads", "4",
		"--backfill-groups", "10",
		"--backfill-days", "1",
		"--backfill-min-articles", "100",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	var report struct {
		BackfillWriteRehearsal struct {
			QueueEntries           int  `json:"queue_entries"`
			CursorUpdatesAttempted int  `json:"cursor_updates_attempted"`
			HeaderRowsAttempted    int  `json:"header_rows_attempted"`
			PartRowsAttempted      int  `json:"part_rows_attempted"`
			RolledBack             bool `json:"rolled_back"`
			WritesCommitted        int  `json:"writes_committed"`
		} `json:"backfill_write_rehearsal"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.BackfillWriteRehearsal.QueueEntries != 4 || report.BackfillWriteRehearsal.CursorUpdatesAttempted != 4 || report.BackfillWriteRehearsal.HeaderRowsAttempted != 4 || report.BackfillWriteRehearsal.PartRowsAttempted != 4 {
		t.Fatalf("backfill write rehearsal = %#v", report.BackfillWriteRehearsal)
	}
	if !report.BackfillWriteRehearsal.RolledBack || report.BackfillWriteRehearsal.WritesCommitted != 0 {
		t.Fatalf("backfill write rehearsal rollback = %#v", report.BackfillWriteRehearsal)
	}
	if fingerprintAfter := workerHeaderBackfillWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("backfill rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunRehearsesBackfillOverviewSampleWritesAndRollsBack(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBackfillTables(t, ctx, db)
	seedWorkerBackfillRows(t, ctx, db)
	createWorkerHeaderRehearsalTables(t, ctx, db)
	fingerprintBefore := workerHeaderBackfillWriteFingerprint(t, ctx, db)

	server := newWorkerFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP a.b.multimedia.movies":
			return "211 200000 1 200000 a.b.multimedia.movies"
		case "GROUP a.b.multimedia.vintage-film":
			return "211 200000 2 200000 a.b.multimedia.vintage-film"
		case "OVER 30000-30001":
			return "224 overview follows\r\n30000\tBackfill.One\tposter@example.test\t17 Jun 2026 11:00:00 +0000\t<30000@example.test>\t\t2234\t55\r\n30001\tBackfill.Two\tposter@example.test\t17 Jun 2026 11:01:00 +0000\t<30001@example.test>\t\t2235\t56\r\n."
		case "OVER 2-3":
			return "224 overview follows\r\n2\tVintage.One\tposter@example.test\t17 Jun 2026 11:02:00 +0000\t<2@example.test>\t\t2236\t57\r\n3\tVintage.Two\tposter@example.test\t17 Jun 2026 11:03:00 +0000\t<3@example.test>\t\t2237\t58\r\n."
		case "OVER 10000-10001":
			return "224 overview follows\r\n10000\tBackfill.Three\tposter@example.test\t17 Jun 2026 11:04:00 +0000\t<10000@example.test>\t\t2238\t59\r\n10001\tBackfill.Four\tposter@example.test\t17 Jun 2026 11:05:00 +0000\t<10001@example.test>\t\t2239\t60\r\n."
		case "OVER 1-2":
			return "224 overview follows\r\n1\tBackfill.Five\tposter@example.test\t17 Jun 2026 11:06:00 +0000\t<1@example.test>\t\t2240\t61\r\n2\tBackfill.Six\tposter@example.test\t17 Jun 2026 11:07:00 +0000\t<2b@example.test>\t\t2241\t62\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	t.Setenv("NNTP_SERVER", host)
	t.Setenv("NNTP_PORT", port)
	t.Setenv("NNTP_USERNAME", "")
	t.Setenv("NNTP_PASSWORD", "")
	t.Setenv("NNTP_SSLENABLED", "false")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/backfill.json",
		"--dry-run",
		"--rehearse-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--backfill-qty", "75000",
		"--backfill-max-messages", "20000",
		"--backfill-threads", "4",
		"--backfill-groups", "10",
		"--backfill-days", "1",
		"--backfill-min-articles", "100",
		"--nntp-overview-sample", "2",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", host, port, "a.b.multimedia.movies", "a.b.multimedia.vintage-film", "Backfill.One", "<30000@example.test>"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("nntp overview rehearsal json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NNTPOverviewSample struct {
			HeaderCandidates int `json:"header_candidates"`
			PartCandidates   int `json:"part_candidates"`
		} `json:"nntp_overview_sample"`
		BackfillWriteRehearsal struct {
			QueueEntries           int   `json:"queue_entries"`
			CursorUpdatesAttempted int   `json:"cursor_updates_attempted"`
			HeaderRowsAttempted    int   `json:"header_rows_attempted"`
			HeaderRowsAffected     int64 `json:"header_rows_affected"`
			PartRowsAttempted      int   `json:"part_rows_attempted"`
			PartRowsAffected       int64 `json:"part_rows_affected"`
			RolledBack             bool  `json:"rolled_back"`
			WritesCommitted        int   `json:"writes_committed"`
		} `json:"backfill_write_rehearsal"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.NNTPOverviewSample.HeaderCandidates != 8 || report.NNTPOverviewSample.PartCandidates != 8 {
		t.Fatalf("nntp overview sample = %#v", report.NNTPOverviewSample)
	}
	if report.BackfillWriteRehearsal.QueueEntries != 8 || report.BackfillWriteRehearsal.CursorUpdatesAttempted != 2 || report.BackfillWriteRehearsal.HeaderRowsAttempted != 8 || report.BackfillWriteRehearsal.PartRowsAttempted != 8 {
		t.Fatalf("backfill overview write rehearsal = %#v", report.BackfillWriteRehearsal)
	}
	if report.BackfillWriteRehearsal.HeaderRowsAffected != 8 || report.BackfillWriteRehearsal.PartRowsAffected != 8 {
		t.Fatalf("backfill overview write rows = %#v", report.BackfillWriteRehearsal)
	}
	if !report.BackfillWriteRehearsal.RolledBack || report.BackfillWriteRehearsal.WritesCommitted != 0 {
		t.Fatalf("backfill overview write rehearsal rollback = %#v", report.BackfillWriteRehearsal)
	}
	if fingerprintAfter := workerHeaderBackfillWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("backfill overview rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunRejectsBackfillCommitWithoutOverviewSample(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBackfillTables(t, ctx, db)
	seedWorkerBackfillRows(t, ctx, db)
	createWorkerHeaderRehearsalTables(t, ctx, db)
	fingerprintBefore := workerHeaderBackfillWriteFingerprint(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:backfill"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/backfill.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "backfill-commit-proof",
		"--backfill-qty", "75000",
		"--backfill-max-messages", "20000",
		"--backfill-threads", "4",
		"--backfill-groups", "10",
		"--backfill-days", "1",
		"--backfill-min-articles", "100",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want config error; stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stderr.String(), "--commit-lane-writes for backfill requires --nntp-overview-sample") {
		t.Fatalf("stderr = %q, want overview sample requirement", stderr.String())
	}
	if stdout.String() != "" {
		t.Fatalf("stdout = %q, want no report for config error", stdout.String())
	}
	if fingerprintAfter := workerHeaderBackfillWriteFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("backfill rejected commit changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunCommitsBackfillOverviewSampleWritesWithRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerBackfillTables(t, ctx, db)
	seedWorkerBackfillRows(t, ctx, db)
	createWorkerHeaderRehearsalTables(t, ctx, db)
	fingerprintBefore := workerHeaderBackfillWriteFingerprint(t, ctx, db)

	server := newWorkerFakeNNTPServer(t, func(line string) string {
		switch line {
		case "GROUP a.b.multimedia.movies":
			return "211 200000 1 200000 a.b.multimedia.movies"
		case "GROUP a.b.multimedia.vintage-film":
			return "211 200000 2 200000 a.b.multimedia.vintage-film"
		case "OVER 30000-30001":
			return "224 overview follows\r\n30000\tBackfill.One\tposter@example.test\t17 Jun 2026 11:00:00 +0000\t<30000@example.test>\t\t2234\t55\r\n30001\tBackfill.Two\tposter@example.test\t17 Jun 2026 11:01:00 +0000\t<30001@example.test>\t\t2235\t56\r\n."
		case "OVER 2-3":
			return "224 overview follows\r\n2\tVintage.One\tposter@example.test\t17 Jun 2026 11:02:00 +0000\t<2@example.test>\t\t2236\t57\r\n3\tVintage.Two\tposter@example.test\t17 Jun 2026 11:03:00 +0000\t<3@example.test>\t\t2237\t58\r\n."
		case "OVER 10000-10001":
			return "224 overview follows\r\n10000\tBackfill.Three\tposter@example.test\t17 Jun 2026 11:04:00 +0000\t<10000@example.test>\t\t2238\t59\r\n10001\tBackfill.Four\tposter@example.test\t17 Jun 2026 11:05:00 +0000\t<10001@example.test>\t\t2239\t60\r\n."
		case "OVER 1-2":
			return "224 overview follows\r\n1\tBackfill.Five\tposter@example.test\t17 Jun 2026 11:06:00 +0000\t<1@example.test>\t\t2240\t61\r\n2\tBackfill.Six\tposter@example.test\t17 Jun 2026 11:07:00 +0000\t<2b@example.test>\t\t2241\t62\r\n."
		case "QUIT":
			return "205 closing"
		default:
			t.Fatalf("unexpected NNTP command %q", line)
			return "500 unexpected"
		}
	})
	defer server.Close()

	host, port, _ := net.SplitHostPort(server.Addr().String())
	t.Setenv("NNTP_SERVER", host)
	t.Setenv("NNTP_PORT", port)
	t.Setenv("NNTP_USERNAME", "")
	t.Setenv("NNTP_PASSWORD", "")
	t.Setenv("NNTP_SSLENABLED", "false")

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:backfill"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/backfill.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "backfill-overview-commit-proof",
		"--backfill-qty", "75000",
		"--backfill-max-messages", "20000",
		"--backfill-threads", "4",
		"--backfill-groups", "10",
		"--backfill-days", "1",
		"--backfill-min-articles", "100",
		"--nntp-overview-sample", "2",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", host, port, "arguments", "redis_key", "nntmux_database", "a.b.multimedia.movies", "a.b.multimedia.vintage-film", "Backfill.One", "<30000@example.test>"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("overview commit json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Writes int `json:"writes"`
		} `json:"native_worker"`
		NNTPOverviewSample struct {
			HeaderCandidates int `json:"header_candidates"`
			PartCandidates   int `json:"part_candidates"`
		} `json:"nntp_overview_sample"`
		BackfillWriteCommit struct {
			QueueEntries           int   `json:"queue_entries"`
			CursorUpdatesAttempted int   `json:"cursor_updates_attempted"`
			HeaderRowsAttempted    int   `json:"header_rows_attempted"`
			HeaderRowsAffected     int64 `json:"header_rows_affected"`
			PartRowsAttempted      int   `json:"part_rows_attempted"`
			PartRowsAffected       int64 `json:"part_rows_affected"`
			RolledBack             bool  `json:"rolled_back"`
			WritesCommitted        int   `json:"writes_committed"`
		} `json:"backfill_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	if report.NNTPOverviewSample.HeaderCandidates != 8 || report.NNTPOverviewSample.PartCandidates != 8 {
		t.Fatalf("nntp overview sample = %#v", report.NNTPOverviewSample)
	}
	commit := report.BackfillWriteCommit
	if commit.QueueEntries != 8 || commit.CursorUpdatesAttempted != 2 || commit.HeaderRowsAttempted != 8 || commit.PartRowsAttempted != 8 {
		t.Fatalf("backfill overview write commit = %#v", commit)
	}
	if commit.HeaderRowsAffected != 8 || commit.PartRowsAffected != 8 || commit.WritesCommitted == 0 {
		t.Fatalf("backfill overview write rows = %#v", commit)
	}
	if commit.RolledBack || report.NativeWorker.Writes != commit.WritesCommitted {
		t.Fatalf("backfill overview write commit rollback = %#v native writes=%d", commit, report.NativeWorker.Writes)
	}
	if fingerprintAfter := workerHeaderBackfillWriteFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("backfill overview commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsRemoveCrapMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerRemoveCrapTables(t, ctx, db)
	seedWorkerRemoveCrapRows(t, ctx, db)

	fingerprintBefore := workerRemoveCrapTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/removecrap.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"native worker dry-run",
		"job=removecrap",
		"removecrap mysql dry-run",
		"commands=15",
		"destructive-commands=15",
		"candidate-releases=20",
		"candidate-rows=21",
		"gibberish-candidates=2",
		"gibberish-rows=2",
		"executable-candidates=1",
		"executable-rows=2",
		"hashed-candidates=1",
		"hashed-rows=1",
		"short-candidates=1",
		"short-rows=1",
		"installbin-candidates=1",
		"installbin-rows=1",
		"passwordurl-candidates=1",
		"passwordurl-rows=1",
		"nzb-candidates=1",
		"nzb-rows=1",
		"scr-candidates=1",
		"scr-rows=1",
		"passworded-candidates=2",
		"passworded-rows=2",
		"sample-candidates=1",
		"sample-rows=1",
		"size-candidates=1",
		"size-rows=1",
		"codec-candidates=1",
		"codec-rows=1",
		"blfiles-candidates=1",
		"blfiles-rows=1",
		"blacklist-candidates=2",
		"blacklist-rows=2",
		"par2only-candidates=3",
		"par2only-rows=3",
		"writes=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerRemoveCrapTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("removecrap dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsRemoveCrapJSONReport(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerRemoveCrapTables(t, ctx, db)
	seedWorkerRemoveCrapRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/removecrap.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "removecrap mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "--delete", "--type", "arguments", "redis_key", "nntmux_database", "guid-gibberish", "ABCDEFGHIJKLMNOP"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		RemoveCrap struct {
			Commands            int `json:"commands"`
			DestructiveCommands int `json:"destructive_commands"`
			CandidateReleases   int `json:"candidate_releases"`
			CandidateRows       int `json:"candidate_rows"`
			Results             []struct {
				Type              string `json:"type"`
				CandidateReleases int    `json:"candidate_releases"`
				CandidateRows     int    `json:"candidate_rows"`
			} `json:"results"`
			Writes int `json:"writes"`
		} `json:"removecrap"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "removecrap" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.RemoveCrap.Writes != 0 {
		t.Fatalf("writes = native:%d removecrap:%d, want 0", report.NativeWorker.Writes, report.RemoveCrap.Writes)
	}
	if report.RemoveCrap.Commands != 15 || report.RemoveCrap.DestructiveCommands != 15 || report.RemoveCrap.CandidateReleases != 20 || report.RemoveCrap.CandidateRows != 21 {
		t.Fatalf("removecrap report = %#v", report.RemoveCrap)
	}
	if len(report.RemoveCrap.Results) != 15 {
		t.Fatalf("removecrap results = %#v", report.RemoveCrap.Results)
	}
	if report.RemoveCrap.Results[0].Type != "gibberish" || report.RemoveCrap.Results[0].CandidateReleases != 2 || report.RemoveCrap.Results[0].CandidateRows != 2 {
		t.Fatalf("first removecrap result = %#v", report.RemoveCrap.Results[0])
	}
	if report.RemoveCrap.Results[1].Type != "executable" || report.RemoveCrap.Results[1].CandidateReleases != 1 || report.RemoveCrap.Results[1].CandidateRows != 2 {
		t.Fatalf("second removecrap result = %#v", report.RemoveCrap.Results[1])
	}
	wantRemaining := []struct {
		Type              string
		CandidateReleases int
		CandidateRows     int
	}{
		{Type: "hashed", CandidateReleases: 1, CandidateRows: 1},
		{Type: "short", CandidateReleases: 1, CandidateRows: 1},
		{Type: "installbin", CandidateReleases: 1, CandidateRows: 1},
		{Type: "passwordurl", CandidateReleases: 1, CandidateRows: 1},
		{Type: "nzb", CandidateReleases: 1, CandidateRows: 1},
		{Type: "scr", CandidateReleases: 1, CandidateRows: 1},
		{Type: "passworded", CandidateReleases: 2, CandidateRows: 2},
		{Type: "sample", CandidateReleases: 1, CandidateRows: 1},
		{Type: "size", CandidateReleases: 1, CandidateRows: 1},
		{Type: "codec", CandidateReleases: 1, CandidateRows: 1},
		{Type: "blfiles", CandidateReleases: 1, CandidateRows: 1},
		{Type: "blacklist", CandidateReleases: 2, CandidateRows: 2},
		{Type: "par2only", CandidateReleases: 3, CandidateRows: 3},
	}
	for index, want := range wantRemaining {
		got := report.RemoveCrap.Results[index+2]
		if got.Type != want.Type || got.CandidateReleases != want.CandidateReleases || got.CandidateRows != want.CandidateRows {
			t.Fatalf("removecrap result %d = %#v, want %#v", index+2, got, want)
		}
	}
}

func TestRunPrintsRemoveCrapJSONReportWithWriteRehearsal(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerRemoveCrapTables(t, ctx, db)
	seedWorkerRemoveCrapRows(t, ctx, db)
	fingerprintBefore := workerRemoveCrapTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/removecrap.json",
		"--dry-run",
		"--rehearse-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "removecrap write rehearsal") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "--delete", "--type", "arguments", "redis_key", "nntmux_database", "guid-gibberish", "ABCDEFGHIJKLMNOP"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		RemoveCrapWriteRehearsal struct {
			CandidateReleases       int   `json:"candidate_releases"`
			CandidateRows           int   `json:"candidate_rows"`
			DeleteCommands          int   `json:"delete_commands"`
			CollectionRowsAffected  int64 `json:"collection_rows_affected"`
			ReleaseFileRowsAffected int64 `json:"release_file_rows_affected"`
			ReleaseRowsAffected     int64 `json:"release_rows_affected"`
			RolledBack              bool  `json:"rolled_back"`
			WritesCommitted         int   `json:"writes_committed"`
		} `json:"removecrap_write_rehearsal"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "removecrap" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 {
		t.Fatalf("native_worker.writes = %d, want 0", report.NativeWorker.Writes)
	}
	rehearsal := report.RemoveCrapWriteRehearsal
	if rehearsal.CandidateReleases != 19 || rehearsal.CandidateRows != 21 || rehearsal.DeleteCommands != 15 {
		t.Fatalf("removecrap write rehearsal candidates = %#v", rehearsal)
	}
	if rehearsal.CollectionRowsAffected != 2 || rehearsal.ReleaseFileRowsAffected != 13 || rehearsal.ReleaseRowsAffected != 19 {
		t.Fatalf("removecrap write rehearsal rows = %#v", rehearsal)
	}
	if !rehearsal.RolledBack || rehearsal.WritesCommitted != 0 {
		t.Fatalf("removecrap write rehearsal rollback = %#v", rehearsal)
	}

	if fingerprintAfter := workerRemoveCrapTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("removecrap write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunCommitsRemoveCrapWritesWithRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerRemoveCrapTables(t, ctx, db)
	seedWorkerRemoveCrapRows(t, ctx, db)
	fingerprintBefore := workerRemoveCrapTableFingerprint(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:removecrap"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/removecrap.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "removecrap-commit-proof",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "--delete", "--type", "arguments", "redis_key", "nntmux_database", "guid-gibberish", "ABCDEFGHIJKLMNOP"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("commit json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Writes int `json:"writes"`
		} `json:"native_worker"`
		RemoveCrapWriteCommit struct {
			CandidateReleases       int     `json:"candidate_releases"`
			CandidateRows           int     `json:"candidate_rows"`
			DeleteCommands          int     `json:"delete_commands"`
			CollectionRowsAffected  int64   `json:"collection_rows_affected"`
			ReleaseFileRowsAffected int64   `json:"release_file_rows_affected"`
			ReleaseRowsAffected     int64   `json:"release_rows_affected"`
			DeletedReleaseIDs       []int64 `json:"deleted_release_ids"`
			DeletedCollectionIDs    []int64 `json:"deleted_collection_ids"`
			FileCleanupRowsEnqueued int64   `json:"release_file_cleanup_rows_enqueued"`
			RolledBack              bool    `json:"rolled_back"`
			WritesCommitted         int     `json:"writes_committed"`
		} `json:"removecrap_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	commit := report.RemoveCrapWriteCommit
	if commit.CandidateReleases != 19 || commit.CandidateRows != 21 || commit.DeleteCommands != 15 {
		t.Fatalf("removecrap write commit candidates = %#v", commit)
	}
	if commit.CollectionRowsAffected != 2 || commit.ReleaseFileRowsAffected != 13 || commit.ReleaseRowsAffected != 19 {
		t.Fatalf("removecrap write commit rows = %#v", commit)
	}
	if len(commit.DeletedReleaseIDs) != int(commit.ReleaseRowsAffected) {
		t.Fatalf("deleted release ids = %#v, want one id per deleted release row", commit.DeletedReleaseIDs)
	}
	if len(commit.DeletedCollectionIDs) != int(commit.CollectionRowsAffected) {
		t.Fatalf("deleted collection ids = %#v, want one id per deleted collection row", commit.DeletedCollectionIDs)
	}
	if commit.FileCleanupRowsEnqueued != commit.ReleaseRowsAffected {
		t.Fatalf("release file cleanup rows enqueued = %d, want one per deleted release row %d", commit.FileCleanupRowsEnqueued, commit.ReleaseRowsAffected)
	}
	seenDeletedReleaseIDs := map[int64]bool{}
	for _, releaseID := range commit.DeletedReleaseIDs {
		if releaseID <= 0 {
			t.Fatalf("deleted release ids = %#v, want positive ids", commit.DeletedReleaseIDs)
		}
		if seenDeletedReleaseIDs[releaseID] {
			t.Fatalf("deleted release ids = %#v, want unique ids", commit.DeletedReleaseIDs)
		}
		seenDeletedReleaseIDs[releaseID] = true
	}
	seenDeletedCollectionIDs := map[int64]bool{}
	for _, collectionID := range commit.DeletedCollectionIDs {
		if collectionID <= 0 {
			t.Fatalf("deleted collection ids = %#v, want positive ids", commit.DeletedCollectionIDs)
		}
		if seenDeletedCollectionIDs[collectionID] {
			t.Fatalf("deleted collection ids = %#v, want unique ids", commit.DeletedCollectionIDs)
		}
		seenDeletedCollectionIDs[collectionID] = true
	}
	if commit.RolledBack {
		t.Fatalf("rolled_back = true, want committed writes")
	}
	if commit.WritesCommitted == 0 || report.NativeWorker.Writes != commit.WritesCommitted {
		t.Fatalf("writes committed = %#v native writes=%d", commit, report.NativeWorker.Writes)
	}
	var cleanupRows int
	if err := db.QueryRowContext(ctx, `
		SELECT COUNT(*)
		FROM native_worker_side_effects
		WHERE job = 'removecrap'
			AND effect = 'release-file-cleanup'
			AND status = 'pending'
			AND payload_text <> ''`).Scan(&cleanupRows); err != nil {
		t.Fatalf("count removecrap file cleanup rows: %v", err)
	}
	if cleanupRows != int(commit.ReleaseRowsAffected) {
		t.Fatalf("removecrap file cleanup rows = %d, want %d", cleanupRows, commit.ReleaseRowsAffected)
	}
	if fingerprintAfter := workerRemoveCrapTableFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("removecrap commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunCommitsRemoveCrapWritesWithProductionOptIn(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerRemoveCrapTables(t, ctx, db)
	seedWorkerRemoveCrapRows(t, ctx, db)
	fingerprintBefore := workerRemoveCrapTableFingerprint(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:removecrap"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowDestructiveTestDBEnv, "")
	t.Setenv(safety.AllowCommittedTestDBEnv, "")
	t.Setenv(safety.AllowProductionCommitEnv, "removecrap")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/removecrap.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "removecrap-production-proof",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stderr.String(), safety.AllowDestructiveTestDBEnv) || strings.Contains(stderr.String(), safety.AllowCommittedTestDBEnv) {
		t.Fatalf("stderr = %q, production opt-in should not require native-test guards", stderr.String())
	}

	var report struct {
		NativeWorker struct {
			Writes int `json:"writes"`
		} `json:"native_worker"`
		RemoveCrapWriteCommit struct {
			ReleaseRowsAffected     int64   `json:"release_rows_affected"`
			CollectionRowsAffected  int64   `json:"collection_rows_affected"`
			DeletedReleaseIDs       []int64 `json:"deleted_release_ids"`
			DeletedCollectionIDs    []int64 `json:"deleted_collection_ids"`
			FileCleanupRowsEnqueued int64   `json:"release_file_cleanup_rows_enqueued"`
			RolledBack              bool    `json:"rolled_back"`
			WritesCommitted         int     `json:"writes_committed"`
		} `json:"removecrap_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	commit := report.RemoveCrapWriteCommit
	if commit.RolledBack {
		t.Fatalf("rolled_back = true, want committed writes")
	}
	if commit.WritesCommitted == 0 || report.NativeWorker.Writes != commit.WritesCommitted {
		t.Fatalf("writes committed = %#v native writes=%d", commit, report.NativeWorker.Writes)
	}
	if commit.FileCleanupRowsEnqueued != commit.ReleaseRowsAffected {
		t.Fatalf("file cleanup rows = %d, want release rows %d", commit.FileCleanupRowsEnqueued, commit.ReleaseRowsAffected)
	}
	if commit.DeletedReleaseIDs == nil || len(commit.DeletedReleaseIDs) != int(commit.ReleaseRowsAffected) {
		t.Fatalf("deleted release ids = %#v, want one array entry per release row %d", commit.DeletedReleaseIDs, commit.ReleaseRowsAffected)
	}
	if commit.DeletedCollectionIDs == nil || len(commit.DeletedCollectionIDs) != int(commit.CollectionRowsAffected) {
		t.Fatalf("deleted collection ids = %#v, want one array entry per collection row %d", commit.DeletedCollectionIDs, commit.CollectionRowsAffected)
	}
	if fingerprintAfter := workerRemoveCrapTableFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("removecrap production opt-in commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunExecutesRemoveCrapNativeLaneCommands(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerRemoveCrapTables(t, ctx, db)
	seedWorkerRemoveCrapRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "removecrap", "laravel-removecrap-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/removecrap.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-removecrap-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=removecrap",
		"commands=15",
		"succeeded=15",
		"failed=0",
		"max-processes=1",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan releases:remove-crap --type=gibberish --time=4 --delete",
		"artisan releases:remove-crap --type=executable --time=4 --delete",
		"artisan releases:remove-crap --type=hashed --time=4 --delete",
		"artisan releases:remove-crap --type=short --time=4 --delete",
		"artisan releases:remove-crap --type=installbin --time=4 --delete",
		"artisan releases:remove-crap --type=passwordurl --time=4 --delete",
		"artisan releases:remove-crap --type=nzb --time=4 --delete",
		"artisan releases:remove-crap --type=scr --time=4 --delete",
		"artisan releases:remove-crap --type=passworded --time=4 --delete",
		"artisan releases:remove-crap --type=sample --time=4 --delete",
		"artisan releases:remove-crap --type=size --time=4 --delete",
		"artisan releases:remove-crap --type=codec --time=4 --delete",
		"artisan releases:remove-crap --type=blfiles --time=4 --delete",
		"artisan releases:remove-crap --type=blacklist --time=4 --delete",
		"artisan releases:remove-crap --type=par2only --time=4 --delete",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
}

func TestRunExecutesRemoveCrapNativeLanePreservesBlacklistID(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerRemoveCrapTables(t, ctx, db)
	seedWorkerRemoveCrapRows(t, ctx, db)

	planPath := filepath.Join(t.TempDir(), "removecrap-blacklist-id.json")
	planJSON := `{
		"version": 1,
		"mode": "shadow",
		"job": {
			"name": "removecrap",
			"description": "Remove configured unwanted releases",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:removecrap",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:removecrap",
			"seconds": 42
		},
		"commands": [
			{
				"command": "releases:remove-crap",
				"arguments": {
					"--type": "blacklist",
					"--time": "4",
					"--blacklist-id": "6",
					"--delete": true
				}
			}
		]
	}`
	if err := os.WriteFile(planPath, []byte(planJSON), 0o644); err != nil {
		t.Fatalf("write blacklist-id plan: %v", err)
	}

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "removecrap", "laravel-removecrap-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", planPath,
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-removecrap-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if !strings.Contains(stdout.String(), "commands=1") {
		t.Fatalf("stdout = %q, missing commands=1", stdout.String())
	}

	logged := readFakeArtisanLog(t, logPath)
	want := "artisan releases:remove-crap --type=blacklist --time=4 --blacklist-id=6 --delete"
	if !strings.Contains(logged, want) {
		t.Fatalf("fake artisan log = %q, missing %q", logged, want)
	}
}

func TestRunExecutesRemoveCrapNativeLanePreservesAllRemovalCommandShape(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerRemoveCrapTables(t, ctx, db)
	seedWorkerRemoveCrapRows(t, ctx, db)

	planPath := filepath.Join(t.TempDir(), "removecrap-all.json")
	planJSON := `{
		"version": 1,
		"mode": "shadow",
		"job": {
			"name": "removecrap",
			"description": "Remove configured unwanted releases",
			"enabled": true,
			"disabled_reason": null,
			"sleep": 300
		},
		"lock": {
			"name": "nntmux:distributed-worker:removecrap",
			"redis_key": "nntmux_database_nntmux-cache-nntmux:distributed-worker:removecrap",
			"seconds": 42
		},
		"commands": [
			{
				"command": "releases:remove-crap",
				"arguments": {
					"--time": "2",
					"--delete": true
				}
			}
		]
	}`
	if err := os.WriteFile(planPath, []byte(planJSON), 0o644); err != nil {
		t.Fatalf("write all-removal plan: %v", err)
	}

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "removecrap", "laravel-removecrap-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", planPath,
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-removecrap-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"commands=1",
		"succeeded=1",
		"failed=0",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	want := "artisan releases:remove-crap --time=2 --delete"
	if !strings.Contains(logged, want) {
		t.Fatalf("fake artisan log = %q, missing %q", logged, want)
	}
	if strings.Contains(logged, "--type=") {
		t.Fatalf("fake artisan log = %q, should not include empty --type for all-removal command", logged)
	}
}

func TestRunPrintsPostTVMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostTVRows(t, ctx, db)

	fingerprintBefore := workerPostprocessTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-tv.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"native worker dry-run",
		"job=post-tv",
		"postprocess mysql dry-run",
		"commands=2",
		"types=2",
		"bucket-entries=3",
		"tv-buckets=2",
		"anime-buckets=1",
		"writes=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerPostprocessTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("postprocess dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsPostTVJSONReport(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostTVRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-tv.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "postprocess mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "A-tv-eligible-1", "b-tv-eligible-2", "TV.Release"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		Postprocess struct {
			Commands      int `json:"commands"`
			Types         int `json:"types"`
			BucketEntries int `json:"bucket_entries"`
			Results       []struct {
				Type          string `json:"type"`
				BucketEntries int    `json:"bucket_entries"`
				MaxProcesses  int    `json:"max_processes"`
				RenamedMode   int    `json:"renamed_mode"`
				Pipeline      bool   `json:"pipeline"`
			} `json:"results"`
			Writes int `json:"writes"`
		} `json:"postprocess"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "post-tv" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.Postprocess.Writes != 0 {
		t.Fatalf("writes = native:%d postprocess:%d, want 0", report.NativeWorker.Writes, report.Postprocess.Writes)
	}
	if report.Postprocess.Commands != 2 || report.Postprocess.Types != 2 || report.Postprocess.BucketEntries != 3 {
		t.Fatalf("postprocess report = %#v", report.Postprocess)
	}
	if len(report.Postprocess.Results) != 2 {
		t.Fatalf("postprocess results = %#v", report.Postprocess.Results)
	}
	if report.Postprocess.Results[0].Type != "tv" || report.Postprocess.Results[0].BucketEntries != 2 || !report.Postprocess.Results[0].Pipeline {
		t.Fatalf("first postprocess result = %#v", report.Postprocess.Results[0])
	}
	if report.Postprocess.Results[1].Type != "anime" || report.Postprocess.Results[1].BucketEntries != 1 || report.Postprocess.Results[1].Pipeline {
		t.Fatalf("second postprocess result = %#v", report.Postprocess.Results[1])
	}
}

func TestRunPrintsPostTVJSONReportWithWriteRehearsal(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostTVRows(t, ctx, db)
	fingerprintBefore := workerPostprocessTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-tv.json",
		"--dry-run",
		"--rehearse-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "postprocess write rehearsal") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "A-tv-eligible-1", "b-tv-eligible-2", "TV.Release"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		PostprocessWriteRehearsal struct {
			BucketEntries          int   `json:"bucket_entries"`
			BucketUpdatesAttempted int   `json:"bucket_updates_attempted"`
			ReleaseRowsAffected    int64 `json:"release_rows_affected"`
			RolledBack             bool  `json:"rolled_back"`
			WritesCommitted        int   `json:"writes_committed"`
		} `json:"postprocess_write_rehearsal"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "post-tv" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 {
		t.Fatalf("native_worker.writes = %d, want 0", report.NativeWorker.Writes)
	}
	rehearsal := report.PostprocessWriteRehearsal
	if rehearsal.BucketEntries != 3 || rehearsal.BucketUpdatesAttempted != 3 || rehearsal.ReleaseRowsAffected != 3 {
		t.Fatalf("postprocess write rehearsal = %#v", rehearsal)
	}
	if !rehearsal.RolledBack || rehearsal.WritesCommitted != 0 {
		t.Fatalf("postprocess write rehearsal rollback = %#v", rehearsal)
	}

	if fingerprintAfter := workerPostprocessTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("postprocess write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunCommitsPostprocessWritesWithRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostTVRows(t, ctx, db)
	fingerprintBefore := workerPostprocessTableFingerprint(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:post-tv"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-tv.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "postprocess-commit-proof",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "A-tv-eligible-1", "b-tv-eligible-2", "TV.Release"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("commit json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Writes int `json:"writes"`
		} `json:"native_worker"`
		PostprocessWriteCommit struct {
			BucketEntries          int     `json:"bucket_entries"`
			BucketUpdatesAttempted int     `json:"bucket_updates_attempted"`
			ReleaseRowsAffected    int64   `json:"release_rows_affected"`
			CommittedReleaseIDs    []int64 `json:"committed_release_ids"`
			RolledBack             bool    `json:"rolled_back"`
			WritesCommitted        int     `json:"writes_committed"`
		} `json:"postprocess_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	commit := report.PostprocessWriteCommit
	if commit.BucketEntries != 3 || commit.BucketUpdatesAttempted != 3 || commit.ReleaseRowsAffected != 3 {
		t.Fatalf("postprocess write commit = %#v", commit)
	}
	if commit.RolledBack {
		t.Fatalf("rolled_back = true, want committed writes")
	}
	if commit.WritesCommitted == 0 || report.NativeWorker.Writes != commit.WritesCommitted {
		t.Fatalf("writes committed = %#v native writes=%d", commit, report.NativeWorker.Writes)
	}
	if want := []int64{100, 101, 200}; !reflect.DeepEqual(commit.CommittedReleaseIDs, want) {
		t.Fatalf("committed_release_ids = %#v, want %#v", commit.CommittedReleaseIDs, want)
	}
	if fingerprintAfter := workerPostprocessTableFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("postprocess commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunExecutesPostTVNativeLaneQueue(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostTVRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "post-tv", "laravel-post-tv-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-tv.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-post-tv-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=post-tv",
		"commands=3",
		"succeeded=3",
		"failed=0",
		"max-processes=3",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan postprocess:tv-pipeline A 1 --mode=pipeline",
		"artisan postprocess:tv-pipeline b 1 --mode=pipeline",
		"artisan postprocess:guid anime c",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
}

func TestRunPrintsPostMoviesMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostMovieRows(t, ctx, db)

	fingerprintBefore := workerPostprocessTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-movies.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"native worker dry-run",
		"job=post-movies",
		"postprocess mysql dry-run",
		"commands=1",
		"types=1",
		"bucket-entries=2",
		"movie-buckets=2",
		"movie-max-processes=3",
		"movie-renamed-mode=1",
		"writes=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerPostprocessTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("post-movies dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsPostMoviesJSONReport(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostMovieRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-movies.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "postprocess mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "m-movie-pending", "n-movie-repair", "Movie.Release"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		Postprocess struct {
			Commands      int `json:"commands"`
			Types         int `json:"types"`
			BucketEntries int `json:"bucket_entries"`
			Results       []struct {
				Type          string `json:"type"`
				BucketEntries int    `json:"bucket_entries"`
				MaxProcesses  int    `json:"max_processes"`
				RenamedMode   int    `json:"renamed_mode"`
				Pipeline      bool   `json:"pipeline"`
			} `json:"results"`
			Writes int `json:"writes"`
		} `json:"postprocess"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "post-movies" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.Postprocess.Writes != 0 {
		t.Fatalf("writes = native:%d postprocess:%d, want 0", report.NativeWorker.Writes, report.Postprocess.Writes)
	}
	if report.Postprocess.Commands != 1 || report.Postprocess.Types != 1 || report.Postprocess.BucketEntries != 2 {
		t.Fatalf("postprocess report = %#v", report.Postprocess)
	}
	if len(report.Postprocess.Results) != 1 {
		t.Fatalf("postprocess results = %#v", report.Postprocess.Results)
	}
	result := report.Postprocess.Results[0]
	if result.Type != "movie" || result.BucketEntries != 2 || result.MaxProcesses != 3 || result.RenamedMode != 1 || result.Pipeline {
		t.Fatalf("post-movies result = %#v", result)
	}
}

func TestRunExecutesPostMoviesNativeLaneQueue(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostMovieRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "post-movies", "laravel-post-movies-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-movies.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-post-movies-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=post-movies",
		"commands=2",
		"succeeded=2",
		"failed=0",
		"max-processes=3",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan postprocess:guid movie m",
		"artisan postprocess:guid movie n",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
}

func TestRunPrintsPostAmazonMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostAmazonRows(t, ctx, db)

	fingerprintBefore := workerPostprocessTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-amazon.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"native worker dry-run",
		"job=post-amazon",
		"postprocess mysql dry-run",
		"commands=1",
		"types=4",
		"bucket-entries=8",
		"books-buckets=2",
		"music-buckets=2",
		"console-buckets=2",
		"games-buckets=2",
		"writes=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerPostprocessTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("post-amazon dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsPostAmazonJSONReport(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostAmazonRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-amazon.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "postprocess mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "B-book-eligible", "Book.Release", "Music.Release", "Console.Release", "Game.Release"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		Postprocess struct {
			Commands      int `json:"commands"`
			Types         int `json:"types"`
			BucketEntries int `json:"bucket_entries"`
			Results       []struct {
				Type          string `json:"type"`
				BucketEntries int    `json:"bucket_entries"`
				MaxProcesses  int    `json:"max_processes"`
				RenamedMode   int    `json:"renamed_mode"`
				Pipeline      bool   `json:"pipeline"`
			} `json:"results"`
			Writes int `json:"writes"`
		} `json:"postprocess"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "post-amazon" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.Postprocess.Writes != 0 {
		t.Fatalf("writes = native:%d postprocess:%d, want 0", report.NativeWorker.Writes, report.Postprocess.Writes)
	}
	if report.Postprocess.Commands != 1 || report.Postprocess.Types != 4 || report.Postprocess.BucketEntries != 8 {
		t.Fatalf("postprocess report = %#v", report.Postprocess)
	}

	gotBuckets := map[string]int{}
	for _, result := range report.Postprocess.Results {
		gotBuckets[result.Type] = result.BucketEntries
		if result.MaxProcesses != 4 || result.RenamedMode != 0 || result.Pipeline {
			t.Fatalf("post-amazon result = %#v", result)
		}
	}
	if !reflect.DeepEqual(gotBuckets, map[string]int{"books": 2, "music": 2, "console": 2, "games": 2}) {
		t.Fatalf("post-amazon buckets = %#v", gotBuckets)
	}
}

func TestRunExecutesPostAmazonNativeLaneQueue(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostAmazonRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "post-amazon", "laravel-post-amazon-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-amazon.json",
		"--run-lane",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-post-amazon-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=post-amazon",
		"commands=8",
		"succeeded=8",
		"failed=0",
		"max-processes=4",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan postprocess:guid books B",
		"artisan postprocess:guid books q",
		"artisan postprocess:guid music M",
		"artisan postprocess:guid music N",
		"artisan postprocess:guid console C",
		"artisan postprocess:guid console D",
		"artisan postprocess:guid games G",
		"artisan postprocess:guid games H",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
}

func TestRunPrintsPostAdditionalMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostAdditionalRows(t, ctx, db)

	fingerprintBefore := workerPostprocessTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-additional.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"native worker dry-run",
		"job=post-additional",
		"postprocess mysql dry-run",
		"commands=2",
		"types=2",
		"bucket-entries=4",
		"additional-buckets=2",
		"additional-max-processes=5",
		"nfo-buckets=2",
		"nfo-max-processes=2",
		"writes=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerPostprocessTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("post-additional dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
	for _, deferred := range []string{"metadata-refresh mysql dry-run", "hashed-fixnames mysql dry-run", "hashed-fixnames write-contract"} {
		if strings.Contains(output, deferred) {
			t.Fatalf("post-additional dry-run output included deferred %q: %q", deferred, output)
		}
	}
}

func TestRunPrintsPostAdditionalJSONReport(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostAdditionalRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-additional.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "postprocess mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "Additional.Release", "NFO.Release", "a-add-eligible", "N-nfo-eligible"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		Postprocess struct {
			Commands      int `json:"commands"`
			Types         int `json:"types"`
			BucketEntries int `json:"bucket_entries"`
			Results       []struct {
				Type          string `json:"type"`
				BucketEntries int    `json:"bucket_entries"`
				MaxProcesses  int    `json:"max_processes"`
				RenamedMode   int    `json:"renamed_mode"`
				Pipeline      bool   `json:"pipeline"`
			} `json:"results"`
			Writes int `json:"writes"`
		} `json:"postprocess"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}
	var generic map[string]any
	if err := json.Unmarshal(stdout.Bytes(), &generic); err != nil {
		t.Fatalf("decode generic json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "post-additional" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Writes != 0 || report.Postprocess.Writes != 0 {
		t.Fatalf("writes = native:%d postprocess:%d, want 0", report.NativeWorker.Writes, report.Postprocess.Writes)
	}
	if report.Postprocess.Commands != 2 || report.Postprocess.Types != 2 || report.Postprocess.BucketEntries != 4 {
		t.Fatalf("postprocess report = %#v", report.Postprocess)
	}
	gotBuckets := map[string]int{}
	for _, result := range report.Postprocess.Results {
		gotBuckets[result.Type] = result.BucketEntries
		if result.RenamedMode != 0 || result.Pipeline {
			t.Fatalf("post-additional result = %#v", result)
		}
	}
	if !reflect.DeepEqual(gotBuckets, map[string]int{"additional": 2, "nfo": 2}) {
		t.Fatalf("post-additional buckets = %#v", gotBuckets)
	}
	for _, deferred := range []string{"metadata_refresh", "hashed_fixnames"} {
		if _, ok := generic[deferred]; ok {
			t.Fatalf("post-additional json included deferred %q: %q", deferred, stdout.String())
		}
	}
}

func TestRunExecutesPostAdditionalNativeLaneQueueWithDeferredGuard(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerMetadataIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerPostprocessTables(t, ctx, db)
	seedWorkerPostAdditionalRows(t, ctx, db)

	releaseLock := seedNativeLaneHeldLock(t, ctx, redisAddr, "post-additional", "laravel-post-additional-owner")
	defer releaseLock()

	artisanBinary, logPath := fakeArtisanBinary(t)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/post-additional.json",
		"--run-lane",
		"--allow-deferred-post-additional",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-post-additional-owner",
		"--lock-mode", "held",
		"--artisan-binary", artisanBinary,
		"--artisan-script", "artisan",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, want := range []string{
		"native lane execution",
		"job=post-additional",
		"commands=4",
		"succeeded=4",
		"failed=0",
		"max-processes=5",
	} {
		if !strings.Contains(stdout.String(), want) {
			t.Fatalf("stdout = %q, missing %q", stdout.String(), want)
		}
	}

	logged := readFakeArtisanLog(t, logPath)
	for _, want := range []string{
		"artisan postprocess:guid additional a",
		"artisan postprocess:guid additional B",
		"artisan postprocess:guid nfo N",
		"artisan postprocess:guid nfo o",
	} {
		if !strings.Contains(logged, want) {
			t.Fatalf("fake artisan log = %q, missing %q", logged, want)
		}
	}
	for _, deferred := range []string{
		"predb:refresh-external-metadata",
		"releases:fix-names",
	} {
		if strings.Contains(logged, deferred) {
			t.Fatalf("fake artisan log = %q, should not dispatch deferred %q", logged, deferred)
		}
	}
}

func TestRunPrintsHashedFixNameMariaDBDryRunSummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/metadata-refresh-plan.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"hashed-fixnames mysql dry-run",
		"crc-mutations=1",
		"crc-status-only=1",
		"par-hash-mutations=1",
		"par-hash-status-only=1",
		"hashed-fixnames write-contract",
		"planned-release-updates=2",
		"planned-single-column-updates=3",
		"required-events=2",
		"required-search-updates=5",
		"category-resolution-required=2",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}
}

func TestRunPrintsHashedFixNameSummaryForHashedFixnamesPlan(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"job=hashed-fixnames",
		"hashed-fixnames mysql dry-run",
		"crc-mutations=1",
		"par-hash-mutations=1",
		"hashed-fixnames write-contract",
		"planned-release-updates=2",
		"planned-single-column-updates=3",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}
}

func TestRunPrintsRegularFixnamesNativeDiscoverySummary(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerRegularFixRows(t, ctx, db)

	fingerprintBefore := workerHashedFixTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/fixnames.json",
		"--dry-run",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"job=fixnames",
		"fixnames dry-run",
		"fixnames mysql dry-run",
		"crc-mutations=1",
		"crc-status-only=1",
		"par-hash-mutations=1",
		"par-hash-status-only=1",
		"writes=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerHashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("regular fixnames dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunCommitsRegularFixnamesMissStatusWithRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerRegularFixRows(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:fixnames"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/fixnames.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "regular-fixnames-commit-owner",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "--redis-addr", "redis_key", "nntmux_database", "Regular.CRC", "Regular.Par"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("regular fixnames commit json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		DryRun       bool `json:"dry_run"`
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		FixnamesWriteCommit struct {
			SingleColumnUpdatesAttempted int     `json:"single_column_updates_attempted"`
			SingleColumnUpdatesCommitted int     `json:"single_column_updates_committed"`
			SingleColumnUpdatesSkipped   int     `json:"single_column_updates_skipped"`
			SingleColumnUpdatesBlocked   int     `json:"single_column_updates_blocked"`
			SingleColumnRowsAffected     int64   `json:"single_column_rows_affected"`
			ReleaseUpdatesBlocked        int     `json:"release_updates_blocked"`
			BlockedReleaseIDs            []int64 `json:"blocked_release_ids"`
			BlockedStatusReleaseIDs      []int64 `json:"blocked_status_release_ids"`
			CommittedReleaseIDs          []int64 `json:"committed_release_ids"`
			LockAcquired                 bool    `json:"lock_acquired"`
			SearchSideEffectRowsEnqueued int     `json:"search_side_effect_rows_enqueued"`
			SearchUpdatesEnqueued        int     `json:"search_updates_enqueued"`
			WritesCommitted              int     `json:"writes_committed"`
		} `json:"fixnames_write_commit"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode regular fixnames commit report: %v; output=%q", err, stdout.String())
	}

	commit := report.FixnamesWriteCommit
	if report.DryRun {
		t.Fatalf("dry_run = true, want false")
	}
	if report.NativeWorker.Job != "fixnames" || report.NativeWorker.Writes != 2 {
		t.Fatalf("native_worker = %#v", report.NativeWorker)
	}
	if !commit.LockAcquired {
		t.Fatalf("lock_acquired = false, want true")
	}
	if commit.SingleColumnUpdatesAttempted != 2 || commit.SingleColumnUpdatesCommitted != 2 || commit.SingleColumnRowsAffected != 2 {
		t.Fatalf("fixnames_write_commit = %#v, want two committed miss statuses", commit)
	}
	if commit.SingleColumnUpdatesBlocked != 1 || commit.ReleaseUpdatesBlocked != 2 {
		t.Fatalf("fixnames_write_commit = %#v, want one blocked rename-linked status and two blocked renames", commit)
	}
	if !reflect.DeepEqual(commit.CommittedReleaseIDs, []int64{711, 721}) {
		t.Fatalf("committed_release_ids = %#v, want [711 721]", commit.CommittedReleaseIDs)
	}
	if !reflect.DeepEqual(commit.BlockedReleaseIDs, []int64{720, 710}) {
		t.Fatalf("blocked_release_ids = %#v, want [720 710]", commit.BlockedReleaseIDs)
	}
	if !reflect.DeepEqual(commit.BlockedStatusReleaseIDs, []int64{710}) {
		t.Fatalf("blocked_status_release_ids = %#v, want [710]", commit.BlockedStatusReleaseIDs)
	}
	if commit.SearchSideEffectRowsEnqueued != 2 || commit.SearchUpdatesEnqueued != 2 || commit.WritesCommitted != 2 {
		t.Fatalf("fixnames_write_commit = %#v, want two outbox-backed writes", commit)
	}

	assertWorkerHashedFixStatus(t, ctx, db, 711, "proc_crc32", 1)
	assertWorkerHashedFixStatus(t, ctx, db, 721, "proc_hash16k", 1)
	assertWorkerHashedFixStatus(t, ctx, db, 710, "proc_crc32", 0)
	assertWorkerHashedFixStatus(t, ctx, db, 720, "proc_hash16k", 0)
	if got := workerNativeSearchSideEffectOutboxCountForJob(t, ctx, db, "fixnames"); got != 2 {
		t.Fatalf("fixnames native side-effect outbox rows = %d, want 2", got)
	}
	if got := workerNativeSearchSideEffectOutboxCountForJob(t, ctx, db, "hashed-fixnames"); got != 0 {
		t.Fatalf("hashed-fixnames native side-effect outbox rows = %d, want 0", got)
	}
	if exists, err := client.Exists(ctx, lockKey).Result(); err != nil {
		t.Fatalf("check redis lock release: %v", err)
	} else if exists != 0 {
		t.Fatalf("redis lock %q still exists after commit", lockKey)
	}

	stdout.Reset()
	stderr.Reset()
	code = run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/fixnames.json",
		"--commit-lane-writes",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "regular-fixnames-commit-owner-second",
	}, strings.NewReader(""), &stdout, &stderr)
	if code != 0 {
		t.Fatalf("second run exit = %d, stderr = %q", code, stderr.String())
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode second regular fixnames commit report: %v; output=%q", err, stdout.String())
	}
	commit = report.FixnamesWriteCommit
	if commit.WritesCommitted != 0 || commit.SingleColumnUpdatesCommitted != 0 || commit.SingleColumnRowsAffected != 0 {
		t.Fatalf("second fixnames_write_commit = %#v, want idempotent zero writes", commit)
	}
	if commit.SearchSideEffectRowsEnqueued != 0 || commit.SearchUpdatesEnqueued != 0 {
		t.Fatalf("second outbox counters = rows:%d updates:%d, want zero", commit.SearchSideEffectRowsEnqueued, commit.SearchUpdatesEnqueued)
	}
	if got := workerNativeSearchSideEffectOutboxCountForJob(t, ctx, db, "fixnames"); got != 2 {
		t.Fatalf("fixnames native side-effect outbox rows after retry = %d, want 2", got)
	}
}

func TestRunPrintsHashedFixNameJSONReportWithWriteContractDetails(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)

	fingerprintBefore := workerHashedFixTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	if strings.Contains(stdout.String(), "hashed-fixnames mysql dry-run") {
		t.Fatalf("json output contains text summary: %q", stdout.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("json report leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		NativeWorker struct {
			Job      string `json:"job"`
			Commands int    `json:"commands"`
			Writes   int    `json:"writes"`
		} `json:"native_worker"`
		HashedFixnames struct {
			CRCMutations         int  `json:"crc_mutations"`
			CRCStatusOnly        int  `json:"crc_status_only"`
			ParHashMutations     int  `json:"par_hash_mutations"`
			ParHashStatusOnly    int  `json:"par_hash_status_only"`
			ReplacementReady     bool `json:"replacement_ready"`
			ReplacementReadiness struct {
				SupportedMethods    []string `json:"supported_methods"`
				UnsupportedMethods  []string `json:"unsupported_methods"`
				UnsupportedCommands int      `json:"unsupported_commands"`
				Blockers            []string `json:"blockers"`
			} `json:"replacement_readiness"`
			Writes        int `json:"writes"`
			WriteContract struct {
				ReleaseUpdates []struct {
					ReleaseID   int64  `json:"release_id"`
					Type        string `json:"type"`
					Method      string `json:"method"`
					MatchSource string `json:"match_source"`
					Columns     []struct {
						Column      string `json:"column"`
						Value       any    `json:"value,omitempty"`
						ValueSource string `json:"value_source,omitempty"`
					} `json:"columns"`
				} `json:"release_updates"`
				SingleColumnUpdates []struct {
					ReleaseID int64  `json:"release_id"`
					Column    string `json:"column"`
					Value     int    `json:"value"`
					Reason    string `json:"reason"`
				} `json:"single_column_updates"`
				RequiredEvents []struct {
					ReleaseID     int64  `json:"release_id"`
					OldName       string `json:"old_name"`
					NewName       string `json:"new_name"`
					OldCategoryID int    `json:"old_category_id"`
					GroupID       int64  `json:"group_id"`
					Poster        string `json:"poster"`
				} `json:"required_events"`
				SearchUpdates []struct {
					ReleaseID int64  `json:"release_id"`
					Reason    string `json:"reason"`
				} `json:"search_updates"`
				CategoryResolutionRequired int `json:"category_resolution_required"`
				Writes                     int `json:"writes"`
			} `json:"write_contract"`
		} `json:"hashed_fixnames"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	if report.NativeWorker.Job != "hashed-fixnames" {
		t.Fatalf("native_worker.job = %q", report.NativeWorker.Job)
	}
	if report.NativeWorker.Commands != 10 {
		t.Fatalf("native_worker.commands = %d", report.NativeWorker.Commands)
	}
	if report.HashedFixnames.CRCMutations != 1 || report.HashedFixnames.ParHashMutations != 1 {
		t.Fatalf("hashed_fixnames = %#v", report.HashedFixnames)
	}
	if report.HashedFixnames.ReplacementReady {
		t.Fatalf("replacement_ready = true, want false while unsupported hashed-fixnames methods remain")
	}
	readiness := report.HashedFixnames.ReplacementReadiness
	if !reflect.DeepEqual(readiness.SupportedMethods, []string{"16", "20"}) {
		t.Fatalf("supported_methods = %#v, want [16 20]", readiness.SupportedMethods)
	}
	if !reflect.DeepEqual(readiness.UnsupportedMethods, []string{"4", "6", "8", "10", "12", "14", "18", "21"}) {
		t.Fatalf("unsupported_methods = %#v", readiness.UnsupportedMethods)
	}
	if readiness.UnsupportedCommands != 8 {
		t.Fatalf("unsupported_commands = %d, want 8", readiness.UnsupportedCommands)
	}
	if len(readiness.Blockers) == 0 || !strings.Contains(readiness.Blockers[0], "unsupported hashed fix-name methods") {
		t.Fatalf("replacement blockers = %#v, want unsupported methods blocker", readiness.Blockers)
	}
	writeContract := report.HashedFixnames.WriteContract
	if report.HashedFixnames.Writes != 0 || writeContract.Writes != 0 {
		t.Fatalf("writes = hashed:%d contract:%d, want 0", report.HashedFixnames.Writes, writeContract.Writes)
	}
	if len(writeContract.ReleaseUpdates) != 2 {
		t.Fatalf("release_updates = %#v", writeContract.ReleaseUpdates)
	}
	if writeContract.ReleaseUpdates[0].ReleaseID != 300 || writeContract.ReleaseUpdates[0].Type != "PAR2 hash, " {
		t.Fatalf("first release update = %#v, want PAR hash method-order winner", writeContract.ReleaseUpdates[0])
	}
	if writeContract.ReleaseUpdates[1].ReleaseID != 100 || writeContract.ReleaseUpdates[1].Type != "CRC32, " {
		t.Fatalf("second release update = %#v, want CRC update", writeContract.ReleaseUpdates[1])
	}
	if !hasColumnSource(writeContract.ReleaseUpdates[0].Columns, "categories_id", "CategorizationService.determineCategory(groups_id, new_title, fromname)") {
		t.Fatalf("first release update columns = %#v, missing category value_source", writeContract.ReleaseUpdates[0].Columns)
	}
	if len(writeContract.SingleColumnUpdates) != 3 {
		t.Fatalf("single_column_updates = %#v", writeContract.SingleColumnUpdates)
	}
	if len(writeContract.RequiredEvents) != 2 {
		t.Fatalf("required_events = %#v", writeContract.RequiredEvents)
	}
	if len(writeContract.SearchUpdates) != 5 {
		t.Fatalf("search_updates = %#v", writeContract.SearchUpdates)
	}
	if writeContract.CategoryResolutionRequired != 2 {
		t.Fatalf("category_resolution_required = %d", writeContract.CategoryResolutionRequired)
	}

	if fingerprintAfter := workerHashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("json dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsSearchDocumentParityForPendingNativeOutboxRows(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)
	prepareWorkerSearchDocumentTables(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:hashed-fixnames"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--commit-miss-status",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "search-document-parity-commit",
	}, strings.NewReader(""), &stdout, &stderr)
	if code != 0 {
		t.Fatalf("commit run exit = %d, stderr = %q", code, stderr.String())
	}

	fingerprintBefore := workerHashedFixTableFingerprint(t, ctx, db)
	stdout.Reset()
	stderr.Reset()

	code = run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--search-document-parity",
		"--search-document-limit", "10",
	}, strings.NewReader(""), &stdout, &stderr)
	if code != 0 {
		t.Fatalf("parity run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "arguments", "redis_key", "nntmux_database", "Hash.Target", "poster@example", ".rar"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("parity json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		SearchDocuments struct {
			Mode                string `json:"mode"`
			DryRun              bool   `json:"dry_run"`
			SourceJob           string `json:"source_job"`
			SearchDocumentsSeen int    `json:"search_documents_seen"`
			ReleaseDocuments    []struct {
				ReleaseID   int64  `json:"release_id"`
				Fingerprint string `json:"fingerprint"`
			} `json:"release_documents"`
			Writes int `json:"writes"`
		} `json:"search_documents"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode parity json report: %v; output=%q", err, stdout.String())
	}
	parity := report.SearchDocuments
	if parity.Mode != "native-search-document-parity" || !parity.DryRun || parity.SourceJob != "hashed-fixnames" {
		t.Fatalf("search_documents = %#v", parity)
	}
	if parity.SearchDocumentsSeen != 2 || len(parity.ReleaseDocuments) != 2 {
		t.Fatalf("search_documents = %#v", parity)
	}
	if parity.ReleaseDocuments[0].ReleaseID != 102 || parity.ReleaseDocuments[1].ReleaseID != 301 {
		t.Fatalf("release_documents = %#v", parity.ReleaseDocuments)
	}
	for _, document := range parity.ReleaseDocuments {
		if len(document.Fingerprint) != 64 {
			t.Fatalf("fingerprint for release %d = %q", document.ReleaseID, document.Fingerprint)
		}
	}
	if parity.Writes != 0 {
		t.Fatalf("search_documents.writes = %d, want 0", parity.Writes)
	}

	if fingerprintAfter := workerHashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("search document parity changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunSearchDocumentParityDoesNotRequireHashedFixPlannerTables(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerSearchDocumentParityOnlyTables(t, ctx, db)
	seedWorkerSearchDocumentParityOnlyRows(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--search-document-parity",
		"--search-document-limit", "10",
	}, strings.NewReader(""), &stdout, &stderr)
	if code != 0 {
		t.Fatalf("parity run exit = %d, stderr = %q", code, stderr.String())
	}

	var report struct {
		HashedFixnames struct {
			WriteContract *json.RawMessage `json:"write_contract"`
		} `json:"hashed_fixnames"`
		SearchDocuments struct {
			SearchDocumentsSeen int `json:"search_documents_seen"`
			ReleaseDocuments    []struct {
				ReleaseID   int64  `json:"release_id"`
				Fingerprint string `json:"fingerprint"`
			} `json:"release_documents"`
			Writes int `json:"writes"`
		} `json:"search_documents"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode parity json report: %v; output=%q", err, stdout.String())
	}
	if report.HashedFixnames.WriteContract != nil {
		t.Fatalf("parity output included write_contract: %s", *report.HashedFixnames.WriteContract)
	}
	if report.SearchDocuments.SearchDocumentsSeen != 1 || len(report.SearchDocuments.ReleaseDocuments) != 1 {
		t.Fatalf("search_documents = %#v", report.SearchDocuments)
	}
	if report.SearchDocuments.ReleaseDocuments[0].ReleaseID != 92001 || len(report.SearchDocuments.ReleaseDocuments[0].Fingerprint) != 64 {
		t.Fatalf("release_documents = %#v", report.SearchDocuments.ReleaseDocuments)
	}
	if report.SearchDocuments.Writes != 0 {
		t.Fatalf("search_documents.writes = %d, want 0", report.SearchDocuments.Writes)
	}
}

func TestRunRehearsesHashedFixNameWritesWithRollback(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)

	fingerprintBefore := workerHashedFixTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--rehearse-writes",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	output := stdout.String()
	for _, want := range []string{
		"hashed-fixnames write-rehearsal",
		"single-column-updates-attempted=3",
		"single-column-rows-affected=3",
		"release-updates-attempted=0",
		"release-updates-blocked=2",
		"blocked-release-ids=300,100",
		"rolled-back=true",
		"writes-committed=0",
	} {
		if !strings.Contains(output, want) {
			t.Fatalf("dry-run output = %q, missing %q", output, want)
		}
	}

	if fingerprintAfter := workerHashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunPrintsHashedFixNameJSONReportWithWriteRehearsal(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)

	fingerprintBefore := workerHashedFixTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--output", "json",
		"--rehearse-writes",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	var report struct {
		HashedFixnames struct {
			WriteRehearsal struct {
				SingleColumnUpdatesAttempted int     `json:"single_column_updates_attempted"`
				SingleColumnRowsAffected     int64   `json:"single_column_rows_affected"`
				ReleaseUpdatesAttempted      int     `json:"release_updates_attempted"`
				ReleaseUpdatesBlocked        int     `json:"release_updates_blocked"`
				BlockedReleaseIDs            []int64 `json:"blocked_release_ids"`
				RolledBack                   bool    `json:"rolled_back"`
				WritesCommitted              int     `json:"writes_committed"`
			} `json:"write_rehearsal"`
		} `json:"hashed_fixnames"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	rehearsal := report.HashedFixnames.WriteRehearsal
	if rehearsal.SingleColumnUpdatesAttempted != 3 {
		t.Fatalf("single_column_updates_attempted = %d", rehearsal.SingleColumnUpdatesAttempted)
	}
	if rehearsal.SingleColumnRowsAffected != 3 {
		t.Fatalf("single_column_rows_affected = %d", rehearsal.SingleColumnRowsAffected)
	}
	if rehearsal.ReleaseUpdatesAttempted != 0 {
		t.Fatalf("release_updates_attempted = %d", rehearsal.ReleaseUpdatesAttempted)
	}
	if rehearsal.ReleaseUpdatesBlocked != 2 {
		t.Fatalf("release_updates_blocked = %d", rehearsal.ReleaseUpdatesBlocked)
	}
	if !reflect.DeepEqual(rehearsal.BlockedReleaseIDs, []int64{300, 100}) {
		t.Fatalf("blocked_release_ids = %#v", rehearsal.BlockedReleaseIDs)
	}
	if !rehearsal.RolledBack {
		t.Fatalf("rolled_back = false, want true")
	}
	if rehearsal.WritesCommitted != 0 {
		t.Fatalf("writes_committed = %d, want 0", rehearsal.WritesCommitted)
	}

	if fingerprintAfter := workerHashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("json write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunRehearsesResolvedHashedFixNameReleaseUpdatesWithRollback(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)

	oraclePath := writeWorkerResolvedOracle(t)
	fingerprintBefore := workerHashedFixTableFingerprint(t, ctx, db)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--output", "json",
		"--rehearse-writes",
		"--resolved-write-contract", oraclePath,
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}

	var report struct {
		HashedFixnames struct {
			WriteRehearsal struct {
				SingleColumnUpdatesAttempted int     `json:"single_column_updates_attempted"`
				SingleColumnRowsAffected     int64   `json:"single_column_rows_affected"`
				ReleaseUpdatesAttempted      int     `json:"release_updates_attempted"`
				ReleaseUpdateRowsAffected    int64   `json:"release_update_rows_affected"`
				ReleaseUpdatesBlocked        int     `json:"release_updates_blocked"`
				BlockedReleaseIDs            []int64 `json:"blocked_release_ids"`
				RolledBack                   bool    `json:"rolled_back"`
				WritesCommitted              int     `json:"writes_committed"`
			} `json:"write_rehearsal"`
		} `json:"hashed_fixnames"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	rehearsal := report.HashedFixnames.WriteRehearsal
	if rehearsal.ReleaseUpdatesAttempted != 2 {
		t.Fatalf("release_updates_attempted = %d, want 2", rehearsal.ReleaseUpdatesAttempted)
	}
	if rehearsal.ReleaseUpdateRowsAffected != 2 {
		t.Fatalf("release_update_rows_affected = %d, want 2", rehearsal.ReleaseUpdateRowsAffected)
	}
	if rehearsal.ReleaseUpdatesBlocked != 0 {
		t.Fatalf("release_updates_blocked = %d, want 0", rehearsal.ReleaseUpdatesBlocked)
	}
	if len(rehearsal.BlockedReleaseIDs) != 0 {
		t.Fatalf("blocked_release_ids = %#v, want empty", rehearsal.BlockedReleaseIDs)
	}
	if rehearsal.SingleColumnUpdatesAttempted != 3 {
		t.Fatalf("single_column_updates_attempted = %d", rehearsal.SingleColumnUpdatesAttempted)
	}
	if rehearsal.SingleColumnRowsAffected != 2 {
		t.Fatalf("single_column_rows_affected = %d, want 2 after resolved release update overlap", rehearsal.SingleColumnRowsAffected)
	}
	if !rehearsal.RolledBack {
		t.Fatalf("rolled_back = false, want true")
	}
	if rehearsal.WritesCommitted != 0 {
		t.Fatalf("writes_committed = %d, want 0", rehearsal.WritesCommitted)
	}

	if fingerprintAfter := workerHashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("resolved write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunCommitsHashedFixNameMissStatusWithRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:hashed-fixnames"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--commit-miss-status",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "commit-test-owner",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "--redis-addr", "redis_key", "nntmux_database", "Hash.Target", "Known.Par"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("commit json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		DryRun       bool `json:"dry_run"`
		NativeWorker struct {
			Job    string `json:"job"`
			Writes int    `json:"writes"`
		} `json:"native_worker"`
		HashedFixnames struct {
			WriteCommit struct {
				SingleColumnUpdatesAttempted int     `json:"single_column_updates_attempted"`
				SingleColumnUpdatesCommitted int     `json:"single_column_updates_committed"`
				SingleColumnUpdatesSkipped   int     `json:"single_column_updates_skipped"`
				SingleColumnUpdatesBlocked   int     `json:"single_column_updates_blocked"`
				SingleColumnRowsAffected     int64   `json:"single_column_rows_affected"`
				ReleaseUpdatesBlocked        int     `json:"release_updates_blocked"`
				BlockedReleaseIDs            []int64 `json:"blocked_release_ids"`
				BlockedStatusReleaseIDs      []int64 `json:"blocked_status_release_ids"`
				CommittedReleaseIDs          []int64 `json:"committed_release_ids"`
				SkippedReleaseIDs            []int64 `json:"skipped_release_ids"`
				LockAcquired                 bool    `json:"lock_acquired"`
				SearchSideEffectRowsEnqueued int     `json:"search_side_effect_rows_enqueued"`
				SearchUpdatesEnqueued        int     `json:"search_updates_enqueued"`
				WritesCommitted              int     `json:"writes_committed"`
			} `json:"write_commit"`
		} `json:"hashed_fixnames"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	commit := report.HashedFixnames.WriteCommit
	if report.DryRun {
		t.Fatalf("dry_run = true, want false for commit proof")
	}
	if report.NativeWorker.Job != "hashed-fixnames" || report.NativeWorker.Writes != 2 {
		t.Fatalf("native_worker = %#v", report.NativeWorker)
	}
	if !commit.LockAcquired {
		t.Fatalf("lock_acquired = false, want true")
	}
	if commit.SingleColumnUpdatesAttempted != 2 || commit.SingleColumnUpdatesCommitted != 2 || commit.SingleColumnRowsAffected != 2 {
		t.Fatalf("write_commit = %#v, want two committed miss statuses", commit)
	}
	if commit.SingleColumnUpdatesBlocked != 1 || commit.ReleaseUpdatesBlocked != 2 {
		t.Fatalf("write_commit = %#v, want blocked rename-linked status and renames", commit)
	}
	if commit.WritesCommitted != 2 {
		t.Fatalf("writes_committed = %d, want 2", commit.WritesCommitted)
	}
	if !reflect.DeepEqual(commit.CommittedReleaseIDs, []int64{102, 301}) {
		t.Fatalf("committed_release_ids = %#v, want [102 301]", commit.CommittedReleaseIDs)
	}
	if !reflect.DeepEqual(commit.BlockedReleaseIDs, []int64{300, 100}) {
		t.Fatalf("blocked_release_ids = %#v, want [300 100]", commit.BlockedReleaseIDs)
	}
	if !reflect.DeepEqual(commit.BlockedStatusReleaseIDs, []int64{100}) {
		t.Fatalf("blocked_status_release_ids = %#v, want [100]", commit.BlockedStatusReleaseIDs)
	}
	if commit.SearchSideEffectRowsEnqueued != 2 || commit.SearchUpdatesEnqueued != 2 {
		t.Fatalf("outbox counters = rows:%d updates:%d, want 2/2", commit.SearchSideEffectRowsEnqueued, commit.SearchUpdatesEnqueued)
	}

	assertWorkerHashedFixStatus(t, ctx, db, 102, "proc_crc32", 1)
	assertWorkerHashedFixStatus(t, ctx, db, 301, "proc_hash16k", 1)
	assertWorkerHashedFixStatus(t, ctx, db, 100, "proc_crc32", 0)
	assertWorkerHashedFixReleaseName(t, ctx, db, 100, "Hash.Target.CRC.PreDB", 20, 0)
	assertWorkerHashedFixReleaseName(t, ctx, db, 300, "Hash.Target.Par.Match", 20, 0)
	if exists, err := client.Exists(ctx, lockKey).Result(); err != nil {
		t.Fatalf("check redis lock release: %v", err)
	} else if exists != 0 {
		t.Fatalf("redis lock %q still exists after commit", lockKey)
	}

	stdout.Reset()
	stderr.Reset()
	code = run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--commit-miss-status",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "commit-test-owner-second",
	}, strings.NewReader(""), &stdout, &stderr)
	if code != 0 {
		t.Fatalf("second run exit = %d, stderr = %q", code, stderr.String())
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode second json report: %v; output=%q", err, stdout.String())
	}
	commit = report.HashedFixnames.WriteCommit
	if commit.WritesCommitted != 0 || commit.SingleColumnUpdatesCommitted != 0 || commit.SingleColumnRowsAffected != 0 {
		t.Fatalf("second write_commit = %#v, want idempotent zero writes", commit)
	}
	if commit.SearchSideEffectRowsEnqueued != 0 || commit.SearchUpdatesEnqueued != 0 {
		t.Fatalf("second outbox counters = rows:%d updates:%d, want zero", commit.SearchSideEffectRowsEnqueued, commit.SearchUpdatesEnqueued)
	}
	if got := workerNativeSearchSideEffectOutboxCount(t, ctx, db); got != 2 {
		t.Fatalf("native side-effect outbox rows = %d, want 2", got)
	}
}

func TestRunCommitsHashedFixNameMissStatusWithHeldRedisLock(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:hashed-fixnames"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	if err := client.Set(ctx, lockKey, "laravel-owner", time.Minute).Err(); err != nil {
		t.Fatalf("seed held lock: %v", err)
	}

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--commit-miss-status",
		"--lock-mode", "held",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-owner",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 0 {
		t.Fatalf("run exit = %d, stderr = %q", code, stderr.String())
	}
	for _, forbidden := range []string{dsn, "--mysql-dsn", "--redis-addr", "redis_key", "nntmux_database", "Hash.Target", "Known.Par"} {
		if strings.Contains(stdout.String(), forbidden) {
			t.Fatalf("held-lock commit json leaked %q: %q", forbidden, stdout.String())
		}
	}

	var report struct {
		HashedFixnames struct {
			WriteCommit struct {
				LockAcquired        bool    `json:"lock_acquired"`
				LockMode            string  `json:"lock_mode"`
				WritesCommitted     int     `json:"writes_committed"`
				CommittedReleaseIDs []int64 `json:"committed_release_ids"`
			} `json:"write_commit"`
		} `json:"hashed_fixnames"`
	}
	if err := json.Unmarshal(stdout.Bytes(), &report); err != nil {
		t.Fatalf("decode json report: %v; output=%q", err, stdout.String())
	}

	commit := report.HashedFixnames.WriteCommit
	if !commit.LockAcquired || commit.LockMode != "held" {
		t.Fatalf("write_commit lock fields = acquired:%t mode:%q, want held acquired lock", commit.LockAcquired, commit.LockMode)
	}
	if commit.WritesCommitted != 2 || !reflect.DeepEqual(commit.CommittedReleaseIDs, []int64{102, 301}) {
		t.Fatalf("write_commit = %#v, want two committed IDs", commit)
	}

	if got, err := client.Get(ctx, lockKey).Result(); err != nil {
		t.Fatalf("held lock missing after native commit: %v", err)
	} else if got != "laravel-owner" {
		t.Fatalf("held lock owner after native commit = %q, want laravel-owner", got)
	}

	assertWorkerHashedFixStatus(t, ctx, db, 102, "proc_crc32", 1)
	assertWorkerHashedFixStatus(t, ctx, db, 301, "proc_hash16k", 1)
}

func TestRunRefusesHeldLockCommitWhenOwnerDoesNotMatch(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:hashed-fixnames"
	_ = client.Del(ctx, lockKey).Err()
	defer client.Del(ctx, lockKey)

	if err := client.Set(ctx, lockKey, "other-owner", time.Minute).Err(); err != nil {
		t.Fatalf("seed held lock: %v", err)
	}

	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--commit-miss-status",
		"--lock-mode", "held",
		"--output", "json",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "laravel-owner",
	}, strings.NewReader(""), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run exit = 0, want failure; stdout=%q", stdout.String())
	}
	if !strings.Contains(stderr.String(), "native worker lock is not held by owner") {
		t.Fatalf("stderr = %q, want held-owner rejection", stderr.String())
	}
	if got, err := client.Get(ctx, lockKey).Result(); err != nil {
		t.Fatalf("held lock missing after rejected native commit: %v", err)
	} else if got != "other-owner" {
		t.Fatalf("held lock owner after rejection = %q, want other-owner", got)
	}
	assertWorkerHashedFixStatus(t, ctx, db, 102, "proc_crc32", 0)
	assertWorkerHashedFixStatus(t, ctx, db, 301, "proc_hash16k", 0)
}

func TestRunRefusesMissStatusCommitWithoutCommitGuard(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)
	fingerprintBefore := workerHashedFixTableFingerprint(t, ctx, db)
	t.Setenv(safety.AllowCommittedTestDBEnv, "")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--commit-miss-status",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "commit-test-owner",
	}, strings.NewReader(""), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run exit = 0, want safety failure")
	}
	if !strings.Contains(stderr.String(), safety.AllowCommittedTestDBEnv) {
		t.Fatalf("stderr = %q, want commit guard error", stderr.String())
	}
	if fingerprintAfter := workerHashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("unsafe commit changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunRefusesMissStatusCommitWhenRedisLockHeld(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	redisAddr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if dsn == "" || redisAddr == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN and NNTMUX_NATIVE_REDIS_ADDR are required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)
	fingerprintBefore := workerHashedFixTableFingerprint(t, ctx, db)
	t.Setenv(safety.AllowCommittedTestDBEnv, "1")

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer client.Close()
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:hashed-fixnames"
	_ = client.Del(ctx, lockKey).Err()
	held := workerlock.NewRedisLock(client, lockKey, "other-owner", 30*time.Second)
	acquired, err := held.TryAcquire(ctx)
	if err != nil {
		t.Fatalf("acquire pre-held redis lock: %v", err)
	}
	if !acquired {
		t.Fatalf("pre-held redis lock acquire = false")
	}
	defer held.Release(ctx)

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--commit-miss-status",
		"--mysql-dsn", dsn,
		"--redis-addr", redisAddr,
		"--lock-owner", "commit-test-owner",
	}, strings.NewReader(""), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run exit = 0, want lock contention failure")
	}
	if !strings.Contains(stderr.String(), "native worker lock is already held") {
		t.Fatalf("stderr = %q, want lock held error", stderr.String())
	}
	if fingerprintAfter := workerHashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("lock contention commit changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunRefusesWriteRehearsalWithoutSafeTestDBGuard(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetWorkerHashedFixTables(t, ctx, db)
	seedWorkerHashedFixRows(t, ctx, db)

	fingerprintBefore := workerHashedFixTableFingerprint(t, ctx, db)
	t.Setenv(safety.AllowDestructiveTestDBEnv, "")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--rehearse-writes",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run exit = 0, want safety failure")
	}
	if !strings.Contains(stderr.String(), safety.AllowDestructiveTestDBEnv) {
		t.Fatalf("stderr = %q, want safe test DB guard error", stderr.String())
	}
	if fingerprintAfter := workerHashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("unsafe rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRunRefusesWriteRehearsalBeforePlannerSQLWithoutSafeTestDBGuard(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native worker integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireWorkerHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	if _, err := db.ExecContext(ctx, "DROP TABLE IF EXISTS releases, release_files, predb, predb_crcs, par_hashes"); err != nil {
		t.Fatalf("drop setup tables: %v", err)
	}
	t.Setenv(safety.AllowDestructiveTestDBEnv, "")

	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--rehearse-writes",
		"--mysql-dsn", dsn,
	}, strings.NewReader(""), &stdout, &stderr)

	if code == 0 {
		t.Fatalf("run exit = 0, want safety failure")
	}
	if !strings.Contains(stderr.String(), safety.AllowDestructiveTestDBEnv) {
		t.Fatalf("stderr = %q, want safe test DB guard error", stderr.String())
	}
	if strings.Contains(stderr.String(), "build hashed-fixnames mysql dry-run") {
		t.Fatalf("stderr = %q, planner ran before safety guard", stderr.String())
	}
}

func TestRunRejectsWriteRehearsalWithoutMySQL(t *testing.T) {
	var stdout bytes.Buffer
	var stderr bytes.Buffer
	code := run([]string{
		"--plan", "../../../tests/Fixtures/native-worker/catalog/hashed-fixnames.json",
		"--dry-run",
		"--rehearse-writes",
	}, strings.NewReader(""), &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want usage failure", code)
	}
	if !strings.Contains(stderr.String(), "--rehearse-writes requires --mysql-dsn") {
		t.Fatalf("stderr = %q, want mysql-dsn requirement", stderr.String())
	}
}

func writeWorkerResolvedOracle(t *testing.T) string {
	t.Helper()

	path := t.TempDir() + "/resolved-write-contract.json"
	payload := []byte(`{
  "schema_version": 1,
  "mode": "native-write-contract-resolve",
  "dry_run": true,
  "writes": 0,
  "write_contract": {
    "release_updates_seen": 2,
    "release_updates_resolved": 2,
    "release_updates_blocked": 0,
    "resolved_release_updates": [
      {
        "release_id": 300,
        "category_resolution": {
          "group_id": 1,
          "new_name": "Known.Par.Release.2026.2160p.WEB.x265-GRP",
          "poster_present": true,
          "categories_id": 5045,
          "value_source": "CategorizationService.determineCategory(groups_id, new_title, fromname)"
        },
        "required_event": {
          "release_id": 300,
          "old_name": "Hash.Target.Par.Match",
          "new_name": "Known.Par.Release.2026.2160p.WEB.x265-GRP",
          "old_category_id": 20,
          "new_category_id": 5045,
          "group_id": 1,
          "poster_present": true
        },
        "columns": [
          {"column": "videos_id", "value": 0},
          {"column": "tv_episodes_id", "value": 0},
          {"column": "imdbid", "value": null},
          {"column": "musicinfo_id", "value": ""},
          {"column": "consoleinfo_id", "value": ""},
          {"column": "bookinfo_id", "value": ""},
          {"column": "anidbid", "value": ""},
          {"column": "predb_id", "value": 88},
          {"column": "searchname", "value": "Known.Par.Release.2026.2160p.WEB.x265-GRP"},
          {"column": "categories_id", "value": 5045},
          {"column": "isrenamed", "value": 1},
          {"column": "iscategorized", "value": 1},
          {"column": "proc_hash16k", "value": 1}
        ]
      },
      {
        "release_id": 100,
        "category_resolution": {
          "group_id": 1,
          "new_name": "Predb.Match.2026.1080p.BluRay.x264-GRP",
          "poster_present": true,
          "categories_id": 5040,
          "value_source": "CategorizationService.determineCategory(groups_id, new_title, fromname)"
        },
        "required_event": {
          "release_id": 100,
          "old_name": "Hash.Target.CRC.PreDB",
          "new_name": "Predb.Match.2026.1080p.BluRay.x264-GRP",
          "old_category_id": 20,
          "new_category_id": 5040,
          "group_id": 1,
          "poster_present": true
        },
        "columns": [
          {"column": "videos_id", "value": 0},
          {"column": "tv_episodes_id", "value": 0},
          {"column": "imdbid", "value": null},
          {"column": "musicinfo_id", "value": ""},
          {"column": "consoleinfo_id", "value": ""},
          {"column": "bookinfo_id", "value": ""},
          {"column": "anidbid", "value": ""},
          {"column": "predb_id", "value": 10},
          {"column": "searchname", "value": "Predb.Match.2026.1080p.BluRay.x264-GRP"},
          {"column": "categories_id", "value": 5040},
          {"column": "isrenamed", "value": 1},
          {"column": "iscategorized", "value": 1},
          {"column": "proc_crc32", "value": 1}
        ]
      }
    ],
    "blocked_release_updates": [],
    "single_column_update_intents": [],
    "writes": 0
  }
}`)
	if err := os.WriteFile(path, payload, 0o600); err != nil {
		t.Fatalf("write resolved oracle: %v", err)
	}

	return path
}

func resetWorkerMetadataTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS native_worker_side_effects",
		"DROP TABLE IF EXISTS predb_crcs",
		"DROP TABLE IF EXISTS predb",
		"DROP TABLE IF EXISTS release_files",
		"DROP TABLE IF EXISTS par_hashes",
		"DROP TABLE IF EXISTS releases",
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			groups_id INT NOT NULL DEFAULT 0,
			size BIGINT NOT NULL DEFAULT 0,
			postdate DATETIME NULL,
			adddate DATETIME NULL,
			fromname VARCHAR(255) NULL,
			categories_id INT NOT NULL DEFAULT 10,
			videos_id INT NOT NULL DEFAULT 0,
			tv_episodes_id INT NOT NULL DEFAULT 0,
			imdbid VARCHAR(16) NULL,
			musicinfo_id VARCHAR(32) NULL,
			consoleinfo_id VARCHAR(32) NULL,
			bookinfo_id VARCHAR(32) NULL,
			predb_id INT NOT NULL DEFAULT 0,
			anidbid VARCHAR(32) NULL,
			isrenamed TINYINT NOT NULL DEFAULT 0,
			iscategorized TINYINT NOT NULL DEFAULT 0,
			proc_crc32 TINYINT NOT NULL DEFAULT 0,
			proc_hash16k TINYINT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE par_hashes (
			releases_id INT NOT NULL,
			hash VARCHAR(32) NOT NULL DEFAULT ''
		)`,
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

func acquireWorkerMetadataIntegrationLock(t *testing.T, ctx context.Context, db *sql.DB) func() {
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

func newWorkerFakeNNTPServer(t *testing.T, handler func(string) string) net.Listener {
	t.Helper()

	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen fake NNTP: %v", err)
	}

	done := make(chan struct{})
	t.Cleanup(func() {
		_ = listener.Close()
		<-done
	})

	go func() {
		defer close(done)
		for {
			conn, err := listener.Accept()
			if err != nil {
				return
			}

			handleWorkerFakeNNTPSession(t, conn, handler)
		}
	}()

	return listener
}

func newWorkerFakeIRCServer(t *testing.T) net.Listener {
	t.Helper()

	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen fake IRC: %v", err)
	}

	done := make(chan struct{})
	t.Cleanup(func() {
		_ = listener.Close()
		<-done
	})

	go func() {
		defer close(done)
		conn, err := listener.Accept()
		if err != nil {
			return
		}
		handleWorkerFakeIRCSession(t, conn)
	}()

	return listener
}

func newWorkerSrrdbDetailsServer(t *testing.T) *httptest.Server {
	t.Helper()

	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		isSrrdbRequest := strings.HasPrefix(r.URL.Path, "/details/") || strings.HasPrefix(r.URL.Path, "/search/archive-crc:")
		if isSrrdbRequest && r.Header.Get("User-Agent") != "nntmux-external-metadata/1.0" {
			t.Fatalf("srrdb user-agent = %q", r.Header.Get("User-Agent"))
		}

		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/details/Movie.Name.2026.1080p.BluRay.x264-GRP":
			_, _ = w.Write([]byte(`{
				"name": "Movie.Name.2026.1080p.BluRay.x264-GRP",
				"files": [
					{"name": "movie.r00", "size": 123456, "crc": "1122aabb"},
					{"name": "movie.invalid", "size": 1, "crc": "not-crc"},
					{"name": "", "size": 2, "crc": "3344CCDD"}
				]
			}`))
		case "/search/archive-crc:AABBCCDD/archive-size:15000000":
			_, _ = w.Write([]byte(`{
				"results": [
					{"release": "Provider.Movie.2026.1080p.BluRay.x264-GRP"},
					{"release": "Provider.Movie.2026.1080p.BluRay.x264-GRP"},
					{"release": ""}
				]
			}`))
		case "/":
			if r.URL.Query().Get("limit") != "" {
				_, _ = w.Write([]byte(`{
					"data": [
						{"release": "PredbNet.Movie.2026.1080p-GRP"},
						{"release": "PredbNet.Movie.2026.1080p-GRP"},
						{"release": ""}
					]
				}`))
				return
			}
			if r.URL.Query().Get("count") != "" {
				_, _ = w.Write([]byte(`{
					"data": {
						"rows": [
							{"name": "PredbOvh.Movie.2026.1080p-GRP"},
							{"name": ""}
						]
					}
				}`))
				return
			}
			http.NotFound(w, r)
		case "/search/releases.json":
			if r.URL.Query().Get("p2p") == "1" {
				_, _ = w.Write([]byte(`{"list": [{"dirname": "XrelP2P.Movie.2026.1080p-GRP"}]}`))
				return
			}
			_, _ = w.Write([]byte(`{"list": [{"dirname": "Xrel.Movie.2026.1080p-GRP"}]}`))
		case "/search":
			if r.URL.Query().Get("key") != "nzbindex-secret" {
				t.Fatalf("nzbindex key = %q", r.URL.Query().Get("key"))
			}
			_, _ = w.Write([]byte(`{
				"data": {
					"content": [
						{"name": "NzbIndex.Movie.2026.1080p-GRP"},
						{"name": ""},
						{"name": "NzbIndex.Movie.2026.2160p-GRP"}
					]
				}
			}`))
		default:
			http.NotFound(w, r)
		}
	}))
}

func handleWorkerFakeIRCSession(t *testing.T, conn net.Conn) {
	t.Helper()
	defer conn.Close()

	reader := bufio.NewReader(conn)
	writer := bufio.NewWriter(conn)
	for {
		line, err := reader.ReadString('\n')
		if err != nil {
			return
		}
		line = strings.TrimRight(line, "\r\n")
		if strings.HasPrefix(line, "USER ") {
			writeWorkerIRCLine(t, writer, ":irc.example.test 001 nntmuxbot :welcome")
			break
		}
	}

	line, err := reader.ReadString('\n')
	if err != nil {
		return
	}
	if strings.TrimRight(line, "\r\n") != "JOIN #PreNNTmux" {
		t.Fatalf("fake IRC got %q, want JOIN #PreNNTmux", strings.TrimRight(line, "\r\n"))
	}

	writeWorkerIRCLine(t, writer, ":prebot!bot@example PRIVMSG #PreNNTmux :NEW: [DT: 2026-06-17 12:34:56] [TT: New.Movie.2026-GRP] [SC: #a.b.movies] [CT: MOVIE] [RQ: 44:alt.binaries.movies] [SZ: 8 GB] [FL: 10F] [FN: new.r00]")
	writeWorkerIRCLine(t, writer, ":prebot!bot@example PRIVMSG #PreNNTmux :NUK: [DT: 2026-06-17 13:00:00] [TT: Existing.Movie.2025-GRP] [SC: srrdb] [CT: NEW-CAT] [RQ: 45:alt.binaries.tv] [SZ: 7 GB] [FL: 2F] [FN: existing.r00] [NUKED: bad.pack]")
}

func writeWorkerIRCLine(t *testing.T, writer *bufio.Writer, line string) {
	t.Helper()
	if _, err := writer.WriteString(line + "\r\n"); err != nil {
		t.Fatalf("write fake IRC line: %v", err)
	}
	if err := writer.Flush(); err != nil {
		t.Fatalf("flush fake IRC line: %v", err)
	}
}

func handleWorkerFakeNNTPSession(t *testing.T, conn net.Conn, handler func(string) string) {
	t.Helper()
	defer conn.Close()

	reader := bufio.NewReader(conn)
	writer := bufio.NewWriter(conn)
	writeWorkerNNTPLine(t, writer, "200 fake nntp ready")
	for {
		line, err := reader.ReadString('\n')
		if err != nil {
			return
		}
		line = strings.TrimRight(line, "\r\n")
		response := handler(line)
		writeWorkerNNTPLine(t, writer, response)
		if line == "QUIT" {
			return
		}
	}
}

func writeWorkerNNTPLine(t *testing.T, writer *bufio.Writer, line string) {
	t.Helper()
	if _, err := writer.WriteString(line + "\r\n"); err != nil {
		t.Fatalf("write fake NNTP line: %v", err)
	}
	if err := writer.Flush(); err != nil {
		t.Fatalf("flush fake NNTP line: %v", err)
	}
}

func seedWorkerMetadataRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO predb (id, title, source) VALUES
			(1, 'Movie.Name.2026.1080p.BluRay.x264-GRP', 'srrdb'),
			(2, 'Other.Source.2026.1080p.WEB.x264-GRP', 'predb-net')`,
		`INSERT INTO predb_crcs (predb_id, crchash, filesize) VALUES
			(2, 'DDCCBBAA', 15000000)`,
		`INSERT INTO release_files (releases_id, name, size, crc32, created_at) VALUES
			(10, 'Movie.Name.2026.1080p.BluRay.x264-GRP.r00', 15000000, 'aabbccdd', '2026-06-15 12:00:00'),
			(11, 'Existing.CRC.No.Signal-GRP.r00', 15000000, 'DDCCBBAA', '2026-06-15 12:00:01')`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func workerMetadataTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
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

func acquireWorkerHashedFixIntegrationLock(t *testing.T, ctx context.Context, db *sql.DB) func() {
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

func resetWorkerHashedFixTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	if err := testdb.ResetHashedFixTables(ctx, db); err != nil {
		t.Fatal(err)
	}
}

func seedWorkerHashedFixRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	if err := testdb.SeedHashedFixRows(ctx, db); err != nil {
		t.Fatal(err)
	}
}

func seedWorkerRegularFixRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO predb (id, title, predate, source) VALUES
			(60, 'Regular.CRC.Predb.2026.1080p.BluRay.x264-GRP', NOW(), 'srrdb')`,
		`INSERT INTO predb_crcs (predb_id, crchash, filesize) VALUES
			(60, 'FEEDBEEF', 12345678)`,
		`INSERT INTO releases (id, name, searchname, groups_id, size, postdate, adddate, fromname, categories_id, predb_id, anidbid, isrenamed, proc_crc32, proc_hash16k) VALUES
			(710, 'regular-crc-predb', 'Regular.CRC.Target', 1, 12345678, NOW(), NOW(), 'poster@example', 10, 0, 0, 0, 0, 0),
			(711, 'regular-crc-miss', 'Regular.CRC.Miss', 1, 12345679, NOW(), NOW(), 'poster@example', 10, 0, 0, 0, 0, 0),
			(720, 'regular-par-match', 'Regular.Par.Target', 1, 50000000, NOW(), NOW(), 'poster@example', 10, 0, 0, 0, 0, 0),
			(721, 'regular-par-miss', 'Regular.Par.Miss', 1, 51000000, NOW(), NOW(), 'poster@example', 10, 0, 0, 0, 0, 0),
			(730, 'regular-known-par', 'Regular.Par.Known.2026.2160p.WEB.x265-GRP', 1, 50100000, NOW(), NOW(), 'poster@example', 5040, 90, 0, 1, 1, 1)`,
		`INSERT INTO release_files (releases_id, name, size, crc32, created_at) VALUES
			(710, 'Regular.CRC.Predb.2026.1080p.BluRay.x264-GRP.rar', 12345678, 'feedbeef', NOW()),
			(711, 'Regular.CRC.Miss.2026.1080p.BluRay.x264-GRP.r00', 12345679, '00112233', NOW())`,
		`INSERT INTO par_hashes (releases_id, hash) VALUES
			(720, 'regular-par-match-hash'),
			(721, 'regular-par-miss-hash'),
			(730, 'regular-par-match-hash')`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed regular fixnames %q: %v", statement, err)
		}
	}
}

func prepareWorkerSearchDocumentTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"CREATE TABLE IF NOT EXISTS movieinfo (id INT NOT NULL PRIMARY KEY, tmdbid INT NOT NULL DEFAULT 0, traktid INT NOT NULL DEFAULT 0)",
		"CREATE TABLE IF NOT EXISTS videos (id INT NOT NULL PRIMARY KEY, tvdb INT NOT NULL DEFAULT 0, tvmaze INT NOT NULL DEFAULT 0, tvrage INT NOT NULL DEFAULT 0)",
		"ALTER TABLE releases ADD COLUMN IF NOT EXISTS totalpart INT NOT NULL DEFAULT 0",
		"ALTER TABLE releases ADD COLUMN IF NOT EXISTS grabs INT NOT NULL DEFAULT 0",
		"ALTER TABLE releases ADD COLUMN IF NOT EXISTS passwordstatus INT NOT NULL DEFAULT 0",
		"ALTER TABLE releases ADD COLUMN IF NOT EXISTS nzbstatus INT NOT NULL DEFAULT 0",
		"ALTER TABLE releases ADD COLUMN IF NOT EXISTS haspreview INT NOT NULL DEFAULT 0",
		"ALTER TABLE releases ADD COLUMN IF NOT EXISTS movieinfo_id INT NOT NULL DEFAULT 0",
		"INSERT INTO movieinfo (id, tmdbid, traktid) VALUES (5001, 7001, 8001) ON DUPLICATE KEY UPDATE tmdbid = VALUES(tmdbid), traktid = VALUES(traktid)",
		"INSERT INTO videos (id, tvdb, tvmaze, tvrage) VALUES (6001, 9001, 9002, 9003) ON DUPLICATE KEY UPDATE tvdb = VALUES(tvdb), tvmaze = VALUES(tvmaze), tvrage = VALUES(tvrage)",
		"UPDATE releases SET movieinfo_id = 5001, videos_id = 6001, totalpart = 42, grabs = 3, passwordstatus = 0, nzbstatus = 1, haspreview = 0 WHERE id IN (102, 301)",
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec search document prep %q: %v", statement, err)
		}
	}
}

func resetWorkerSearchDocumentParityOnlyTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS par_hashes",
		"DROP TABLE IF EXISTS predb_crcs",
		"DROP TABLE IF EXISTS predb",
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
			t.Fatalf("exec parity-only schema statement %q: %v", statement, err)
		}
	}
}

func seedWorkerSearchDocumentParityOnlyRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"INSERT INTO movieinfo (id, tmdbid, traktid) VALUES (9201, 9202, 9203)",
		"INSERT INTO videos (id, tvdb, tvmaze, tvrage) VALUES (9301, 9302, 9303, 9304)",
		`INSERT INTO releases (
			id, name, searchname, fromname, categories_id, size, postdate, adddate,
			totalpart, grabs, passwordstatus, groups_id, nzbstatus, haspreview,
			imdbid, videos_id, movieinfo_id
		) VALUES (
			92001, 'Parity.Only.Release.2026.1080p-GRP', 'Parity.Only.Release.2026.1080p-GRP',
			'parity@example.invalid', 5040, 987654321, '2026-06-15 09:00:00',
			'2026-06-15 10:00:00', 42, 3, 0, 101, 1, 0, 'tt9200123', 9301, 9201
		)`,
		"INSERT INTO release_files (releases_id, name) VALUES (92001, 'parity.only.sample.mkv')",
		`INSERT INTO native_worker_side_effects (
			operation_key, job, effect, release_id, status_column, status_reason,
			status_value, status, attempts, available_at, created_at, updated_at
		) VALUES (
			'hashed-fixnames:miss-status:v1:92001:proc_hash16k:1:par-hash-miss',
			'hashed-fixnames', 'release-search-sync', 92001, 'proc_hash16k',
			'par-hash-miss', 1, 'pending', 0, NULL, NOW(), NOW()
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec parity-only seed statement %q: %v", statement, err)
		}
	}
}

func resetWorkerBinariesTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()
	if err := testdb.ResetBinariesTables(ctx, db); err != nil {
		t.Fatal(err)
	}
}

func seedWorkerBinariesRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()
	if err := testdb.SeedBinariesRows(ctx, db); err != nil {
		t.Fatal(err)
	}
}

func resetWorkerReleaseQueueTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()
	if err := testdb.ResetReleaseQueueTables(ctx, db); err != nil {
		t.Fatal(err)
	}
}

func seedWorkerReleaseQueueRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()
	if err := testdb.SeedReleaseQueueRows(ctx, db); err != nil {
		t.Fatal(err)
	}
}

func resetWorkerPerGroupQueueTables(t *testing.T, ctx context.Context, db *sql.DB) {
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

func seedWorkerPerGroupQueueRows(t *testing.T, ctx context.Context, db *sql.DB) {
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

func resetWorkerBackfillTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()
	if err := testdb.ResetBackfillTables(ctx, db); err != nil {
		t.Fatal(err)
	}
}

func seedWorkerBackfillRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()
	if err := testdb.SeedBackfillRows(ctx, db); err != nil {
		t.Fatal(err)
	}
}

func resetWorkerRemoveCrapTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS binaryblacklist",
		"DROP TABLE IF EXISTS native_worker_side_effects",
		"DROP TABLE IF EXISTS collections",
		"DROP TABLE IF EXISTS release_files",
		"DROP TABLE IF EXISTS releases",
		"DROP TABLE IF EXISTS settings",
		"DROP TABLE IF EXISTS usenet_groups",
		`CREATE TABLE settings (
			name VARCHAR(255) PRIMARY KEY,
			value TEXT NULL
		)`,
		`CREATE TABLE usenet_groups (
			id INT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE binaryblacklist (
			id INT PRIMARY KEY,
			groupname VARCHAR(255) NOT NULL DEFAULT '',
			regex VARCHAR(255) NOT NULL DEFAULT '',
			msgcol INT NOT NULL DEFAULT 1,
			optype INT NOT NULL DEFAULT 1,
			status INT NOT NULL DEFAULT 1
		)`,
		`CREATE TABLE native_worker_side_effects (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			guid VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			fromname VARCHAR(255) NOT NULL DEFAULT '',
			groups_id INT NOT NULL DEFAULT 0,
			categories_id INT NOT NULL DEFAULT 0,
			passwordstatus INT NOT NULL DEFAULT 0,
			nfostatus TINYINT NOT NULL DEFAULT 0,
			haspreview INT NOT NULL DEFAULT 0,
			jpgstatus INT NOT NULL DEFAULT 0,
			predb_id INT NOT NULL DEFAULT 0,
			videostatus INT NOT NULL DEFAULT 0,
			imdbid VARCHAR(32) NULL,
			isrenamed TINYINT NOT NULL DEFAULT 0,
			iscategorized TINYINT NOT NULL DEFAULT 0,
			rarinnerfilecount INT NOT NULL DEFAULT 0,
			totalpart INT NOT NULL DEFAULT 0,
			size BIGINT NOT NULL DEFAULT 0,
			adddate DATETIME NOT NULL
		)`,
		`CREATE TABLE release_files (
			id INT AUTO_INCREMENT PRIMARY KEY,
			releases_id INT NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			passworded INT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE collections (
			id INT AUTO_INCREMENT PRIMARY KEY,
			groups_id INT NOT NULL DEFAULT 0,
			releases_id INT NULL
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func seedWorkerRemoveCrapRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES
			('minsizetoformrelease', '2097152')`,
		`INSERT INTO usenet_groups (id, name) VALUES
			(88, 'alt.binaries.movies'),
			(89, 'alt.binaries.tv')`,
		`INSERT INTO binaryblacklist (id, groupname, regex, msgcol, optype, status) VALUES
			(1, 'alt[.]binaries[.]movies', 'badcodec[.]dat', 1, 1, 1),
			(2, 'alt.binaries.*', 'disabledbad[.]dat', 1, 1, 0),
			(3, 'alt.binaries.*', 'whitelistbad[.]dat', 1, 2, 1),
			(4, 'alt.binaries.*', 'frombad[.]dat', 2, 1, 1),
			(5, 'alt[.]binaries[.]movies', 'Bad[.]Subject', 1, 1, 1),
			(6, 'alt.binaries.*', 'BadPoster', 2, 1, 1),
			(7, 'alt.binaries.*', 'Disabled[.]Subject', 1, 1, 0),
			(8, 'alt.binaries.*', 'Whitelist[.]Subject', 1, 2, 1)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, iscategorized, rarinnerfilecount, adddate) VALUES
			(100, 'guid-gibberish', 'ABCDEFGHIJKLMNOP', 'ABCDEFGHIJKLMNOP', 2000, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(101, 'guid-old-gibberish', 'QRSTUVWXYZABCDE', 'QRSTUVWXYZABCDE', 2000, 0, 0, 1, 0, NOW() - INTERVAL 12 HOUR),
			(102, 'guid-hashed-category', 'ABCDEFGHIJKLMNOPQ', 'ABCDEFGHIJKLMNOPQ', 20, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(103, 'guid-not-categorized', 'ABCDEFGHIJKLMNOPR', 'ABCDEFGHIJKLMNOPR', 2000, 0, 0, 0, 0, NOW() - INTERVAL 1 HOUR),
			(200, 'guid-executable', 'Movie.Release.2026', 'Movie.Release.2026', 2040, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(201, 'guid-pc-game-exe', 'Game.Release.2026', 'Game.Release.2026', 4050, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(202, 'guid-old-exe', 'Old.Release.2026', 'Old.Release.2026', 2040, 0, 0, 1, 0, NOW() - INTERVAL 12 HOUR)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, iscategorized, rarinnerfilecount, totalpart, size, adddate) VALUES
			(300, 'guid-hashed', 'ABCDEFGHIJKLMNOPQRSTUVWXY', 'ABCDEFGHIJKLMNOPQRSTUVWXY', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(301, 'guid-hashed-misc', 'ABCDEFGHIJKLMNOPQRSTUVWXY1', 'ABCDEFGHIJKLMNOPQRSTUVWXY1', 10, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(310, 'guid-short', 'AB12', 'AB12', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(311, 'guid-short-misc', 'AB13', 'AB13', 10, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(320, 'guid-installbin', 'Install.Bin.Release', 'Install.Bin.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(321, 'guid-passwordurl', 'URL.File.Release', 'URL.File.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(322, 'guid-nzb', 'Single.Nzb.Release', 'Single.Nzb.Release', 2000, 0, 0, 1, 0, 1, 3000000, NOW() - INTERVAL 1 HOUR),
			(323, 'guid-scr', 'Screen.Saver.Release', 'Screen.Saver.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(330, 'guid-passwordstatus', 'Password.Status.Release', 'Password.Status.Release', 2000, 1, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(331, 'guid-password-file', 'Password.File.Release', 'Password.File.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(332, 'guid-password-false-positive', 'No password Release', 'No password Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(340, 'guid-sample', 'Movie.sample.avi', 'Movie.Sample.Release', 2040, 0, 0, 1, 0, 2, 20000000, NOW() - INTERVAL 1 HOUR),
			(341, 'guid-sample-large', 'Movie.sample.large.avi', 'Movie.Sample.Large.Release', 2040, 0, 0, 1, 0, 2, 50000000, NOW() - INTERVAL 1 HOUR),
			(350, 'guid-size', 'Tiny.Release', 'Tiny.Release', 2000, 0, 0, 1, 0, 1, 1000000, NOW() - INTERVAL 1 HOUR),
			(351, 'guid-size-music', 'Tiny.Music.Release', 'Tiny.Music.Release', 3010, 0, 0, 1, 0, 1, 1000000, NOW() - INTERVAL 1 HOUR)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, haspreview, jpgstatus, predb_id, videostatus, imdbid, iscategorized, rarinnerfilecount, totalpart, size, adddate) VALUES
			(360, 'guid-codec', 'Movie.Codec.Release', 'Movie.Codec.Release', 2040, 0, 1, 0, 0, 0, 0, 'tt1234567', 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(361, 'guid-codec-no-imdb', 'Movie.Codec.No.Imdb', 'Movie.Codec.No.Imdb', 2040, 0, 1, 0, 0, 0, 0, '', 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(362, 'guid-codec-preview', 'Movie.Codec.Preview', 'Movie.Codec.Preview', 2040, 0, 1, 1, 0, 0, 0, 'tt7654321', 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, iscategorized, rarinnerfilecount, totalpart, size, adddate) VALUES
			(380, 'guid-blfiles', 'Blacklist.Files.Release', 'Blacklist.Files.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(381, 'guid-blfiles-wrong-group', 'Blacklist.Files.Wrong.Group', 'Blacklist.Files.Wrong.Group', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(382, 'guid-blfiles-disabled', 'Blacklist.Files.Disabled', 'Blacklist.Files.Disabled', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(383, 'guid-blfiles-whitelist', 'Blacklist.Files.Whitelist', 'Blacklist.Files.Whitelist', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(384, 'guid-blfiles-from', 'Blacklist.Files.From', 'Blacklist.Files.From', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(385, 'guid-blfiles-old', 'Blacklist.Files.Old', 'Blacklist.Files.Old', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(390, 'guid-blacklist-subject', 'Bad.Subject.Release', 'Bad.Subject.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(391, 'guid-blacklist-wrong-group', 'Bad.Subject.Wrong.Group', 'Bad.Subject.Wrong.Group', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(392, 'guid-blacklist-poster', 'Poster.Blacklist.Release', 'Poster.Blacklist.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(393, 'guid-blacklist-disabled', 'Disabled.Subject.Release', 'Disabled.Subject.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(394, 'guid-blacklist-whitelist', 'Whitelist.Subject.Release', 'Whitelist.Subject.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(395, 'guid-blacklist-old', 'Bad.Subject.Old', 'Bad.Subject.Old', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(400, 'guid-par2-searchname', 'Only.Par2.par2_', 'Only.Par2.par2_', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(401, 'guid-par2-searchname-mixed', 'Mixed.Par2.par2_', 'Mixed.Par2.par2_', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(402, 'guid-par2-files-only', 'All.Files.Are.Repair', 'All.Files.Are.Repair', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(403, 'guid-par2-files-mixed', 'Mixed.Files.Release', 'Mixed.Files.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(404, 'guid-par2-old', 'Old.Par2.par2_', 'Old.Par2.par2_', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(405, 'guid-par2-hashed-nzb', 'Hashed.Par2.NZB', 'Hashed.Par2.NZB', 20, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(406, 'guid-par2-hashed-content', 'Hashed.Mixed.NZB', 'Hashed.Mixed.NZB', 20, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR)`,
		`UPDATE releases SET groups_id = 88 WHERE id IN (380, 382, 383, 384, 385, 390, 392, 393, 394, 395)`,
		`UPDATE releases SET groups_id = 89 WHERE id IN (381, 391)`,
		`UPDATE releases SET fromname = 'BadPoster' WHERE id = 392`,
		`INSERT INTO release_files (releases_id, name, passworded) VALUES
			(200, 'setup.exe', 0),
			(200, 'bonus.exe', 0),
			(201, 'game.exe', 0),
			(202, 'old.exe', 0),
			(320, 'install.bin', 0),
			(321, 'password.url', 0),
			(322, 'release.nzb', 0),
			(323, 'danger.scr ', 0),
			(331, 'archive.rar', 1),
			(360, 'XviD-abc.avi', 0),
			(361, 'XviD-def.avi', 0),
			(362, 'XviD-ghi.avi', 0),
			(380, 'badcodec.dat', 0),
			(381, 'badcodec.dat', 0),
			(382, 'disabledbad.dat', 0),
			(383, 'whitelistbad.dat', 0),
			(384, 'frombad.dat', 0),
			(385, 'badcodec.dat', 0),
			(400, 'volume.par2', 0),
			(400, 'volume.vol000+001.par2', 0),
			(401, 'volume.par2', 0),
			(401, 'movie.mkv', 0),
			(402, 'repair.par2', 0),
			(402, 'repair.vol000+001.par2', 0),
			(403, 'repair.par2', 0),
			(403, 'archive.rar', 0),
			(404, 'old.par2', 0)`,
		`INSERT INTO collections (id, groups_id, releases_id) VALUES
			(1, 10, 100),
			(2, 10, 200),
			(3, 10, 201),
			(4, 10, NULL)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}

	nzbRoot := t.TempDir()
	t.Setenv("PATH_TO_NZBS", nzbRoot)
	writeWorkerGzippedNZB(t, nzbRoot, "guid-par2-hashed-nzb", []string{
		`[1/2] "repair.par2" yEnc`,
		`[2/2] "repair.vol000+001.par2" yEnc`,
	})
	writeWorkerGzippedNZB(t, nzbRoot, "guid-par2-hashed-content", []string{
		`[1/2] "repair.par2" yEnc`,
		`[2/2] "movie.mkv" yEnc`,
	})
}

func resetWorkerPostprocessTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS categories",
		"DROP TABLE IF EXISTS releases",
		"DROP TABLE IF EXISTS settings",
		`CREATE TABLE settings (
			name VARCHAR(255) PRIMARY KEY,
			value TEXT NULL
		)`,
		`CREATE TABLE categories (
			id INT PRIMARY KEY,
			disablepreview TINYINT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			guid VARCHAR(64) NOT NULL DEFAULT '',
			leftguid VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			groups_id INT NOT NULL DEFAULT 0,
			categories_id INT NOT NULL DEFAULT 0,
			passwordstatus INT NOT NULL DEFAULT 0,
			haspreview INT NOT NULL DEFAULT 0,
			nzbstatus INT NOT NULL DEFAULT 1,
			nfostatus INT NOT NULL DEFAULT 0,
			videos_id INT NOT NULL DEFAULT 0,
			tv_episodes_id INT NOT NULL DEFAULT 0,
			size BIGINT NOT NULL DEFAULT 0,
			isrenamed TINYINT NOT NULL DEFAULT 0,
			anidbid INT NULL,
			imdbid VARCHAR(16) NULL,
			movieinfo_id INT NULL,
			bookinfo_id INT NULL,
			musicinfo_id INT NULL,
			consoleinfo_id INT NULL,
			gamesinfo_id INT NULL DEFAULT 0
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func seedWorkerPostTVRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES
			('lookuptv', '1'),
			('lookupanidb', '1'),
			('postthreadsnon', '3')`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, categories_id, videos_id, tv_episodes_id, size, isrenamed, anidbid) VALUES
			(100, 'guid-tv-a', 'A-tv-eligible-1', 'TV.Release.A', 'TV.Release.A', 5000, 0, -1, 2097152, 0, NULL),
			(101, 'guid-tv-b', 'b-tv-eligible-2', 'TV.Release.B', 'TV.Release.B', 5020, 0, 0, 3145728, 1, NULL),
			(102, 'guid-tv-a-duplicate', 'A-tv-eligible-duplicate', 'TV.Release.A2', 'TV.Release.A2', 5030, 0, -2, 4194304, 0, NULL),
			(103, 'guid-tv-anime-category', 'x-tv-anime-category', 'TV.Anime.Category', 'TV.Anime.Category', 5070, 0, -1, 2097152, 1, 99999),
			(104, 'guid-tv-has-video', 'y-tv-has-video', 'TV.Has.Video', 'TV.Has.Video', 5000, 7, -1, 2097152, 1, NULL),
			(105, 'guid-tv-too-small', 'z-tv-too-small', 'TV.Too.Small', 'TV.Too.Small', 5000, 0, -1, 1048576, 1, NULL),
			(106, 'guid-tv-episode-linked', 'w-tv-episode-linked', 'TV.Episode.Linked', 'TV.Episode.Linked', 5000, 0, 1, 2097152, 1, NULL),
			(107, 'guid-tv-wrong-category', 'v-tv-wrong-category', 'TV.Wrong.Category', 'TV.Wrong.Category', 6000, 0, -1, 2097152, 1, NULL),
			(200, 'guid-anime-c', 'c-anime-eligible', 'Anime.Release.C', 'Anime.Release.C', 5070, 0, 0, 2097152, 0, NULL),
			(201, 'guid-anime-known', 'd-anime-known', 'Anime.Release.D', 'Anime.Release.D', 5070, 0, 0, 2097152, 0, 12345)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func seedWorkerPostMovieRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES
			('lookupimdb', '1'),
			('postthreadsnon', '3')`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, categories_id, imdbid, movieinfo_id, isrenamed) VALUES
			(300, 'guid-movie-m', 'm-movie-pending', 'Movie.Release.M', 'Movie.Release.M', 2040, NULL, NULL, 0),
			(301, 'guid-movie-n', 'n-movie-repair', 'Movie.Release.N', 'Movie.Release.N', 2080, '1234567', 0, 1),
			(302, 'guid-movie-duplicate', 'm-movie-duplicate', 'Movie.Release.M2', 'Movie.Release.M2', 2010, '00000000', NULL, 0),
			(303, 'guid-movie-empty-imdb', 'x-movie-empty-imdb', 'Movie.Release.Empty', 'Movie.Release.Empty', 2040, '', NULL, 1),
			(304, 'guid-movie-linked', 'y-movie-linked', 'Movie.Release.Linked', 'Movie.Release.Linked', 2040, '7654321', 55, 1),
			(305, 'guid-movie-wrong-category', 'z-movie-wrong-category', 'Movie.Release.Wrong', 'Movie.Release.Wrong', 3000, NULL, NULL, 1)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func seedWorkerPostAmazonRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES
			('lookupbooks', '1'),
			('lookupmusic', '1'),
			('lookupgames', '1'),
			('postthreadsamazon', '4')`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, categories_id, bookinfo_id, musicinfo_id, consoleinfo_id, gamesinfo_id, isrenamed) VALUES
			(400, 'guid-book-b', 'B-book-eligible', 'Book.Release.B', 'Book.Release.B', 7010, NULL, NULL, NULL, 0, 0),
			(401, 'guid-book-q', 'q-book-nzb', 'N:/NZB/book-q', 'N:/NZB/book-q', 3030, 77, NULL, NULL, 0, 0),
			(402, 'guid-book-linked', 'r-book-linked', 'Book.Release.Linked', 'Book.Release.Linked', 7020, 77, NULL, NULL, 0, 0),
			(410, 'guid-music-m', 'M-music-eligible', 'Music.Release.M', 'Music.Release.M', 3010, NULL, NULL, NULL, 0, 0),
			(411, 'guid-music-n', 'N-music-eligible', 'Music.Release.N', 'Music.Release.N', 3040, NULL, NULL, NULL, 0, 0),
			(412, 'guid-music-video', 'o-music-video', 'Music.Release.Video', 'Music.Release.Video', 3020, NULL, NULL, NULL, 0, 0),
			(413, 'guid-music-linked', 'p-music-linked', 'Music.Release.Linked', 'Music.Release.Linked', 3010, NULL, 9001, NULL, 0, 0),
			(420, 'guid-console-c', 'C-console-eligible', 'Console.Release.C', 'Console.Release.C', 1010, NULL, NULL, NULL, 0, 0),
			(421, 'guid-console-d', 'D-console-renamed', 'Console.Release.D', 'Console.Release.D', 1180, NULL, NULL, NULL, 0, 1),
			(422, 'guid-console-linked', 'e-console-linked', 'Console.Release.Linked', 'Console.Release.Linked', 1080, NULL, NULL, 9101, 0, 1),
			(430, 'guid-game-g', 'G-game-eligible', 'Game.Release.G', 'Game.Release.G', 4050, NULL, NULL, NULL, 0, 0),
			(431, 'guid-game-h', 'H-game-renamed', 'Game.Release.H', 'Game.Release.H', 4050, NULL, NULL, NULL, 0, 1),
			(432, 'guid-game-linked', 'i-game-linked', 'Game.Release.Linked', 'Game.Release.Linked', 4050, NULL, NULL, NULL, 44, 1),
			(433, 'guid-game-null-info', 'j-game-null-info', 'Game.Release.NullInfo', 'Game.Release.NullInfo', 4050, NULL, NULL, NULL, NULL, 1)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func seedWorkerPostAdditionalRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES
			('postthreads', '5'),
			('nfothreads', '2'),
			('lookupnfo', '1'),
			('minsizetopostprocess', '1'),
			('maxsizetopostprocess', '100'),
			('minsizetoprocessnfo', '1'),
			('maxsizetoprocessnfo', '2'),
			('maxnforetries', '7')`,
		`INSERT INTO categories (id, disablepreview) VALUES
			(2000, 0),
			(3000, 1)`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, groups_id, categories_id, passwordstatus, haspreview, nzbstatus, nfostatus, size) VALUES
			(600, 'guid-add-a', 'a-add-eligible', 'Additional.Release.A', 'Additional.Release.A', 1, 2000, -1, -1, 1, 0, 2097152),
			(601, 'guid-add-b', 'B-add-eligible-large', 'Additional.Release.B', 'Additional.Release.B', 1, 2000, -1, -1, 1, 0, 31457280),
			(602, 'guid-add-a-duplicate', 'a-add-duplicate', 'Additional.Release.A2', 'Additional.Release.A2', 1, 2000, -1, -1, 1, 0, 3145728),
			(607, 'guid-add-blank-leftguid', '', 'Additional.Release.Blank', 'Additional.Release.Blank', 1, 2000, -1, -1, 1, 0, 4194304),
			(603, 'guid-add-too-small', 'c-add-too-small', 'Additional.Release.Small', 'Additional.Release.Small', 1, 2000, -1, -1, 1, 0, 1048576),
			(604, 'guid-add-preview-disabled', 'd-add-preview-disabled', 'Additional.Release.Disabled', 'Additional.Release.Disabled', 1, 3000, -1, -1, 1, 0, 4194304),
			(605, 'guid-add-already-previewed', 'e-add-previewed', 'Additional.Release.Previewed', 'Additional.Release.Previewed', 1, 2000, -1, 0, 1, 0, 4194304),
			(606, 'guid-add-missing-nzb', 'f-add-missing-nzb', 'Additional.Release.NoNZB', 'Additional.Release.NoNZB', 1, 2000, -1, -1, 0, 0, 4194304),
			(700, 'guid-nfo-n', 'N-nfo-eligible', 'NFO.Release.N', 'NFO.Release.N', 1, 2000, 0, 0, 1, -1, 2097152),
			(701, 'guid-nfo-o', 'o-nfo-retry', 'NFO.Release.O', 'NFO.Release.O', 1, 2000, 0, 0, 1, -8, 3145728),
			(702, 'guid-nfo-n-duplicate', 'N-nfo-duplicate', 'NFO.Release.N2', 'NFO.Release.N2', 1, 2000, 0, 0, 1, -2, 4194304),
			(703, 'guid-nfo-exhausted', 'p-nfo-exhausted', 'NFO.Release.Exhausted', 'NFO.Release.Exhausted', 1, 2000, 0, 0, 1, -9, 4194304),
			(704, 'guid-nfo-too-small', 'q-nfo-too-small', 'NFO.Release.Small', 'NFO.Release.Small', 1, 2000, 0, 0, 1, -1, 1048576),
			(705, 'guid-nfo-too-large', 'r-nfo-too-large', 'NFO.Release.Large', 'NFO.Release.Large', 1, 2000, 0, 0, 1, -1, 2147483648)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func hasColumnSource(columns []struct {
	Column      string `json:"column"`
	Value       any    `json:"value,omitempty"`
	ValueSource string `json:"value_source,omitempty"`
}, column string, valueSource string) bool {
	for _, candidate := range columns {
		if candidate.Column == column && candidate.ValueSource == valueSource {
			return true
		}
	}

	return false
}

func workerHashedFixTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	fingerprint, err := testdb.HashedFixTableFingerprint(ctx, db)
	if err != nil {
		t.Fatal(err)
	}

	return fingerprint
}

func workerNativeSearchSideEffectOutboxCount(t *testing.T, ctx context.Context, db *sql.DB) int {
	return workerNativeSearchSideEffectOutboxCountForJob(t, ctx, db, "hashed-fixnames")
}

func workerNativeSearchSideEffectOutboxCountForJob(t *testing.T, ctx context.Context, db *sql.DB, job string) int {
	t.Helper()

	var count int
	if err := db.QueryRowContext(ctx, "SELECT COUNT(*) FROM native_worker_side_effects WHERE job = ? AND effect = 'release-search-sync'", job).Scan(&count); err != nil {
		t.Fatalf("count native search side-effect outbox rows: %v", err)
	}

	return count
}

func assertWorkerHashedFixStatus(t *testing.T, ctx context.Context, db *sql.DB, releaseID int64, column string, want int) {
	t.Helper()

	var got int
	if err := db.QueryRowContext(ctx, "SELECT "+column+" FROM releases WHERE id = ?", releaseID).Scan(&got); err != nil {
		t.Fatalf("read release %d %s: %v", releaseID, column, err)
	}
	if got != want {
		t.Fatalf("release %d %s = %d, want %d", releaseID, column, got, want)
	}
}

func assertWorkerHashedFixReleaseName(t *testing.T, ctx context.Context, db *sql.DB, releaseID int64, wantSearchName string, wantCategoryID int, wantPreDBID int) {
	t.Helper()

	var searchName string
	var categoryID int
	var preDBID int
	if err := db.QueryRowContext(ctx, "SELECT searchname, categories_id, predb_id FROM releases WHERE id = ?", releaseID).Scan(&searchName, &categoryID, &preDBID); err != nil {
		t.Fatalf("read release %d name/category/predb: %v", releaseID, err)
	}
	if searchName != wantSearchName || categoryID != wantCategoryID || preDBID != wantPreDBID {
		t.Fatalf("release %d = (%q,%d,%d), want (%q,%d,%d)", releaseID, searchName, categoryID, preDBID, wantSearchName, wantCategoryID, wantPreDBID)
	}
}

func workerBinariesTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
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

func workerReleaseQueueTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"settings":      "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', name, COALESCE(value, '')) ORDER BY name SEPARATOR '|'), '') FROM settings",
		"usenet_groups": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name, active, backfill, COALESCE(DATE_FORMAT(last_updated, '%Y-%m-%d %H:%i:%s.%f'), '')) ORDER BY id SEPARATOR '|'), '') FROM usenet_groups",
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

func workerPerGroupQueueTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
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

func workerBackfillTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
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

func createWorkerHeaderRehearsalTables(t *testing.T, ctx context.Context, db *sql.DB) {
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

func workerHeaderWriteFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
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

func workerHeaderBackfillWriteFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
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

func resetWorkerIRCTables(t *testing.T, ctx context.Context, db *sql.DB) {
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

func seedWorkerIRCPredbRows(t *testing.T, ctx context.Context, db *sql.DB) {
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

func workerIRCTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) string {
	t.Helper()

	var value string
	if err := db.QueryRowContext(ctx, "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, title, source, COALESCE(category, ''), COALESCE(size, ''), requestid, groups_id, nuked, COALESCE(nukereason, ''), COALESCE(files, ''), filename) ORDER BY id SEPARATOR '|'), '') FROM predb").Scan(&value); err != nil {
		t.Fatalf("fingerprint predb: %v", err)
	}

	return value
}

func writeWorkerIRCSample(t *testing.T) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "irc-sample.log")
	lines := []string{
		":prebot!bot@example PRIVMSG #PreNNTmux :NEW: [DT: 2026-06-17 12:34:56] [TT: New.Movie.2026-GRP] [SC: #a.b.movies] [CT: MOVIE] [RQ: 44:alt.binaries.movies] [SZ: 8 GB] [FL: 10F] [FN: new.r00]",
		":prebot!bot@example PRIVMSG #PreNNTmux :NUK: [DT: 2026-06-17 13:00:00] [TT: Existing.Movie.2025-GRP] [SC: srrdb] [CT: NEW-CAT] [RQ: 45:alt.binaries.tv] [SZ: 7 GB] [FL: 2F] [FN: existing.r00] [NUKED: bad.pack]",
	}
	if err := os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0o600); err != nil {
		t.Fatalf("write irc sample: %v", err)
	}

	return path
}

func createWorkerReleaseRehearsalTables(t *testing.T, ctx context.Context, db *sql.DB) {
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
			nzbstatus TINYINT NOT NULL DEFAULT 0,
			nzb_guid BLOB NOT NULL
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func workerReleaseWriteFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
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

func workerRemoveCrapTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"binaryblacklist": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, groupname, regex, msgcol, optype, status) ORDER BY id SEPARATOR '|'), '') FROM binaryblacklist",
		"settings":        "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', name, COALESCE(value, '')) ORDER BY name SEPARATOR '|'), '') FROM settings",
		"usenet_groups":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name) ORDER BY id SEPARATOR '|'), '') FROM usenet_groups",
		"releases":        "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, guid, name, searchname, fromname, groups_id, categories_id, passwordstatus, nfostatus, haspreview, jpgstatus, predb_id, videostatus, COALESCE(imdbid, ''), isrenamed, iscategorized, rarinnerfilecount, totalpart, size, adddate) ORDER BY id SEPARATOR '|'), '') FROM releases",
		"release_files":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, releases_id, name, passworded) ORDER BY id SEPARATOR '|'), '') FROM release_files",
		"collections":     "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, groups_id, COALESCE(releases_id, '')) ORDER BY id SEPARATOR '|'), '') FROM collections",
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

func workerPostprocessTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"settings":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', name, COALESCE(value, '')) ORDER BY name SEPARATOR '|'), '') FROM settings",
		"categories": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, disablepreview) ORDER BY id SEPARATOR '|'), '') FROM categories",
		"releases":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, guid, leftguid, name, searchname, groups_id, categories_id, passwordstatus, haspreview, nzbstatus, nfostatus, videos_id, tv_episodes_id, size, isrenamed, COALESCE(anidbid, ''), COALESCE(imdbid, ''), COALESCE(movieinfo_id, ''), COALESCE(bookinfo_id, ''), COALESCE(musicinfo_id, ''), COALESCE(consoleinfo_id, ''), COALESCE(gamesinfo_id, '')) ORDER BY id SEPARATOR '|'), '') FROM releases",
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

func writeWorkerGzippedNZB(t *testing.T, basePath string, guid string, subjects []string) {
	t.Helper()

	if guid == "" {
		t.Fatal("guid is required")
	}
	dir := filepath.Join(basePath, guid[:1])
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatalf("mkdir nzb path: %v", err)
	}

	file, err := os.Create(filepath.Join(dir, guid+".nzb.gz"))
	if err != nil {
		t.Fatalf("create nzb: %v", err)
	}
	defer file.Close()

	gzipWriter := gzip.NewWriter(file)
	defer gzipWriter.Close()

	fmt.Fprintln(gzipWriter, `<?xml version="1.0" encoding="UTF-8"?>`)
	fmt.Fprintln(gzipWriter, `<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">`)
	for _, subject := range subjects {
		fmt.Fprintf(gzipWriter, `  <file poster="poster" date="1" subject="%s">`+"\n", html.EscapeString(subject))
		fmt.Fprintln(gzipWriter, `    <groups><group>alt.binaries.test</group></groups>`)
		fmt.Fprintln(gzipWriter, `    <segments><segment bytes="1" number="1">message-id</segment></segments>`)
		fmt.Fprintln(gzipWriter, `  </file>`)
	}
	fmt.Fprintln(gzipWriter, `</nzb>`)
}

func fakeArtisanBinary(t *testing.T) (string, string) {
	t.Helper()

	dir := t.TempDir()
	binaryPath := dir + "/fake-php"
	logPath := dir + "/artisan.log"
	script := "#!/usr/bin/env sh\nprintf '%s\\n' \"$*\" >> \"" + logPath + "\"\nexit 0\n"
	if err := os.WriteFile(binaryPath, []byte(script), 0o755); err != nil {
		t.Fatalf("write fake artisan binary: %v", err)
	}

	return binaryPath, logPath
}

func seedNativeLaneHeldLock(t *testing.T, ctx context.Context, redisAddr string, job string, owner string) func() {
	t.Helper()

	client := redis.NewClient(&redis.Options{Addr: redisAddr})
	lockKey := "nntmux_database_nntmux-cache-nntmux:distributed-worker:" + job
	if err := client.Del(ctx, lockKey).Err(); err != nil {
		_ = client.Close()
		t.Fatalf("clear native lane lock: %v", err)
	}
	if err := client.Set(ctx, lockKey, owner, time.Minute).Err(); err != nil {
		_ = client.Close()
		t.Fatalf("seed native lane lock: %v", err)
	}

	return func() {
		_ = client.Del(ctx, lockKey).Err()
		_ = client.Close()
	}
}

func readFakeArtisanLog(t *testing.T, path string) string {
	t.Helper()

	contents, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return ""
		}
		t.Fatalf("read fake artisan log: %v", err)
	}

	return string(contents)
}
