package releases

import (
	"bytes"
	"context"
	"database/sql"
	"fmt"
	"strconv"
	"strings"
)

type QueueEntry struct {
	GroupID int64
	Name    string
	Command string
}

type Plan struct {
	CandidateGroups      int          `json:"candidate_groups"`
	EligibleGroups       int          `json:"eligible_groups"`
	SkippedNoCollections int          `json:"skipped_no_collections"`
	QueueEntries         int          `json:"queue_entries"`
	MaxProcesses         int          `json:"max_processes"`
	Batches              int          `json:"batches"`
	Queues               []QueueEntry `json:"-"`
	Writes               int          `json:"writes"`
}

func BuildDryRunPlan(ctx context.Context, db *sql.DB) (Plan, error) {
	maxProcesses, err := settingInt(ctx, db, "releasethreads")
	if err != nil {
		return Plan{}, err
	}
	if maxProcesses < 1 {
		maxProcesses = 1
	}

	rows, err := db.QueryContext(ctx, `
		SELECT id, name
		FROM usenet_groups
		WHERE active = 1 OR backfill = 1`)
	if err != nil {
		return Plan{}, fmt.Errorf("select release groups: %w", err)
	}
	defer rows.Close()

	plan := Plan{
		MaxProcesses: maxProcesses,
		Queues:       []QueueEntry{},
		Writes:       0,
	}
	for rows.Next() {
		var groupID int64
		var name string
		if err := rows.Scan(&groupID, &name); err != nil {
			return Plan{}, fmt.Errorf("scan release group: %w", err)
		}

		plan.CandidateGroups++
		hasCollections, err := hasCollections(ctx, db, groupID)
		if err != nil {
			return Plan{}, fmt.Errorf("select collections for group %d: %w", groupID, err)
		}
		if !hasCollections {
			plan.SkippedNoCollections++
			continue
		}

		plan.Queues = append(plan.Queues, QueueEntry{
			GroupID: groupID,
			Name:    name,
			Command: fmt.Sprintf("releases  %d", groupID),
		})
	}
	if err := rows.Err(); err != nil {
		return Plan{}, fmt.Errorf("read release groups: %w", err)
	}

	plan.EligibleGroups = len(plan.Queues)
	plan.QueueEntries = len(plan.Queues)
	if plan.QueueEntries > 0 {
		plan.Batches = (plan.QueueEntries + maxProcesses - 1) / maxProcesses
	}

	return plan, nil
}

func DryRunSummary(plan Plan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "releases mysql dry-run")
	fmt.Fprintf(&buffer, "candidate-groups=%d\n", plan.CandidateGroups)
	fmt.Fprintf(&buffer, "eligible-groups=%d\n", plan.EligibleGroups)
	fmt.Fprintf(&buffer, "skipped-no-collections=%d\n", plan.SkippedNoCollections)
	fmt.Fprintf(&buffer, "queue-entries=%d\n", plan.QueueEntries)
	fmt.Fprintf(&buffer, "max-processes=%d\n", plan.MaxProcesses)
	fmt.Fprintf(&buffer, "batches=%d\n", plan.Batches)
	fmt.Fprintf(&buffer, "writes=%d\n", plan.Writes)

	return buffer.String()
}

func hasCollections(ctx context.Context, db *sql.DB, groupID int64) (bool, error) {
	var id int64
	err := db.QueryRowContext(ctx, "SELECT id FROM collections WHERE groups_id = ? LIMIT 1", groupID).Scan(&id)
	if err == sql.ErrNoRows {
		return false, nil
	}
	if err != nil {
		return false, err
	}

	return true, nil
}

func settingInt(ctx context.Context, db *sql.DB, name string) (int, error) {
	var value sql.NullString
	if err := db.QueryRowContext(ctx, "SELECT value FROM settings WHERE name = ?", name).Scan(&value); err != nil {
		if err == sql.ErrNoRows {
			return 0, nil
		}

		return 0, fmt.Errorf("read setting %s: %w", name, err)
	}
	if !value.Valid {
		return 0, nil
	}

	parsed, err := strconv.Atoi(strings.TrimSpace(value.String))
	if err != nil {
		return 0, nil
	}

	return parsed, nil
}
