package metadata

import (
	"context"
	"database/sql"
	"fmt"
	"strings"
)

type WriteRehearsalResult struct {
	SrrdbTitleCandidates  int   `json:"srrdb_title_candidates"`
	ArchiveCRCCandidates  int   `json:"archive_crc_candidates"`
	SearchQueries         int   `json:"search_queries"`
	SrrdbDetailsQueried   int   `json:"srrdb_details_queried"`
	SrrdbDetailsFound     int   `json:"srrdb_details_found"`
	SrrdbDetailsFailed    int   `json:"srrdb_details_failed"`
	SrrdbDetailFiles      int   `json:"srrdb_detail_files"`
	SrrdbArchiveQueried   int   `json:"srrdb_archive_queried"`
	SrrdbArchiveFound     int   `json:"srrdb_archive_found"`
	SrrdbArchiveFailed    int   `json:"srrdb_archive_failed"`
	SrrdbArchiveHits      int   `json:"srrdb_archive_hits"`
	SearchProviderHits    int   `json:"search_provider_hits"`
	PredbRowsAttempted    int   `json:"predb_rows_attempted"`
	PredbRowsAffected     int64 `json:"predb_rows_affected"`
	PredbCRCRowsAttempted int   `json:"predb_crc_rows_attempted"`
	PredbCRCRowsAffected  int64 `json:"predb_crc_rows_affected"`
	SearchUpdatesEnqueued int64 `json:"search_updates_enqueued"`
	RolledBack            bool  `json:"rolled_back"`
	WritesCommitted       int   `json:"writes_committed"`
}

func RehearseMetadataRefreshWrites(ctx context.Context, db *sql.DB, plan RefreshDryRunPlan) (WriteRehearsalResult, error) {
	return runMetadataRefreshWrites(ctx, db, plan, true)
}

func CommitMetadataRefreshWrites(ctx context.Context, db *sql.DB, plan RefreshDryRunPlan) (WriteRehearsalResult, error) {
	return runMetadataRefreshWrites(ctx, db, plan, false)
}

func runMetadataRefreshWrites(ctx context.Context, db *sql.DB, plan RefreshDryRunPlan, rollbackOnly bool) (WriteRehearsalResult, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return WriteRehearsalResult{}, err
	}

	result := WriteRehearsalResult{
		SrrdbTitleCandidates: len(plan.SrrdbTitleCandidates),
		ArchiveCRCCandidates: len(plan.ArchiveCRCCandidates),
		SearchQueries:        len(plan.SearchQueries),
	}
	rollback := func() error {
		if err := tx.Rollback(); err != nil {
			return fmt.Errorf("rollback metadata-refresh write rehearsal: %w", err)
		}
		result.RolledBack = true
		return nil
	}

	for _, candidate := range plan.SrrdbTitleCandidates {
		details, ok := plan.SrrdbTitleDetails[candidate.ID]
		result.SrrdbDetailsQueried++
		if !ok {
			result.SrrdbDetailsFailed++
			continue
		}

		result.SrrdbDetailsFound++
		rowsAttempted, rowsAffected, err := insertSrrdbTitleCRCs(ctx, tx, candidate, details)
		if err != nil {
			_ = rollback()
			return result, err
		}
		result.SrrdbDetailFiles += rowsAttempted
		result.PredbCRCRowsAttempted += rowsAttempted
		result.PredbCRCRowsAffected += rowsAffected
	}

	for _, candidate := range plan.ArchiveCRCCandidates {
		hits := plan.ArchiveCRCHits[candidate.Key()]
		result.SrrdbArchiveQueried++
		if len(hits) == 0 {
			result.SrrdbArchiveFailed++
			continue
		}

		result.SrrdbArchiveFound++
		predbRowsAttempted, predbRows, predbCRCRowsAttempted, predbCRCRows, searchUpdates, err := applyArchiveCRCHits(ctx, tx, candidate, hits)
		if err != nil {
			_ = rollback()
			return result, err
		}
		result.SrrdbArchiveHits += predbRowsAttempted
		result.PredbRowsAttempted += predbRowsAttempted
		result.PredbRowsAffected += predbRows
		result.PredbCRCRowsAttempted += predbCRCRowsAttempted
		result.PredbCRCRowsAffected += predbCRCRows
		result.SearchUpdatesEnqueued += searchUpdates
	}

	for _, query := range plan.SearchQueries {
		hits := searchProviderHitsForQuery(plan, query)
		rowsAttempted, rows, searchUpdates, err := applySearchProviderHits(ctx, tx, hits)
		if err != nil {
			_ = rollback()
			return result, err
		}
		result.SearchProviderHits += rowsAttempted
		result.PredbRowsAttempted += rowsAttempted
		result.PredbRowsAffected += rows
		result.SearchUpdatesEnqueued += searchUpdates
	}

	if rollbackOnly {
		if err := rollback(); err != nil {
			return result, err
		}

		return result, nil
	}

	if err := tx.Commit(); err != nil {
		return result, fmt.Errorf("commit metadata-refresh writes: %w", err)
	}

	result.WritesCommitted = int(result.PredbRowsAffected + result.PredbCRCRowsAffected + result.SearchUpdatesEnqueued)
	result.RolledBack = false

	return result, nil
}

