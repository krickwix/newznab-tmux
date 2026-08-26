package postprocess

import (
	"context"
	"database/sql"
	"fmt"
)

type WriteRehearsalResult struct {
	BucketEntries          int     `json:"bucket_entries"`
	BucketUpdatesAttempted int     `json:"bucket_updates_attempted"`
	ReleaseRowsAffected    int64   `json:"release_rows_affected"`
	CommittedReleaseIDs    []int64 `json:"committed_release_ids"`
	RolledBack             bool    `json:"rolled_back"`
	WritesCommitted        int     `json:"writes_committed"`
}

func RehearsePostprocessWrites(ctx context.Context, db *sql.DB, plan Plan) (WriteRehearsalResult, error) {
	return runPostprocessWrites(ctx, db, plan, true)
}

func CommitPostprocessWrites(ctx context.Context, db *sql.DB, plan Plan) (WriteRehearsalResult, error) {
	return runPostprocessWrites(ctx, db, plan, false)
}

func runPostprocessWrites(ctx context.Context, db *sql.DB, plan Plan, rollbackOnly bool) (WriteRehearsalResult, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return WriteRehearsalResult{}, err
	}

	result := WriteRehearsalResult{
		BucketEntries:       plan.BucketEntries,
		CommittedReleaseIDs: []int64{},
	}
	rollback := func() error {
		if err := tx.Rollback(); err != nil {
			return fmt.Errorf("rollback postprocess write rehearsal: %w", err)
		}
		result.RolledBack = true
		return nil
	}

	for _, typeResult := range plan.Results {
		for _, bucket := range typeResult.Buckets {
			releaseID, rows, err := rehearsePostprocessBucket(ctx, tx, bucket)
			if err != nil {
				_ = rollback()
				return result, err
			}
			result.BucketUpdatesAttempted++
			result.ReleaseRowsAffected += rows
			if rows > 0 && releaseID > 0 && !rollbackOnly {
				result.CommittedReleaseIDs = append(result.CommittedReleaseIDs, releaseID)
			}
		}
	}

	if rollbackOnly {
		if err := rollback(); err != nil {
			return result, err
		}

		return result, nil
	}

	if err := tx.Commit(); err != nil {
		return result, fmt.Errorf("commit postprocess writes: %w", err)
	}

	result.WritesCommitted = int(result.ReleaseRowsAffected)
	result.RolledBack = false

	return result, nil
}

func rehearsePostprocessBucket(ctx context.Context, tx *sql.Tx, bucket Bucket) (int64, int64, error) {
	switch bucket.Type {
	case "additional":
		return rehearsePostprocessUpdate(ctx, tx, bucket, `
			SELECT id
			FROM releases
			WHERE LEFT(leftguid, 1) = ?
				AND passwordstatus = -1
				AND haspreview = -1
				AND nzbstatus = 1
			LIMIT 1`, `
			UPDATE releases
			SET haspreview = 0,
				passwordstatus = 0
			WHERE id = ?
			LIMIT 1`)
	case "nfo":
		return rehearsePostprocessUpdate(ctx, tx, bucket, `
			SELECT id
			FROM releases
			WHERE LEFT(leftguid, 1) = ?
				AND nfostatus BETWEEN -8 AND -1
			LIMIT 1`, `
			UPDATE releases
			SET nfostatus = 0
			WHERE id = ?
			LIMIT 1`)
	case "movie":
		return rehearsePostprocessUpdate(ctx, tx, bucket, `
			SELECT id
			FROM releases
			WHERE LEFT(leftguid, 1) = ?
				AND categories_id BETWEEN ? AND ?
				AND (movieinfo_id IS NULL OR movieinfo_id = 0)
			LIMIT 1`, `
			UPDATE releases
			SET movieinfo_id = 1
			WHERE id = ?
			LIMIT 1`, categoryMovieRoot, categoryMovieOther)
	case "tv":
		return rehearsePostprocessUpdate(ctx, tx, bucket, `
			SELECT id
			FROM releases
			WHERE LEFT(leftguid, 1) = ?
				AND categories_id BETWEEN 5000 AND 5999
				AND categories_id != 5070
				AND videos_id = 0
				AND tv_episodes_id BETWEEN -3 AND 0
			LIMIT 1`, `
			UPDATE releases
			SET videos_id = 1,
				tv_episodes_id = 1
			WHERE id = ?
			LIMIT 1`)
	case "anime":
		return rehearsePostprocessUpdate(ctx, tx, bucket, `
			SELECT id
			FROM releases
			WHERE LEFT(leftguid, 1) = ?
				AND categories_id = 5070
				AND anidbid IS NULL
			LIMIT 1`, `
			UPDATE releases
			SET anidbid = -2
			WHERE id = ?
			LIMIT 1`)
	case "books":
		return rehearsePostprocessUpdate(ctx, tx, bucket, `
			SELECT id
			FROM releases
			WHERE LEFT(leftguid, 1) = ?
				AND (bookinfo_id IS NULL OR bookinfo_id = 0)
			LIMIT 1`, `
			UPDATE releases
			SET bookinfo_id = 1
			WHERE id = ?
			LIMIT 1`)
	case "music":
		return rehearsePostprocessUpdate(ctx, tx, bucket, `
			SELECT id
			FROM releases
			WHERE LEFT(leftguid, 1) = ?
				AND musicinfo_id IS NULL
			LIMIT 1`, `
			UPDATE releases
			SET musicinfo_id = -2
			WHERE id = ?
			LIMIT 1`)
	case "console":
		return rehearsePostprocessUpdate(ctx, tx, bucket, `
			SELECT id
			FROM releases
			WHERE LEFT(leftguid, 1) = ?
				AND consoleinfo_id IS NULL
			LIMIT 1`, `
			UPDATE releases
			SET consoleinfo_id = -2
			WHERE id = ?
			LIMIT 1`)
	case "games":
		return rehearsePostprocessUpdate(ctx, tx, bucket, `
			SELECT id
			FROM releases
			WHERE LEFT(leftguid, 1) = ?
				AND gamesinfo_id = 0
			LIMIT 1`, `
			UPDATE releases
			SET gamesinfo_id = 1
			WHERE id = ?
			LIMIT 1`)
	default:
		return 0, 0, fmt.Errorf("unsupported postprocess rehearsal type %q", bucket.Type)
	}
}

func rehearsePostprocessUpdate(ctx context.Context, tx *sql.Tx, bucket Bucket, selectQuery string, updateQuery string, args ...any) (int64, int64, error) {
	selectArgs := append([]any{bucket.Bucket}, args...)
	var releaseID int64
	err := tx.QueryRowContext(ctx, selectQuery, selectArgs...).Scan(&releaseID)
	if err == sql.ErrNoRows {
		return 0, 0, nil
	}
	if err != nil {
		return 0, 0, fmt.Errorf("select postprocess %s bucket %q: %w", bucket.Type, bucket.Bucket, err)
	}

	result, err := tx.ExecContext(ctx, updateQuery, releaseID)
	if err != nil {
		return 0, 0, fmt.Errorf("rehearse postprocess %s bucket %q release %d: %w", bucket.Type, bucket.Bucket, releaseID, err)
	}

	rows, err := result.RowsAffected()
	if err != nil {
		return 0, 0, err
	}

	return releaseID, rows, nil
}
