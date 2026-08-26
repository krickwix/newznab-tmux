package pergroup

import (
	"context"
	"database/sql"
	"fmt"
)

type WriteRehearsalResult struct {
	QueueEntries          int   `json:"queue_entries"`
	GroupUpdatesAttempted int   `json:"group_updates_attempted"`
	GroupRowsAffected     int64 `json:"group_rows_affected"`
	RolledBack            bool  `json:"rolled_back"`
	WritesCommitted       int   `json:"writes_committed"`
}

func RehearsePerGroupWrites(ctx context.Context, db *sql.DB, plan Plan) (WriteRehearsalResult, error) {
	return runPerGroupWrites(ctx, db, plan, true)
}

func CommitPerGroupWrites(ctx context.Context, db *sql.DB, plan Plan) (WriteRehearsalResult, error) {
	return runPerGroupWrites(ctx, db, plan, false)
}

func runPerGroupWrites(ctx context.Context, db *sql.DB, plan Plan, rollbackOnly bool) (WriteRehearsalResult, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return WriteRehearsalResult{}, err
	}

	result := WriteRehearsalResult{
		QueueEntries: len(plan.Queues),
	}
	rollback := func() error {
		if err := tx.Rollback(); err != nil {
			return fmt.Errorf("rollback per-group write rehearsal: %w", err)
		}
		result.RolledBack = true
		return nil
	}

	for _, queue := range plan.Queues {
		rows, err := rehearsePerGroupUpdate(ctx, tx, queue)
		if err != nil {
			_ = rollback()
			return result, err
		}
		result.GroupUpdatesAttempted++
		result.GroupRowsAffected += rows
	}

	if rollbackOnly {
		if err := rollback(); err != nil {
			return result, err
		}

		return result, nil
	}

	if err := tx.Commit(); err != nil {
		return result, fmt.Errorf("commit per-group writes: %w", err)
	}

	result.WritesCommitted = int(result.GroupRowsAffected)
	result.RolledBack = false

	return result, nil
}

func rehearsePerGroupUpdate(ctx context.Context, tx *sql.Tx, queue QueueEntry) (int64, error) {
	update, err := tx.ExecContext(ctx, `
		UPDATE usenet_groups
		SET last_updated = UTC_TIMESTAMP(6)
		WHERE id = ?
			AND (active = 1 OR backfill = 1)
		LIMIT 1`,
		queue.GroupID,
	)
	if err != nil {
		return 0, fmt.Errorf("rehearse per-group update for group %d: %w", queue.GroupID, err)
	}

	rows, err := update.RowsAffected()
	if err != nil {
		return 0, err
	}

	return rows, nil
}
