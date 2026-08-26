package backfill

import (
	"context"
	"database/sql"
	"fmt"

	"nntmux-native/internal/nntp"
)

type WriteRehearsalResult struct {
	QueueEntries           int   `json:"queue_entries"`
	CursorUpdatesAttempted int   `json:"cursor_updates_attempted"`
	CursorRowsAffected     int64 `json:"cursor_rows_affected"`
	HeaderRowsAttempted    int   `json:"header_rows_attempted"`
	HeaderRowsAffected     int64 `json:"header_rows_affected"`
	PartRowsAttempted      int   `json:"part_rows_attempted"`
	PartRowsAffected       int64 `json:"part_rows_affected"`
	RolledBack             bool  `json:"rolled_back"`
	WritesCommitted        int   `json:"writes_committed"`
}

func RehearseSafeBackfillWrites(ctx context.Context, db *sql.DB, plan SafeBackfillPlan) (WriteRehearsalResult, error) {
	return runSafeBackfillWrites(ctx, db, plan, true)
}

func RehearseOverviewSampleWrites(ctx context.Context, db *sql.DB, sample nntp.OverviewSampleReport) (WriteRehearsalResult, error) {
	return runOverviewSampleWrites(ctx, db, sample, true)
}

func CommitSafeBackfillWrites(ctx context.Context, db *sql.DB, plan SafeBackfillPlan) (WriteRehearsalResult, error) {
	return runSafeBackfillWrites(ctx, db, plan, false)
}

func CommitOverviewSampleWrites(ctx context.Context, db *sql.DB, sample nntp.OverviewSampleReport) (WriteRehearsalResult, error) {
	return runOverviewSampleWrites(ctx, db, sample, false)
}

func runSafeBackfillWrites(ctx context.Context, db *sql.DB, plan SafeBackfillPlan, rollbackOnly bool) (WriteRehearsalResult, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return WriteRehearsalResult{}, err
	}

	result := WriteRehearsalResult{
		QueueEntries: len(plan.Queues),
	}
	rollback := func() error {
		if err := tx.Rollback(); err != nil {
			return fmt.Errorf("rollback safe backfill write rehearsal: %w", err)
		}
		result.RolledBack = true
		return nil
	}

	for _, queue := range plan.Queues {
		if queue.Action != "get_range" {
			_ = rollback()
			return result, fmt.Errorf("unsupported backfill rehearsal action %q", queue.Action)
		}

		cursorRows, headerRows, partRows, err := rehearseBackfillRange(ctx, tx, queue)
		if err != nil {
			_ = rollback()
			return result, err
		}
		result.CursorUpdatesAttempted++
		result.CursorRowsAffected += cursorRows
		result.HeaderRowsAttempted++
		result.HeaderRowsAffected += headerRows
		result.PartRowsAttempted++
		result.PartRowsAffected += partRows
	}

	if rollbackOnly {
		if err := rollback(); err != nil {
			return result, err
		}
		return result, nil
	}

	if err := tx.Commit(); err != nil {
		return result, fmt.Errorf("commit safe backfill writes: %w", err)
	}
	result.WritesCommitted = int(result.CursorRowsAffected + result.HeaderRowsAffected + result.PartRowsAffected)
	result.RolledBack = false

	return result, nil
}

