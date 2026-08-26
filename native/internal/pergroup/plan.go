package pergroup

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
	CandidateGroups int          `json:"candidate_groups"`
	QueueEntries    int          `json:"queue_entries"`
	MaxProcesses    int          `json:"max_processes"`
	Batches         int          `json:"batches"`
	Queues          []QueueEntry `json:"-"`
	Writes          int          `json:"writes"`
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
		return Plan{}, fmt.Errorf("select per-group groups: %w", err)
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
			return Plan{}, fmt.Errorf("scan per-group group: %w", err)
		}

		plan.CandidateGroups++
		plan.Queues = append(plan.Queues, QueueEntry{
			GroupID: groupID,
			Name:    name,
			Command: fmt.Sprintf("update_per_group  %d", groupID),
		})
	}
	if err := rows.Err(); err != nil {
		return Plan{}, fmt.Errorf("read per-group groups: %w", err)
	}

	plan.QueueEntries = len(plan.Queues)
	if plan.QueueEntries > 0 {
		plan.Batches = (plan.QueueEntries + maxProcesses - 1) / maxProcesses
	}

	return plan, nil
}

func DryRunSummary(plan Plan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "per-group mysql dry-run")
	fmt.Fprintf(&buffer, "candidate-groups=%d\n", plan.CandidateGroups)
	fmt.Fprintf(&buffer, "queue-entries=%d\n", plan.QueueEntries)
	fmt.Fprintf(&buffer, "max-processes=%d\n", plan.MaxProcesses)
	fmt.Fprintf(&buffer, "batches=%d\n", plan.Batches)
	fmt.Fprintf(&buffer, "writes=%d\n", plan.Writes)

	return buffer.String()
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