func insertSrrdbTitleCRCs(ctx context.Context, tx *sql.Tx, candidate PredbTitleCandidate, details SrrdbTitleDetails) (int, int64, error) {
	seen := map[string]struct{}{}
	rowsAttempted := 0
	var rowsAffected int64

	for _, file := range details.Files {
		crc := strings.ToUpper(strings.TrimSpace(file.CRC))
		if file.Size <= 0 || !validCRC32Pattern.MatchString(crc) {
			continue
		}

		key := fmt.Sprintf("%s#%d", crc, file.Size)
		if _, ok := seen[key]; ok {
			continue
		}
		seen[key] = struct{}{}

		insert, err := tx.ExecContext(ctx, `
			INSERT IGNORE INTO predb_crcs (predb_id, crchash, filesize)
			VALUES (?, ?, ?)`,
			candidate.ID,
			crc,
			file.Size,
		)
		if err != nil {
			return rowsAttempted, rowsAffected, fmt.Errorf("insert srrdb crc for predb %d: %w", candidate.ID, err)
		}
		rows, err := insert.RowsAffected()
		if err != nil {
			return rowsAttempted, rowsAffected, err
		}

		rowsAttempted++
		rowsAffected += rows
	}

	return rowsAttempted, rowsAffected, nil
}

func applyArchiveCRCHits(ctx context.Context, tx *sql.Tx, candidate ArchiveCRCCandidate, hits []SrrdbArchiveHit) (int, int64, int, int64, int64, error) {
	seen := map[string]struct{}{}
	predbRowsAttempted := 0
	var predbRowsAffected int64
	predbCRCRowsAttempted := 0
	var predbCRCRowsAffected int64
	var searchUpdatesEnqueued int64

	for _, hit := range hits {
		title := strings.TrimSpace(hit.Title)
		if title == "" {
			continue
		}
		if _, ok := seen[title]; ok {
			continue
		}
		seen[title] = struct{}{}

		predbID, predbRows, err := findOrCreatePredb(ctx, tx, title, "srrdb")
		if err != nil {
			return predbRowsAttempted, predbRowsAffected, predbCRCRowsAttempted, predbCRCRowsAffected, searchUpdatesEnqueued, fmt.Errorf("insert archive predb hit for crc candidate: %w", err)
		}
		predbRowsAttempted++
		predbRowsAffected += predbRows
		if predbRows > 0 {
			searchUpdateRows, err := enqueuePredbSearchSideEffect(ctx, tx, predbID)
			if err != nil {
				return predbRowsAttempted, predbRowsAffected, predbCRCRowsAttempted, predbCRCRowsAffected, searchUpdatesEnqueued, err
			}
			searchUpdatesEnqueued += searchUpdateRows
		}

		crcInsert, err := tx.ExecContext(ctx, `
			INSERT IGNORE INTO predb_crcs (predb_id, crchash, filesize)
			VALUES (?, ?, ?)`,
			predbID,
			candidate.CRC,
			candidate.Size,
		)
		if err != nil {
			return predbRowsAttempted, predbRowsAffected, predbCRCRowsAttempted, predbCRCRowsAffected, searchUpdatesEnqueued, fmt.Errorf("insert archive crc for crc candidate: %w", err)
		}
		predbCRCRows, err := crcInsert.RowsAffected()
		if err != nil {
			return predbRowsAttempted, predbRowsAffected, predbCRCRowsAttempted, predbCRCRowsAffected, searchUpdatesEnqueued, err
		}
		predbCRCRowsAttempted++
		predbCRCRowsAffected += predbCRCRows
	}

	return predbRowsAttempted, predbRowsAffected, predbCRCRowsAttempted, predbCRCRowsAffected, searchUpdatesEnqueued, nil
}