func runOverviewSampleWrites(ctx context.Context, db *sql.DB, sample nntp.OverviewSampleReport, rollbackOnly bool) (WriteRehearsalResult, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return WriteRehearsalResult{}, err
	}

	result := WriteRehearsalResult{
		QueueEntries:        len(sample.Candidates),
		HeaderRowsAttempted: len(sample.Candidates),
		PartRowsAttempted:   len(sample.Candidates),
	}
	rollback := func() error {
		if err := tx.Rollback(); err != nil {
			return fmt.Errorf("rollback sampled backfill write rehearsal: %w", err)
		}
		result.RolledBack = true
		return nil
	}

	groupLowWater := map[string]int64{}
	for _, candidate := range sample.Candidates {
		if candidate.Group == "" || candidate.Article < 1 || candidate.Subject == "" || candidate.MessageID == "" {
			_ = rollback()
			return result, fmt.Errorf("sampled backfill candidate is incomplete")
		}
		if current, ok := groupLowWater[candidate.Group]; !ok || candidate.Article < current {
			groupLowWater[candidate.Group] = candidate.Article
		}
	}

	for group, article := range groupLowWater {
		cursorRows, err := rehearseBackfillSampleCursor(ctx, tx, group, article)
		if err != nil {
			_ = rollback()
			return result, err
		}
		result.CursorUpdatesAttempted++
		result.CursorRowsAffected += cursorRows
	}

	for _, candidate := range sample.Candidates {
		headerRows, partRows, err := rehearseBackfillSampleCandidate(ctx, tx, candidate)
		if err != nil {
			_ = rollback()
			return result, err
		}
		result.HeaderRowsAffected += headerRows
		result.PartRowsAffected += partRows
	}

	if rollbackOnly {
		if err := rollback(); err != nil {
			return result, err
		}

		return result, nil
	}

	if err := tx.Commit(); err != nil {
		return result, fmt.Errorf("commit sampled backfill writes: %w", err)
	}
	result.WritesCommitted = int(result.CursorRowsAffected + result.HeaderRowsAffected + result.PartRowsAffected)
	result.RolledBack = false

	return result, nil
}

func rehearseBackfillRange(ctx context.Context, tx *sql.Tx, queue QueueEntry) (int64, int64, int64, error) {
	cursor, err := tx.ExecContext(ctx, `
		UPDATE usenet_groups
		SET first_record = ?,
			first_record_postdate = UTC_TIMESTAMP(6)
		WHERE name = ?
			AND first_record > ?`, queue.Start, queue.Group, queue.Start)
	if err != nil {
		return 0, 0, 0, fmt.Errorf("rehearse backfill cursor update for %s: %w", queue.Group, err)
	}
	cursorRows, err := cursor.RowsAffected()
	if err != nil {
		return 0, 0, 0, err
	}

	subject := fmt.Sprintf("native-backfill-rehearsal:%s:%d-%d", queue.Group, queue.Start, queue.End)
	messageID := fmt.Sprintf("<native-backfill-rehearsal-%s-%d-%d>", queue.Group, queue.Start, queue.End)
	headerRows, partRows, err := insertProductionShapeBackfillCandidate(ctx, tx, queue.Group, subject, 1, 1, messageID, queue.Start, 0)
	if err != nil {
		return 0, 0, 0, fmt.Errorf("rehearse backfill range insert for %s: %w", queue.Group, err)
	}

	return cursorRows, headerRows, partRows, nil
}

func rehearseBackfillSampleCursor(ctx context.Context, tx *sql.Tx, group string, article int64) (int64, error) {
	cursor, err := tx.ExecContext(ctx, `
		UPDATE usenet_groups
		SET first_record = ?,
			first_record_postdate = UTC_TIMESTAMP(6)
		WHERE name = ?
			AND first_record > ?`, article, group, article)
	if err != nil {
		return 0, fmt.Errorf("rehearse sampled backfill cursor update for %s: %w", group, err)
	}
	rows, err := cursor.RowsAffected()
	if err != nil {
		return 0, err
	}

	return rows, nil
}

func rehearseBackfillSampleCandidate(ctx context.Context, tx *sql.Tx, candidate nntp.OverviewCandidate) (int64, int64, error) {
	binaryName, partNumber, totalParts := overviewCandidatePartMetadata(candidate)

	return insertProductionShapeBackfillCandidate(ctx, tx, candidate.Group, binaryName, partNumber, totalParts, candidate.MessageID, candidate.Article, candidate.Bytes)
}

