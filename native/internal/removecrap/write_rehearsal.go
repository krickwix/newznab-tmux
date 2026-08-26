package removecrap

import (
	"context"
	"database/sql"
	"fmt"
)

type WriteRehearsalResult struct {
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
}

func RehearseRemoveCrapWrites(ctx context.Context, db *sql.DB, plan Plan) (WriteRehearsalResult, error) {
	return runRemoveCrapWrites(ctx, db, plan, true)
}

func CommitRemoveCrapWrites(ctx context.Context, db *sql.DB, plan Plan) (WriteRehearsalResult, error) {
	return runRemoveCrapWrites(ctx, db, plan, false)
}

func runRemoveCrapWrites(ctx context.Context, db *sql.DB, plan Plan, rollbackOnly bool) (WriteRehearsalResult, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return WriteRehearsalResult{}, err
	}

	releaseIDs, candidateRows := uniqueCandidateReleaseIDs(plan)
	result := WriteRehearsalResult{
		CandidateReleases:    len(releaseIDs),
		CandidateRows:        candidateRows,
		DeleteCommands:       plan.DestructiveCommands,
		DeletedReleaseIDs:    []int64{},
		DeletedCollectionIDs: []int64{},
	}
	rollback := func() error {
		if err := tx.Rollback(); err != nil {
			return fmt.Errorf("rollback removecrap write rehearsal: %w", err)
		}
		result.RolledBack = true
		return nil
	}

	deletedReleaseIDs := []int64{}
	deletedCollectionIDs := []int64{}
	for _, releaseID := range releaseIDs {
		collectionRows, fileRows, releaseRows, collectionIDs, cleanupRows, err := rehearseRemoveCrapDelete(ctx, tx, releaseID, !rollbackOnly)
		if err != nil {
			_ = rollback()
			return result, err
		}
		result.CollectionRowsAffected += collectionRows
		result.ReleaseFileRowsAffected += fileRows
		result.ReleaseRowsAffected += releaseRows
		result.FileCleanupRowsEnqueued += cleanupRows
		if releaseRows > 0 {
			deletedReleaseIDs = append(deletedReleaseIDs, releaseID)
		}
		deletedCollectionIDs = append(deletedCollectionIDs, collectionIDs...)
	}

	if rollbackOnly {
		if err := rollback(); err != nil {
			return result, err
		}

		return result, nil
	}

	if err := tx.Commit(); err != nil {
		return result, fmt.Errorf("commit removecrap writes: %w", err)
	}

	result.WritesCommitted = int(result.CollectionRowsAffected + result.ReleaseFileRowsAffected + result.ReleaseRowsAffected)
	result.DeletedReleaseIDs = deletedReleaseIDs
	result.DeletedCollectionIDs = deletedCollectionIDs
	result.RolledBack = false

	return result, nil
}

func uniqueCandidateReleaseIDs(plan Plan) ([]int64, int) {
	seen := map[int64]struct{}{}
	releaseIDs := []int64{}
	candidateRows := 0

	for _, typeResult := range plan.Results {
		candidateRows += len(typeResult.Candidates)
		for _, candidate := range typeResult.Candidates {
			if _, ok := seen[candidate.ID]; ok {
				continue
			}
			seen[candidate.ID] = struct{}{}
			releaseIDs = append(releaseIDs, candidate.ID)
		}
	}

	return releaseIDs, candidateRows
}

