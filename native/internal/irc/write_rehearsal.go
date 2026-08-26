package irc

import (
	"context"
	"database/sql"
	"fmt"
	"strings"
)

type WriteRehearsalResult struct {
	Candidates            int   `json:"candidates"`
	PredbRowsAttempted    int   `json:"predb_rows_attempted"`
	PredbRowsAffected     int64 `json:"predb_rows_affected"`
	InsertedRows          int64 `json:"inserted_rows"`
	UpdatedRows           int64 `json:"updated_rows"`
	SearchUpdatesEnqueued int64 `json:"search_updates_enqueued"`
	RolledBack            bool  `json:"rolled_back"`
	WritesCommitted       int   `json:"writes_committed"`
}

func RehearsePredbWrites(ctx context.Context, db *sql.DB, candidates []Candidate) (WriteRehearsalResult, error) {
	return runPredbWrites(ctx, db, candidates, true)
}

func CommitPredbWrites(ctx context.Context, db *sql.DB, candidates []Candidate) (WriteRehearsalResult, error) {
	return runPredbWrites(ctx, db, candidates, false)
}

func runPredbWrites(ctx context.Context, db *sql.DB, candidates []Candidate, rollbackOnly bool) (WriteRehearsalResult, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return WriteRehearsalResult{}, err
	}

	result := WriteRehearsalResult{Candidates: len(candidates)}
	groupIDs := map[string]int64{}
	rollback := func() error {
		if err := tx.Rollback(); err != nil {
			return fmt.Errorf("rollback irc predb write rehearsal: %w", err)
		}
		result.RolledBack = true
		return nil
	}

	for _, candidate := range candidates {
		groupID, err := groupIDForCandidate(ctx, tx, groupIDs, candidate)
		if err != nil {
			_ = rollback()
			return result, err
		}

		predbID, rows, inserted, err := upsertPredbCandidate(ctx, tx, candidate, groupID)
		if err != nil {
			_ = rollback()
			return result, err
		}

		result.PredbRowsAttempted++
		result.PredbRowsAffected += rows
		if inserted {
			result.InsertedRows += rows
		} else {
			result.UpdatedRows += rows
		}
		if rows > 0 {
			searchUpdateRows, err := enqueuePredbSearchSideEffect(ctx, tx, predbID)
			if err != nil {
				_ = rollback()
				return result, err
			}
			result.SearchUpdatesEnqueued += searchUpdateRows
		}
	}

	if rollbackOnly {
		if err := rollback(); err != nil {
			return result, err
		}

		return result, nil
	}

	if err := tx.Commit(); err != nil {
		return result, fmt.Errorf("commit irc predb writes: %w", err)
	}

	result.WritesCommitted = int(result.PredbRowsAffected + result.SearchUpdatesEnqueued)
	result.RolledBack = false

	return result, nil
}

func groupIDForCandidate(ctx context.Context, tx *sql.Tx, cache map[string]int64, candidate Candidate) (int64, error) {
	groupName := strings.TrimSpace(candidate.GroupName)
	if groupName == "" {
		return 0, nil
	}
	if id, ok := cache[groupName]; ok {
		return id, nil
	}

	var id int64
	err := tx.QueryRowContext(ctx, "SELECT id FROM usenet_groups WHERE name = ? LIMIT 1", groupName).Scan(&id)
	if err == sql.ErrNoRows {
		cache[groupName] = 0
		return 0, nil
	}
	if err != nil {
		return 0, fmt.Errorf("resolve irc predb group %q: %w", groupName, err)
	}

	cache[groupName] = id
	return id, nil
}

func upsertPredbCandidate(ctx context.Context, tx *sql.Tx, candidate Candidate, groupID int64) (int64, int64, bool, error) {
	title := strings.TrimSpace(candidate.Title)
	if title == "" {
		return 0, 0, false, fmt.Errorf("irc predb title is required")
	}

	predbID, exists, err := predbTitle(ctx, tx, title)
	if err != nil {
		return 0, 0, false, err
	}
	if !exists {
		predbID, rows, err := insertPredbCandidate(ctx, tx, candidate, title, groupID)
		return predbID, rows, true, err
	}

	rows, err := updatePredbCandidate(ctx, tx, candidate, title, groupID)
	return predbID, rows, false, err
}

