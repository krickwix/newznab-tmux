package backfill

import (
	"bytes"
	"context"
	"database/sql"
	"fmt"
	"math"
	"sort"
	"time"
)

const (
	defaultBackfillQty      = 75000
	defaultMaxMessages      = 20000
	defaultThreads          = 1
	defaultBackfillGroups   = 1
	defaultMinimumSafeRange = 100
)

type Options struct {
	BackfillQty      int
	MaxMessages      int
	Threads          int
	BackfillGroups   int
	BackfillDays     int
	SafeBackfillDate time.Time
	Now              time.Time
	MinimumSafeRange int
}

type SafeBackfillGroup struct {
	Name       string
	OurFirst   int64
	TheirFirst int64
	TheirLast  int64
}

type QueueEntry struct {
	Key     string
	Chunk   int
	Group   string
	Action  string
	Start   int64
	End     int64
	Command string
}

type QueueStats struct {
	Groups           int `json:"groups"`
	QueueEntries     int `json:"queue_entries"`
	Ranges           int `json:"ranges"`
	SkippedInvalid   int `json:"skipped_invalid"`
	SkippedNoWork    int `json:"skipped_no_work"`
	SkippedNearFloor int `json:"skipped_near_floor"`
	Writes           int `json:"writes"`
}

type SafeBackfillPlan struct {
	Groups           int          `json:"groups"`
	QueueEntries     int          `json:"queue_entries"`
	Ranges           int          `json:"ranges"`
	SkippedInvalid   int          `json:"skipped_invalid"`
	SkippedNoWork    int          `json:"skipped_no_work"`
	SkippedNearFloor int          `json:"skipped_near_floor"`
	Queues           []QueueEntry `json:"-"`
	Writes           int          `json:"writes"`
}

func BuildSafeBackfillDryRunPlan(ctx context.Context, db *sql.DB, opts Options) (SafeBackfillPlan, error) {
	opts = opts.withDefaults()
	if opts.BackfillDays == 2 && opts.SafeBackfillDate.IsZero() {
		return SafeBackfillPlan{}, fmt.Errorf("safe backfill date is required when backfill days mode is 2")
	}
	backfillDaysExpression := opts.backfillDaysExpression()

	query := fmt.Sprintf(`
		SELECT g.name,
			g.first_record AS our_first,
			MAX(a.first_record) AS their_first,
			MAX(a.last_record) AS their_last
		FROM usenet_groups g
		INNER JOIN short_groups a ON g.name = a.name
		WHERE g.first_record IS NOT NULL
			AND CAST(g.first_record AS SIGNED) > 0
			AND g.first_record_postdate IS NOT NULL
			AND g.backfill = 1
			AND (NOW() - INTERVAL %s DAY ) < g.first_record_postdate
			AND CAST(a.first_record AS SIGNED) > 0
			AND CAST(a.last_record AS SIGNED) >= CAST(a.first_record AS SIGNED)
			AND (CAST(g.first_record AS SIGNED) - CAST(a.first_record AS SIGNED)) >= ?
		GROUP BY a.name, a.last_record, g.name, g.first_record, g.first_record_postdate
		ORDER BY g.first_record_postdate DESC, g.name ASC
		LIMIT ?`, backfillDaysExpression)

	rows, err := db.QueryContext(ctx, query, opts.MinimumSafeRange, opts.BackfillGroups)
	if err != nil {
		return SafeBackfillPlan{}, fmt.Errorf("select safe backfill groups: %w", err)
	}
	defer rows.Close()

	groups := []SafeBackfillGroup{}
	for rows.Next() {
		var group SafeBackfillGroup
		if err := rows.Scan(&group.Name, &group.OurFirst, &group.TheirFirst, &group.TheirLast); err != nil {
			return SafeBackfillPlan{}, fmt.Errorf("scan safe backfill group: %w", err)
		}
		groups = append(groups, group)
	}
	if err := rows.Err(); err != nil {
		return SafeBackfillPlan{}, fmt.Errorf("read safe backfill groups: %w", err)
	}

	queues, stats := SafeBackfillQueueEntries(groups, opts.BackfillQty, opts.MaxMessages, opts.Threads, opts.MinimumSafeRange)
	diagnostics, err := safeBackfillDiagnostics(ctx, db, backfillDaysExpression, opts.MinimumSafeRange)
	if err != nil {
		return SafeBackfillPlan{}, err
	}
	stats.SkippedInvalid += diagnostics.SkippedInvalid
	stats.SkippedNoWork += diagnostics.SkippedNoWork
	stats.SkippedNearFloor += diagnostics.SkippedNearFloor

	return SafeBackfillPlan{
		Groups:           stats.Groups,
		QueueEntries:     stats.QueueEntries,
		Ranges:           stats.Ranges,
		SkippedInvalid:   stats.SkippedInvalid,
		SkippedNoWork:    stats.SkippedNoWork,
		SkippedNearFloor: stats.SkippedNearFloor,
		Queues:           queues,
		Writes:           stats.Writes,
	}, nil
}