func applySearchProviderHits(ctx context.Context, tx *sql.Tx, hits []SearchProviderHit) (int, int64, int64, error) {
	seen := map[string]struct{}{}
	rowsAttempted := 0
	var rowsAffected int64
	var searchUpdatesEnqueued int64

	for _, hit := range hits {
		title := strings.TrimSpace(hit.Title)
		source := strings.TrimSpace(hit.Source)
		if title == "" || source == "" {
			continue
		}

		key := source + "#" + title
		if _, ok := seen[key]; ok {
			continue
		}
		seen[key] = struct{}{}

		predbID, rows, err := findOrCreatePredb(ctx, tx, title, source)
		if err != nil {
			return rowsAttempted, rowsAffected, searchUpdatesEnqueued, fmt.Errorf("insert search provider predb hit: %w", err)
		}
		rowsAttempted++
		rowsAffected += rows
		if rows > 0 {
			searchUpdateRows, err := enqueuePredbSearchSideEffect(ctx, tx, predbID)
			if err != nil {
				return rowsAttempted, rowsAffected, searchUpdatesEnqueued, err
			}
			searchUpdatesEnqueued += searchUpdateRows
		}
	}

	return rowsAttempted, rowsAffected, searchUpdatesEnqueued, nil
}

func searchProviderHitsForQuery(plan RefreshDryRunPlan, query string) []SearchProviderHit {
	hits := []SearchProviderHit{}
	for key, providerHits := range plan.SearchProviderHits {
		parts := strings.SplitN(key, "#", 2)
		if len(parts) != 2 || parts[1] != query {
			continue
		}
		hits = append(hits, providerHits...)
	}

	return hits
}

func findOrCreatePredb(ctx context.Context, tx *sql.Tx, title string, source string) (int64, int64, error) {
	title = strings.TrimSpace(title)
	if title == "" {
		return 0, 0, fmt.Errorf("predb title is required")
	}

	insert, err := tx.ExecContext(ctx, `
		INSERT IGNORE INTO predb (title, source)
		VALUES (?, ?)`,
		title,
		source,
	)
	if err != nil {
		return 0, 0, err
	}

	rows, err := insert.RowsAffected()
	if err != nil {
		return 0, 0, err
	}
	if rows > 0 {
		predbID, err := insert.LastInsertId()
		if err != nil {
			return 0, 0, err
		}
		if predbID > 0 {
			return predbID, rows, nil
		}
	}

	var predbID int64
	if err := tx.QueryRowContext(ctx, "SELECT id FROM predb WHERE title = ? ORDER BY id LIMIT 1", title).Scan(&predbID); err != nil {
		return 0, 0, err
	}

	return predbID, rows, nil
}

func enqueuePredbSearchSideEffect(ctx context.Context, tx *sql.Tx, predbID int64) (int64, error) {
	if predbID <= 0 {
		return 0, fmt.Errorf("metadata-refresh predb search side effect requires positive predb id")
	}

	operationKey := fmt.Sprintf("metadata-refresh:predb-search:v1:%d", predbID)
	res, err := tx.ExecContext(ctx, `
		INSERT INTO native_worker_side_effects (
			operation_key,
			job,
			effect,
			release_id,
			status_column,
			status_reason,
			status_value,
			status,
			attempts,
			available_at,
			created_at,
			updated_at
		) VALUES (?, 'metadata-refresh', 'predb-search-sync', ?, 'predb_id', 'predb-import', 1, 'pending', 0, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE
			status = 'pending',
			available_at = NULL,
			processed_at = NULL,
			last_error_code = NULL,
			updated_at = UTC_TIMESTAMP(6)
	`, operationKey, predbID)
	if err != nil {
		return 0, fmt.Errorf("enqueue metadata-refresh predb search side effect: %w", err)
	}

	rowsAffected, err := res.RowsAffected()
	if err != nil {
		return 0, fmt.Errorf("enqueue metadata-refresh predb search side effect rows affected: %w", err)
	}

	return rowsAffected, nil
}
