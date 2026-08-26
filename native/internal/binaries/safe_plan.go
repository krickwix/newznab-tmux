package binaries

import (
	"bytes"
	"context"
	"database/sql"
	"fmt"
)

const (
	defaultMaxHeaders  = 1000000
	defaultMaxMessages = 20000
	safeBinariesSkip   = 20000
)

type SafeBinaryGroup struct {
	Name      string
	OurLast   int64
	TheirLast int64
}

type QueueEntry struct {
	Index   int
	Group   string
	Action  string
	Start   int64
	End     int64
	Command string
}

type SafeBinariesPlan struct {
	Groups        int          `json:"groups"`
	QueueEntries  int          `json:"queue_entries"`
	HeaderUpdates int          `json:"header_updates"`
	PartRepair    int          `json:"part_repair"`
	Ranges        int          `json:"ranges"`
	Queues        []QueueEntry `json:"-"`
	Writes        int          `json:"writes"`
}

func BuildSafeBinariesDryRunPlan(ctx context.Context, db *sql.DB, maxMessages int, maxHeaders int) (SafeBinariesPlan, error) {
	if maxMessages < 1 {
		maxMessages = defaultMaxMessages
	}
	if maxHeaders < 1 {
		maxHeaders = defaultMaxHeaders
	}

	rows, err := db.QueryContext(ctx, `
		SELECT g.name AS groupname, g.last_record AS our_last, a.last_record AS their_last
		FROM usenet_groups g
		INNER JOIN short_groups a ON g.active = 1 AND g.name = a.name
		ORDER BY a.last_record DESC`)
	if err != nil {
		return SafeBinariesPlan{}, fmt.Errorf("select safe binaries groups: %w", err)
	}
	defer rows.Close()

	plan := SafeBinariesPlan{
		Queues: []QueueEntry{},
		Writes: 0,
	}
	nextIndex := 1
	for rows.Next() {
		var group SafeBinaryGroup
		if err := rows.Scan(&group.Name, &group.OurLast, &group.TheirLast); err != nil {
			return SafeBinariesPlan{}, fmt.Errorf("scan safe binaries group: %w", err)
		}

		plan.Groups++
		entries := SafeBinaryQueueEntries(group, maxMessages, maxHeaders, nextIndex)
		nextIndex += len(entries)
		for _, entry := range entries {
			switch entry.Action {
			case "update_group_headers":
				plan.HeaderUpdates++
			case "part_repair":
				plan.PartRepair++
			case "get_range":
				plan.Ranges++
			}
			plan.Queues = append(plan.Queues, entry)
		}
	}
	if err := rows.Err(); err != nil {
		return SafeBinariesPlan{}, fmt.Errorf("read safe binaries groups: %w", err)
	}
	plan.QueueEntries = len(plan.Queues)

	return plan, nil
}

func SafeBinaryQueueEntries(group SafeBinaryGroup, maxMessages int, maxHeaders int, firstIndex int) []QueueEntry {
	if maxMessages < 1 {
		maxMessages = defaultMaxMessages
	}
	if maxHeaders < 1 {
		maxHeaders = defaultMaxHeaders
	}

	if group.OurLast == 0 {
		return []QueueEntry{headerUpdateEntry(group.Name, firstIndex)}
	}

	count := group.TheirLast - group.OurLast - safeBinariesSkip
	if count <= int64(maxMessages*2) {
		return []QueueEntry{headerUpdateEntry(group.Name, firstIndex)}
	}

	limit := minInt64(count, int64(maxHeaders))
	fullChunks := int(limit / int64(maxMessages))
	remaining := limit - int64(fullChunks*maxMessages)

	entries := []QueueEntry{{
		Index:   firstIndex,
		Group:   group.Name,
		Action:  "part_repair",
		Command: fmt.Sprintf("part_repair  %s", group.Name),
	}}

	for j := 0; j < fullChunks; j++ {
		index := firstIndex + len(entries)
		start := group.OurLast + int64(j*maxMessages) + 1
		end := group.OurLast + int64(j*maxMessages) + int64(maxMessages)
		entries = append(entries, getRangeEntry(group.Name, index, start, end))
	}

	if remaining > 0 {
		index := firstIndex + len(entries)
		start := group.OurLast + int64(fullChunks*maxMessages) + 1
		end := start + remaining - 1
		entries = append(entries, getRangeEntry(group.Name, index, start, end))
	}

	return entries
}

func DryRunSummary(plan SafeBinariesPlan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "binaries mysql dry-run")
	fmt.Fprintf(&buffer, "groups=%d\n", plan.Groups)
	fmt.Fprintf(&buffer, "queue-entries=%d\n", plan.QueueEntries)
	fmt.Fprintf(&buffer, "part-repair=%d\n", plan.PartRepair)
	fmt.Fprintf(&buffer, "ranges=%d\n", plan.Ranges)
	fmt.Fprintf(&buffer, "header-updates=%d\n", plan.HeaderUpdates)
	fmt.Fprintf(&buffer, "writes=%d\n", plan.Writes)

	return buffer.String()
}

func headerUpdateEntry(group string, index int) QueueEntry {
	return QueueEntry{
		Index:   index,
		Group:   group,
		Action:  "update_group_headers",
		Command: fmt.Sprintf("update_group_headers  %s", group),
	}
}

func getRangeEntry(group string, index int, start int64, end int64) QueueEntry {
	return QueueEntry{
		Index:   index,
		Group:   group,
		Action:  "get_range",
		Start:   start,
		End:     end,
		Command: fmt.Sprintf("get_range  binaries  %s  %d  %d  %d", group, start, end, index),
	}
}

func minInt64(a int64, b int64) int64 {
	if a < b {
		return a
	}

	return b
}