func rehearseRemoveCrapDelete(ctx context.Context, tx *sql.Tx, releaseID int64, enqueueFileCleanup bool) (int64, int64, int64, []int64, int64, error) {
	releaseGuid, err := removeCrapReleaseGuid(ctx, tx, releaseID)
	if err != nil {
		return 0, 0, 0, nil, 0, err
	}
	collectionIDs, err := removeCrapCollectionIDs(ctx, tx, releaseID)
	if err != nil {
		return 0, 0, 0, nil, 0, err
	}

	collections, err := tx.ExecContext(ctx, `
		DELETE FROM collections
		WHERE releases_id = ?`, releaseID)
	if err != nil {
		return 0, 0, 0, nil, 0, fmt.Errorf("rehearse removecrap collection delete for release %d: %w", releaseID, err)
	}
	collectionRows, err := collections.RowsAffected()
	if err != nil {
		return 0, 0, 0, nil, 0, err
	}

	files, err := tx.ExecContext(ctx, `
		DELETE FROM release_files
		WHERE releases_id = ?`, releaseID)
	if err != nil {
		return 0, 0, 0, nil, 0, fmt.Errorf("rehearse removecrap release_files delete for release %d: %w", releaseID, err)
	}
	fileRows, err := files.RowsAffected()
	if err != nil {
		return 0, 0, 0, nil, 0, err
	}

	releases, err := tx.ExecContext(ctx, `
		DELETE FROM releases
		WHERE id = ?`, releaseID)
	if err != nil {
		return 0, 0, 0, nil, 0, fmt.Errorf("rehearse removecrap release delete for release %d: %w", releaseID, err)
	}
	releaseRows, err := releases.RowsAffected()
	if err != nil {
		return 0, 0, 0, nil, 0, err
	}

	var cleanupRows int64
	if enqueueFileCleanup && releaseRows > 0 {
		if releaseGuid == "" {
			return 0, 0, 0, nil, 0, fmt.Errorf("removecrap release %d has empty guid for file cleanup side effect", releaseID)
		}
		if err := enqueueNativeReleaseFileCleanupSideEffect(ctx, tx, releaseID, releaseGuid); err != nil {
			return 0, 0, 0, nil, 0, err
		}
		cleanupRows = 1
	}

	return collectionRows, fileRows, releaseRows, collectionIDs, cleanupRows, nil
}

func removeCrapReleaseGuid(ctx context.Context, tx *sql.Tx, releaseID int64) (string, error) {
	var guid string
	err := tx.QueryRowContext(ctx, `
		SELECT guid
		FROM releases
		WHERE id = ?`, releaseID).Scan(&guid)
	if err == sql.ErrNoRows {
		return "", nil
	}
	if err != nil {
		return "", fmt.Errorf("select removecrap release guid for release %d: %w", releaseID, err)
	}

	return guid, nil
}

func removeCrapCollectionIDs(ctx context.Context, tx *sql.Tx, releaseID int64) ([]int64, error) {
	rows, err := tx.QueryContext(ctx, `
		SELECT id
		FROM collections
		WHERE releases_id = ?
		ORDER BY id`, releaseID)
	if err != nil {
		return nil, fmt.Errorf("select removecrap collection ids for release %d: %w", releaseID, err)
	}
	defer rows.Close()

	collectionIDs := []int64{}
	for rows.Next() {
		var collectionID int64
		if err := rows.Scan(&collectionID); err != nil {
			return nil, fmt.Errorf("scan removecrap collection id for release %d: %w", releaseID, err)
		}
		collectionIDs = append(collectionIDs, collectionID)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read removecrap collection ids for release %d: %w", releaseID, err)
	}

	return collectionIDs, nil
}

func enqueueNativeReleaseFileCleanupSideEffect(ctx context.Context, tx *sql.Tx, releaseID int64, guid string) error {
	operationKey := fmt.Sprintf("removecrap:release-file-cleanup:v1:%d", releaseID)
	_, err := tx.ExecContext(ctx, `
		INSERT INTO native_worker_side_effects (
			operation_key,
			job,
			effect,
			release_id,
			status_column,
			status_reason,
			status_value,
			payload_text,
			status,
			attempts,
			available_at,
			created_at,
			updated_at
		) VALUES (?, 'removecrap', 'release-file-cleanup', ?, 'release_guid', 'delete-release-files', 1, ?, 'pending', 0, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE
			payload_text = VALUES(payload_text),
			status = 'pending',
			available_at = NULL,
			processed_at = NULL,
			last_error_code = NULL,
			updated_at = UTC_TIMESTAMP(6)
	`, operationKey, releaseID, guid)
	if err != nil {
		return fmt.Errorf("enqueue native removecrap file cleanup side effect for release %d: %w", releaseID, err)
	}

	return nil
}
