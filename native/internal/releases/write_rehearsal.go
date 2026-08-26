package releases

import (
	"context"
	"database/sql"
	"fmt"
)

type WriteRehearsalResult struct {
	QueueEntries            int     `json:"queue_entries"`
	ReleaseRowsAttempted    int     `json:"release_rows_attempted"`
	ReleaseRowsAffected     int64   `json:"release_rows_affected"`
	CommittedReleaseIDs     []int64 `json:"committed_release_ids"`
	CollectionRowsAttempted int     `json:"collection_rows_attempted"`
	CollectionRowsAffected  int64   `json:"collection_rows_affected"`
	RolledBack              bool    `json:"rolled_back"`
	WritesCommitted         int     `json:"writes_committed"`
}

func RehearseReleaseWrites(ctx context.Context, db *sql.DB, plan Plan) (WriteRehearsalResult, error) {
	return runReleaseWrites(ctx, db, plan, true)
}

func CommitReleaseWrites(ctx context.Context, db *sql.DB, plan Plan) (WriteRehearsalResult, error) {
	return runReleaseWrites(ctx, db, plan, false)
}

func runReleaseWrites(ctx context.Context, db *sql.DB, plan Plan, rollbackOnly bool) (WriteRehearsalResult, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return WriteRehearsalResult{}, err
	}

	result := WriteRehearsalResult{
		QueueEntries:        len(plan.Queues),
		CommittedReleaseIDs: []int64{},
	}
	committedReleaseIDs := []int64{}
	rollback := func() error {
		if err := tx.Rollback(); err != nil {
			return fmt.Errorf("rollback releases write rehearsal: %w", err)
		}
		result.RolledBack = true
		return nil
	}

	for _, queue := range plan.Queues {
		releaseID, releaseRows, collectionRows, err := rehearseReleaseGroup(ctx, tx, queue)
		if err != nil {
			_ = rollback()
			return result, err
		}

		result.ReleaseRowsAttempted++
		result.ReleaseRowsAffected += releaseRows
		result.CollectionRowsAttempted++
		result.CollectionRowsAffected += collectionRows
		if releaseID > 0 && releaseRows > 0 {
			committedReleaseIDs = append(committedReleaseIDs, releaseID)
		}
	}

	if rollbackOnly {
		if err := rollback(); err != nil {
			return result, err
		}
		return result, nil
	}

	if err := tx.Commit(); err != nil {
		return result, fmt.Errorf("commit release writes: %w", err)
	}
	result.WritesCommitted = int(result.ReleaseRowsAffected + result.CollectionRowsAffected)
	result.RolledBack = false
	result.CommittedReleaseIDs = committedReleaseIDs

	return result, nil
}

func rehearseReleaseGroup(ctx context.Context, tx *sql.Tx, queue QueueEntry) (int64, int64, int64, error) {
	candidate, err := selectReleaseCandidateCollection(ctx, tx, queue.GroupID)
	if err != nil {
		return 0, 0, 0, err
	}
	if candidate.CollectionID == 0 {
		return 0, 0, 0, nil
	}

	insert, err := tx.ExecContext(ctx, `
		INSERT INTO releases (
			guid,
			leftguid,
			name,
			searchname,
			totalpart,
			groups_id,
			size,
			postdate,
			adddate,
			fromname,
			completion,
			categories_id,
			nzbstatus
		)
		VALUES (
			SHA1(?),
			LEFT(SHA1(?), 1),
			?,
			?,
			?,
			?,
			?,
			?,
			UTC_TIMESTAMP(6),
			?,
			?,
			10,
			0
		)`,
		candidate.GuidSource,
		candidate.GuidSource,
		truncateString(candidate.Name, 255),
		truncateString(candidate.SearchName, 255),
		candidate.TotalParts,
		candidate.GroupID,
		candidate.Size,
		candidate.PostDate,
		truncateString(candidate.FromName, 255),
		candidate.Completion,
	)
	if err != nil {
		return 0, 0, 0, fmt.Errorf("rehearse release insert for group %d: %w", queue.GroupID, err)
	}
	releaseRows, err := insert.RowsAffected()
	if err != nil {
		return 0, 0, 0, err
	}
	releaseID, err := insert.LastInsertId()
	if err != nil {
		return 0, 0, 0, err
	}

	update, err := tx.ExecContext(ctx, `
		UPDATE collections
		SET releases_id = ?
		WHERE id = ?
			AND releases_id IS NULL
		LIMIT 1`, releaseID, candidate.CollectionID)
	if err != nil {
		return 0, 0, 0, fmt.Errorf("rehearse collection release link for group %d: %w", queue.GroupID, err)
	}
	collectionRows, err := update.RowsAffected()
	if err != nil {
		return 0, 0, 0, err
	}

	return releaseID, releaseRows, collectionRows, nil
}