func SafeBackfillQueueEntries(data []SafeBackfillGroup, backfillQty int, maxMessages int, threads int, minimumSafeRange int) ([]QueueEntry, QueueStats) {
	if backfillQty < 1 {
		backfillQty = defaultBackfillQty
	}
	if maxMessages < 1 {
		maxMessages = defaultMaxMessages
	}
	if threads < 1 {
		threads = defaultThreads
	}
	if minimumSafeRange < 1 {
		minimumSafeRange = defaultMinimumSafeRange
	}

	stats := QueueStats{
		Groups: len(data),
		Writes: 0,
	}
	queuesByChunk := map[int][]QueueEntry{}
	chunks := map[int]struct{}{}

	for _, group := range data {
		if group.OurFirst <= 0 || group.TheirFirst <= 0 || group.TheirLast < group.TheirFirst {
			stats.SkippedInvalid++
			continue
		}

		count := group.OurFirst - group.TheirFirst
		if count <= 0 {
			stats.SkippedNoWork++
			continue
		}

		if count < int64(minimumSafeRange) {
			stats.SkippedNearFloor++
			continue
		}

		getEach := int(math.Ceil(float64(count) / float64(maxMessages)))
		if count > int64(backfillQty*threads) {
			getEach = int(math.Ceil(float64(backfillQty*threads) / float64(maxMessages)))
		}

		for i := 0; i <= getEach-1; i++ {
			chunk := i + 1
			end := group.OurFirst - int64(i*maxMessages) - 1
			start := maxInt64(group.TheirFirst, end-int64(maxMessages)+1)
			if end < group.TheirFirst || start > end {
				continue
			}

			key := fmt.Sprintf("%s#%d", group.Name, chunk)
			entry := QueueEntry{
				Key:     key,
				Chunk:   chunk,
				Group:   group.Name,
				Action:  "get_range",
				Start:   start,
				End:     end,
				Command: fmt.Sprintf("get_range  backfill  %s  %d  %d  %d", group.Name, start, end, chunk),
			}
			queuesByChunk[i] = append(queuesByChunk[i], entry)
			chunks[i] = struct{}{}
		}
	}

	orderedChunks := make([]int, 0, len(chunks))
	for chunk := range chunks {
		orderedChunks = append(orderedChunks, chunk)
	}
	sort.Ints(orderedChunks)

	queues := []QueueEntry{}
	for _, chunk := range orderedChunks {
		queues = append(queues, queuesByChunk[chunk]...)
	}

	stats.QueueEntries = len(queues)
	stats.Ranges = len(queues)

	return queues, stats
}