func predbTitle(ctx context.Context, tx *sql.Tx, title string) (int64, bool, error) {
	var id int64
	err := tx.QueryRowContext(ctx, "SELECT id FROM predb WHERE title = ? ORDER BY id LIMIT 1", title).Scan(&id)
	if err == nil {
		return id, true, nil
	}
	if err == sql.ErrNoRows {
		return 0, false, nil
	}

	return 0, false, err
}

func insertPredbCandidate(ctx context.Context, tx *sql.Tx, candidate Candidate, title string, groupID int64) (int64, int64, error) {
	res, err := tx.ExecContext(ctx, `
		INSERT IGNORE INTO predb (
			title, predate, source, category, size, files, filename, requestid, groups_id, nuked, nukereason
		)
		VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?, ?, NULLIF(?, ''))`,
		title,
		candidate.Predate,
		candidate.Source,
		candidate.Category,
		candidate.Size,
		candidate.Files,
		candidate.Filename,
		candidate.RequestID,
		groupID,
		candidate.NukeStatus,
		candidate.NukeReason,
	)
	if err != nil {
		return 0, 0, fmt.Errorf("insert irc predb candidate %q: %w", title, err)
	}

	rows, err := res.RowsAffected()
	if err != nil {
		return 0, 0, err
	}
	if rows > 0 {
		predbID, err := res.LastInsertId()
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

func updatePredbCandidate(ctx context.Context, tx *sql.Tx, candidate Candidate, title string, groupID int64) (int64, error) {
	res, err := tx.ExecContext(ctx, `
		UPDATE predb
		SET
			size = CASE WHEN ? != '' THEN ? ELSE size END,
			source = CASE WHEN ? != '' THEN ? ELSE source END,
			files = CASE WHEN ? != '' THEN ? ELSE files END,
			nukereason = CASE WHEN ? != '' THEN ? ELSE nukereason END,
			requestid = CASE WHEN ? > 0 THEN ? ELSE requestid END,
			groups_id = CASE WHEN ? > 0 THEN ? ELSE groups_id END,
			predate = ?,
			nuked = CASE WHEN ? > 0 THEN ? ELSE nuked END,
			filename = CASE WHEN ? != '' THEN ? ELSE filename END,
			category = CASE WHEN (category IS NULL OR category = '') AND ? != '' THEN ? ELSE category END
		WHERE title = ?`,
		candidate.Size, candidate.Size,
		candidate.Source, candidate.Source,
		candidate.Files, candidate.Files,
		candidate.NukeReason, candidate.NukeReason,
		candidate.RequestID, candidate.RequestID,
		groupID, groupID,
		candidate.Predate,
		candidate.NukeStatus, candidate.NukeStatus,
		candidate.Filename, candidate.Filename,
		candidate.Category, candidate.Category,
		title,
	)
	if err != nil {
		return 0, fmt.Errorf("update irc predb candidate %q: %w", title, err)
	}

	return res.RowsAffected()
}

func enqueuePredbSearchSideEffect(ctx context.Context, tx *sql.Tx, predbID int64) (int64, error) {
	if predbID <= 0 {
		return 0, fmt.Errorf("irc predb search side effect requires positive predb id")
	}

	operationKey := fmt.Sprintf("irc:predb-search:v1:%d", predbID)
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
		) VALUES (?, 'irc', 'predb-search-sync', ?, 'predb_id', 'predb-import', 1, 'pending', 0, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE
			status = 'pending',
			available_at = NULL,
			processed_at = NULL,
			last_error_code = NULL,
			updated_at = UTC_TIMESTAMP(6)
	`, operationKey, predbID)
	if err != nil {
		return 0, fmt.Errorf("enqueue irc predb search side effect: %w", err)
	}

	rowsAffected, err := res.RowsAffected()
	if err != nil {
		return 0, fmt.Errorf("enqueue irc predb search side effect rows affected: %w", err)
	}

	return rowsAffected, nil
}