type releaseCandidateCollection struct {
	CollectionID int64
	GroupID      int64
	Name         string
	SearchName   string
	FromName     string
	PostDate     any
	TotalParts   int64
	Size         int64
	Completion   float64
	GuidSource   string
}

func selectReleaseCandidateCollection(ctx context.Context, tx *sql.Tx, groupID int64) (releaseCandidateCollection, error) {
	rows, err := tx.QueryContext(ctx, `
		SELECT
			c.id,
			c.groups_id,
			COALESCE(NULLIF(c.subject, ''), CONCAT('Native.Release.', c.id)) AS release_name,
			COALESCE(NULLIF(c.subject, ''), CONCAT('Native.Release.', c.id)) AS search_name,
			COALESCE(c.fromname, '') AS fromname,
			c.date,
			GREATEST(1, COALESCE(SUM(NULLIF(b.totalparts, 0)), NULLIF(c.totalfiles, 0), 1)) AS total_parts,
			COALESCE(NULLIF(SUM(b.partsize), 0), NULLIF(c.filesize, 0), 0) AS release_size,
			LEAST(100, GREATEST(0, COALESCE(SUM(b.currentparts), 0) / GREATEST(1, COALESCE(SUM(NULLIF(b.totalparts, 0)), NULLIF(c.totalfiles, 0), 1)) * 100)) AS completion,
			CONCAT(c.id, ':', c.collectionhash, ':', c.groups_id) AS guid_source
		FROM collections c
		LEFT JOIN binaries b ON b.collections_id = c.id
		WHERE c.groups_id = ?
			AND c.releases_id IS NULL
		GROUP BY c.id, c.groups_id, c.subject, c.fromname, c.date, c.totalfiles, c.filesize, c.collectionhash
		ORDER BY c.id
		LIMIT 1`, groupID)
	if err != nil {
		return releaseCandidateCollection{}, fmt.Errorf("select release candidate for group %d: %w", groupID, err)
	}
	defer rows.Close()

	if !rows.Next() {
		if err := rows.Err(); err != nil {
			return releaseCandidateCollection{}, fmt.Errorf("read release candidate for group %d: %w", groupID, err)
		}
		return releaseCandidateCollection{}, nil
	}

	var candidate releaseCandidateCollection
	var postDate sql.NullTime
	if err := rows.Scan(
		&candidate.CollectionID,
		&candidate.GroupID,
		&candidate.Name,
		&candidate.SearchName,
		&candidate.FromName,
		&postDate,
		&candidate.TotalParts,
		&candidate.Size,
		&candidate.Completion,
		&candidate.GuidSource,
	); err != nil {
		return releaseCandidateCollection{}, fmt.Errorf("scan release candidate for group %d: %w", groupID, err)
	}
	if err := rows.Err(); err != nil {
		return releaseCandidateCollection{}, fmt.Errorf("read release candidate for group %d: %w", groupID, err)
	}
	if postDate.Valid {
		candidate.PostDate = postDate.Time
	} else {
		candidate.PostDate = nil
	}
	if candidate.TotalParts < 1 {
		candidate.TotalParts = 1
	}
	if candidate.Completion < 0 {
		candidate.Completion = 0
	}
	if candidate.Completion > 100 {
		candidate.Completion = 100
	}

	return candidate, nil
}

func truncateString(value string, limit int) string {
	if len(value) <= limit {
		return value
	}

	return value[:limit]
}