func safeBackfillDiagnostics(ctx context.Context, db *sql.DB, backfillDaysExpression string, minimumSafeRange int) (QueueStats, error) {
	query := fmt.Sprintf(`
		SELECT
			COALESCE(SUM(CASE
				WHEN our_first IS NULL
					OR CAST(our_first AS SIGNED) <= 0
					OR CAST(their_first AS SIGNED) <= 0
					OR CAST(their_last AS SIGNED) < CAST(their_first AS SIGNED)
				THEN 1 ELSE 0 END), 0) AS skipped_invalid,
			COALESCE(SUM(CASE
				WHEN our_first IS NOT NULL
					AND CAST(our_first AS SIGNED) > 0
					AND CAST(their_first AS SIGNED) > 0
					AND CAST(their_last AS SIGNED) >= CAST(their_first AS SIGNED)
					AND (CAST(our_first AS SIGNED) - CAST(their_first AS SIGNED)) <= 0
				THEN 1 ELSE 0 END), 0) AS skipped_no_work,
			COALESCE(SUM(CASE
				WHEN our_first IS NOT NULL
					AND CAST(our_first AS SIGNED) > 0
					AND CAST(their_first AS SIGNED) > 0
					AND CAST(their_last AS SIGNED) >= CAST(their_first AS SIGNED)
					AND (CAST(our_first AS SIGNED) - CAST(their_first AS SIGNED)) > 0
					AND (CAST(our_first AS SIGNED) - CAST(their_first AS SIGNED)) < ?
				THEN 1 ELSE 0 END), 0) AS skipped_near_floor
		FROM (
			SELECT g.name,
				g.first_record AS our_first,
				MAX(a.first_record) AS their_first,
				MAX(a.last_record) AS their_last
			FROM usenet_groups g
			INNER JOIN short_groups a ON g.name = a.name
			WHERE g.backfill = 1
				AND g.first_record_postdate IS NOT NULL
				AND (NOW() - INTERVAL %s DAY ) < g.first_record_postdate
			GROUP BY a.name, a.last_record, g.name, g.first_record, g.first_record_postdate
		) candidates`, backfillDaysExpression)

	var stats QueueStats
	if err := db.QueryRowContext(ctx, query, minimumSafeRange).Scan(&stats.SkippedInvalid, &stats.SkippedNoWork, &stats.SkippedNearFloor); err != nil {
		return QueueStats{}, fmt.Errorf("select safe backfill diagnostics: %w", err)
	}

	return stats, nil
}

func DryRunSummary(plan SafeBackfillPlan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "backfill mysql dry-run")
	fmt.Fprintf(&buffer, "groups=%d\n", plan.Groups)
	fmt.Fprintf(&buffer, "queue-entries=%d\n", plan.QueueEntries)
	fmt.Fprintf(&buffer, "ranges=%d\n", plan.Ranges)
	fmt.Fprintf(&buffer, "skipped-invalid=%d\n", plan.SkippedInvalid)
	fmt.Fprintf(&buffer, "skipped-no-work=%d\n", plan.SkippedNoWork)
	fmt.Fprintf(&buffer, "skipped-near-floor=%d\n", plan.SkippedNearFloor)
	fmt.Fprintf(&buffer, "writes=%d\n", plan.Writes)

	return buffer.String()
}

func (opts Options) withDefaults() Options {
	if opts.BackfillQty < 1 {
		opts.BackfillQty = defaultBackfillQty
	}
	if opts.MaxMessages < 1 {
		opts.MaxMessages = defaultMaxMessages
	}
	if opts.Threads < 1 {
		opts.Threads = defaultThreads
	}
	if opts.BackfillGroups < 1 {
		opts.BackfillGroups = defaultBackfillGroups
	}
	if opts.MinimumSafeRange < 1 {
		opts.MinimumSafeRange = defaultMinimumSafeRange
	}
	if opts.Now.IsZero() {
		opts.Now = time.Now()
	}

	return opts
}

func (opts Options) backfillDaysExpression() string {
	switch opts.BackfillDays {
	case 1:
		return "g.backfill_target"
	case 2:
		if opts.SafeBackfillDate.IsZero() {
			return "0"
		}
		days := int(math.Abs(opts.Now.Sub(opts.SafeBackfillDate).Hours()) / 24)
		return fmt.Sprintf("%d", days)
	default:
		return "0"
	}
}

func maxInt64(a int64, b int64) int64 {
	if a > b {
		return a
	}

	return b
}