func insertProductionShapeBackfillCandidate(ctx context.Context, tx *sql.Tx, group string, binaryName string, partNumber int, totalParts int, messageID string, article int64, bytes int) (int64, int64, error) {
	collectionHashSource := fmt.Sprintf("%s\x00%s", group, binaryName)
	collection, err := tx.ExecContext(ctx, `
		INSERT INTO collections (
			subject,
			fromname,
			date,
			xref,
			totalfiles,
			groups_id,
			collectionhash,
			collection_regexes_id,
			dateadded,
			noise
		)
		SELECT ?, '', UTC_TIMESTAMP(6), '', 1, id, SHA1(?), 0, UTC_TIMESTAMP(6), ''
		FROM usenet_groups
		WHERE name = ?
		LIMIT 1
		ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(collections.id)`, truncateString(binaryName, 255), collectionHashSource, group)
	if err != nil {
		return 0, 0, fmt.Errorf("insert collection for %s: %w", group, err)
	}
	collectionRows, err := collection.RowsAffected()
	if err != nil {
		return 0, 0, err
	}
	collectionID := int64(0)
	if collectionRows == 0 {
		err = tx.QueryRowContext(ctx, `
			SELECT id
			FROM collections
			WHERE collectionhash = SHA1(?)
			LIMIT 1`, collectionHashSource).Scan(&collectionID)
		if err == sql.ErrNoRows {
			return 0, 0, nil
		}
		if err != nil {
			return 0, 0, fmt.Errorf("select collection for %s: %w", group, err)
		}
	} else {
		collectionID, err = collection.LastInsertId()
		if err != nil {
			return 0, 0, err
		}
	}
	if collectionID == 0 {
		return 0, 0, fmt.Errorf("insert collection for %s did not return an id", group)
	}

	header, err := tx.ExecContext(ctx, `
		INSERT INTO binaries (
			binaryhash,
			name,
			collections_id,
			totalparts,
			currentparts,
			filenumber,
			partsize
		)
		VALUES (UNHEX(MD5(?)), ?, ?, ?, 1, 1, ?)
		ON DUPLICATE KEY UPDATE
			id = LAST_INSERT_ID(id),
			totalparts = GREATEST(totalparts, VALUES(totalparts))`, binaryName, truncateString(binaryName, 1000), collectionID, totalParts, bytes)
	if err != nil {
		return 0, 0, fmt.Errorf("insert binary for %s: %w", group, err)
	}
	headerRows, err := header.RowsAffected()
	if err != nil {
		return 0, 0, err
	}
	binaryID := int64(0)
	if headerRows == 0 {
		err = tx.QueryRowContext(ctx, `
			SELECT id
			FROM binaries
			WHERE collections_id = ?
				AND filenumber = 1
			LIMIT 1`, collectionID).Scan(&binaryID)
		if err == sql.ErrNoRows {
			return 0, 0, nil
		}
		if err != nil {
			return 0, 0, fmt.Errorf("select binary for %s: %w", group, err)
		}
	} else {
		binaryID, err = header.LastInsertId()
		if err != nil {
			return 0, 0, err
		}
	}
	if binaryID == 0 {
		return 0, 0, fmt.Errorf("insert binary for %s did not return an id", group)
	}

	part, err := tx.ExecContext(ctx, `
		INSERT INTO parts (binaries_id, messageid, number, partnumber, size)
		VALUES (?, ?, ?, ?, ?)
		ON DUPLICATE KEY UPDATE messageid = messageid`, binaryID, messageID, article, partNumber, bytes)
	if err != nil {
		return 0, 0, fmt.Errorf("insert part for %s: %w", group, err)
	}
	partRows, err := part.RowsAffected()
	if err != nil {
		return 0, 0, err
	}
	if headerRows != 1 && partRows > 0 {
		aggregate, err := tx.ExecContext(ctx, `
			UPDATE binaries
			SET currentparts = currentparts + 1,
				partsize = partsize + ?
			WHERE id = ?`, bytes, binaryID)
		if err != nil {
			return 0, 0, fmt.Errorf("update binary aggregate for %s: %w", group, err)
		}
		aggregateRows, err := aggregate.RowsAffected()
		if err != nil {
			return 0, 0, err
		}
		headerRows += aggregateRows
	}

	return headerRows, partRows, nil
}

func overviewCandidatePartMetadata(candidate nntp.OverviewCandidate) (string, int, int) {
	binaryName := candidate.BinaryName
	partNumber := candidate.PartNumber
	totalParts := candidate.TotalParts
	if binaryName == "" || partNumber < 1 || totalParts < 1 || partNumber > totalParts {
		binaryName, partNumber, totalParts = nntp.ParseOverviewSubject(candidate.Subject)
	}
	if binaryName == "" {
		binaryName = candidate.Subject
	}
	if partNumber < 1 || totalParts < 1 || partNumber > totalParts {
		partNumber = 1
		totalParts = 1
	}

	return binaryName, partNumber, totalParts
}

func truncateString(value string, limit int) string {
	if len(value) <= limit {
		return value
	}

	return value[:limit]
}
