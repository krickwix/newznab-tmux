package namefix

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"sort"
	"strconv"
	"strings"
)

type WriteRehearsalResult struct {
	SingleColumnUpdatesAttempted int     `json:"single_column_updates_attempted"`
	SingleColumnRowsAffected     int64   `json:"single_column_rows_affected"`
	ReleaseUpdatesAttempted      int     `json:"release_updates_attempted"`
	ReleaseUpdateRowsAffected    int64   `json:"release_update_rows_affected"`
	ReleaseUpdatesBlocked        int     `json:"release_updates_blocked"`
	BlockedReleaseIDs            []int64 `json:"blocked_release_ids"`
	RolledBack                   bool    `json:"rolled_back"`
	WritesCommitted              int     `json:"writes_committed"`
}

type MissStatusCommitResult struct {
	SingleColumnUpdatesAttempted int     `json:"single_column_updates_attempted"`
	SingleColumnUpdatesCommitted int     `json:"single_column_updates_committed"`
	SingleColumnUpdatesSkipped   int     `json:"single_column_updates_skipped"`
	SingleColumnUpdatesBlocked   int     `json:"single_column_updates_blocked"`
	SingleColumnRowsAffected     int64   `json:"single_column_rows_affected"`
	ReleaseUpdatesBlocked        int     `json:"release_updates_blocked"`
	BlockedReleaseIDs            []int64 `json:"blocked_release_ids"`
	BlockedStatusReleaseIDs      []int64 `json:"blocked_status_release_ids"`
	CommittedReleaseIDs          []int64 `json:"committed_release_ids"`
	SkippedReleaseIDs            []int64 `json:"skipped_release_ids"`
	LockAcquired                 bool    `json:"lock_acquired"`
	LockMode                     string  `json:"lock_mode,omitempty"`
	SearchSideEffectRowsEnqueued int     `json:"search_side_effect_rows_enqueued"`
	SearchUpdatesEnqueued        int     `json:"search_updates_enqueued"`
	RolledBack                   bool    `json:"rolled_back"`
	WritesCommitted              int     `json:"writes_committed"`
}

type MissStatusCommitOptions struct {
	Job        string
	Categories []int
}

type ResolvedWriteContractOracle struct {
	SchemaVersion int                   `json:"schema_version"`
	Mode          string                `json:"mode"`
	DryRun        bool                  `json:"dry_run"`
	Writes        int                   `json:"writes"`
	WriteContract ResolvedWriteContract `json:"write_contract"`
}

type ResolvedWriteContract struct {
	Writes                 int                     `json:"writes"`
	ResolvedReleaseUpdates []ResolvedReleaseUpdate `json:"resolved_release_updates"`
}

type ResolvedReleaseUpdate struct {
	ReleaseID          int64                      `json:"release_id"`
	Columns            []PlannedColumn            `json:"columns"`
	CategoryResolution ResolvedCategoryResolution `json:"category_resolution"`
	RequiredEvent      ResolvedRequiredEvent      `json:"required_event"`
}

type ResolvedCategoryResolution struct {
	GroupID       int64  `json:"group_id"`
	NewName       string `json:"new_name"`
	PosterPresent bool   `json:"poster_present"`
	CategoriesID  int    `json:"categories_id"`
	ValueSource   string `json:"value_source"`
}

type ResolvedRequiredEvent struct {
	ReleaseID     int64  `json:"release_id"`
	OldName       string `json:"old_name"`
	NewName       string `json:"new_name"`
	OldCategoryID int    `json:"old_category_id"`
	NewCategoryID int    `json:"new_category_id"`
	GroupID       int64  `json:"group_id"`
	PosterPresent bool   `json:"poster_present"`
}

type strictZero struct {
	Value int
}

var rehearseReleaseUpdateColumns = map[string]bool{
	"anidbid":        true,
	"bookinfo_id":    true,
	"categories_id":  true,
	"consoleinfo_id": true,
	"imdbid":         true,
	"iscategorized":  true,
	"isrenamed":      true,
	"musicinfo_id":   true,
	"predb_id":       true,
	"proc_crc32":     true,
	"proc_hash16k":   true,
	"searchname":     true,
	"tv_episodes_id": true,
	"videos_id":      true,
}

func RehearseHashedFixWriteContract(ctx context.Context, db *sql.DB, contract HashedFixWriteContract) (WriteRehearsalResult, error) {
	return rehearseHashedFixWriteContract(ctx, db, contract, nil)
}

func RehearseResolvedHashedFixWriteContract(ctx context.Context, db *sql.DB, contract HashedFixWriteContract, oracle ResolvedWriteContractOracle) (WriteRehearsalResult, error) {
	return rehearseHashedFixWriteContract(ctx, db, contract, &oracle)
}

func CommitHashedFixMissStatusUpdates(ctx context.Context, db *sql.DB, contract HashedFixWriteContract) (MissStatusCommitResult, error) {
	return CommitMissStatusUpdates(ctx, db, contract, MissStatusCommitOptions{
		Job:        "hashed-fixnames",
		Categories: []int{otherHashedCategory},
	})
}

func CommitRegularFixMissStatusUpdates(ctx context.Context, db *sql.DB, contract HashedFixWriteContract) (MissStatusCommitResult, error) {
	return CommitMissStatusUpdates(ctx, db, contract, MissStatusCommitOptions{
		Job:        "fixnames",
		Categories: regularFixStatusCommitCategories(),
	})
}

func CommitMissStatusUpdates(ctx context.Context, db *sql.DB, contract HashedFixWriteContract, options MissStatusCommitOptions) (MissStatusCommitResult, error) {
	job := strings.TrimSpace(options.Job)
	if job == "" {
		job = "hashed-fixnames"
	}
	categories := options.Categories
	if len(categories) == 0 {
		categories = []int{otherHashedCategory}
	}

	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return MissStatusCommitResult{}, err
	}

	result := MissStatusCommitResult{
		BlockedReleaseIDs:       make([]int64, 0, len(contract.ReleaseUpdates)),
		BlockedStatusReleaseIDs: make([]int64, 0, len(contract.SingleColumnUpdates)),
		CommittedReleaseIDs:     make([]int64, 0, len(contract.SingleColumnUpdates)),
		SkippedReleaseIDs:       make([]int64, 0, len(contract.SingleColumnUpdates)),
	}

	rollback := func() error {
		if err := tx.Rollback(); err != nil {
			return fmt.Errorf("rollback miss-status commit: %w", err)
		}
		return nil
	}

	releaseUpdateIDs := map[int64]bool{}
	for _, releaseUpdate := range contract.ReleaseUpdates {
		if releaseUpdate.ReleaseID == 0 {
			if err := rollback(); err != nil {
				return result, err
			}
			return result, fmt.Errorf("release update has invalid release id 0")
		}
		if releaseUpdateIDs[releaseUpdate.ReleaseID] {
			if err := rollback(); err != nil {
				return result, err
			}
			return result, fmt.Errorf("duplicate release update for release %d", releaseUpdate.ReleaseID)
		}
		releaseUpdateIDs[releaseUpdate.ReleaseID] = true
		result.ReleaseUpdatesBlocked++
		result.BlockedReleaseIDs = append(result.BlockedReleaseIDs, releaseUpdate.ReleaseID)
	}

	eligibleIDs := map[int64]bool{}
	for _, update := range contract.SingleColumnUpdates {
		if !isCommittableMissStatusColumn(update.Column) {
			if err := rollback(); err != nil {
				return result, err
			}
			return result, fmt.Errorf("miss-status commit does not allow column %q", update.Column)
		}
		if update.Value != procDone {
			if err := rollback(); err != nil {
				return result, err
			}
			return result, fmt.Errorf("miss-status commit does not allow value %d for %q", update.Value, update.Column)
		}

		if releaseUpdateIDs[update.ReleaseID] || !isCommittableMissStatusReason(update.Reason) {
			result.SingleColumnUpdatesBlocked++
			result.BlockedStatusReleaseIDs = append(result.BlockedStatusReleaseIDs, update.ReleaseID)

			continue
		}
		if eligibleIDs[update.ReleaseID] {
			if err := rollback(); err != nil {
				return result, err
			}
			return result, fmt.Errorf("duplicate miss-status commit for release %d", update.ReleaseID)
		}
		eligibleIDs[update.ReleaseID] = true

		args := []any{update.Value, update.ReleaseID}
		for _, category := range categories {
			args = append(args, category)
		}
		res, err := tx.ExecContext(ctx, "UPDATE releases SET "+update.Column+" = ? WHERE id = ? AND categories_id IN ("+placeholders(len(categories))+") AND predb_id = 0 AND "+update.Column+" = 0", args...)
		if err != nil {
			if rollbackErr := rollback(); rollbackErr != nil {
				return result, rollbackErr
			}
			return result, err
		}
		rowsAffected, err := res.RowsAffected()
		if err != nil {
			if rollbackErr := rollback(); rollbackErr != nil {
				return result, rollbackErr
			}
			return result, err
		}

		result.SingleColumnUpdatesAttempted++
		result.SingleColumnRowsAffected += rowsAffected
		if rowsAffected == 1 {
			inserted, err := enqueueNativeSearchSideEffect(ctx, tx, job, update)
			if err != nil {
				if rollbackErr := rollback(); rollbackErr != nil {
					return result, rollbackErr
				}
				return result, err
			}

			result.SingleColumnUpdatesCommitted++
			result.WritesCommitted++
			result.CommittedReleaseIDs = append(result.CommittedReleaseIDs, update.ReleaseID)
			if inserted {
				result.SearchSideEffectRowsEnqueued++
				result.SearchUpdatesEnqueued++
			}
		} else {
			result.SingleColumnUpdatesSkipped++
			result.SkippedReleaseIDs = append(result.SkippedReleaseIDs, update.ReleaseID)
		}
	}

	if err := tx.Commit(); err != nil {
		return result, fmt.Errorf("commit miss-status updates: %w", err)
	}
	sort.Slice(result.CommittedReleaseIDs, func(i, j int) bool {
		return result.CommittedReleaseIDs[i] < result.CommittedReleaseIDs[j]
	})
	sort.Slice(result.SkippedReleaseIDs, func(i, j int) bool {
		return result.SkippedReleaseIDs[i] < result.SkippedReleaseIDs[j]
	})

	return result, nil
}

func enqueueNativeSearchSideEffect(ctx context.Context, tx *sql.Tx, job string, update SingleColumnUpdateContract) (bool, error) {
	operationKey := fmt.Sprintf("%s:miss-status:v1:%d:%s:%d:%s", job, update.ReleaseID, update.Column, update.Value, update.Reason)
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
		) VALUES (?, ?, 'release-search-sync', ?, ?, ?, ?, 'pending', 0, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
		ON DUPLICATE KEY UPDATE
			status = 'pending',
			available_at = NULL,
			processed_at = NULL,
			last_error_code = NULL,
			updated_at = UTC_TIMESTAMP(6)
	`, operationKey, job, update.ReleaseID, update.Column, update.Reason, update.Value)
	if err != nil {
		return false, fmt.Errorf("enqueue native search side effect: %w", err)
	}

	rowsAffected, err := res.RowsAffected()
	if err != nil {
		return false, fmt.Errorf("enqueue native search side effect rows affected: %w", err)
	}

	return rowsAffected >= 1, nil
}

func DecodeResolvedWriteContractOracle(reader io.Reader) (ResolvedWriteContractOracle, error) {
	var payload struct {
		SchemaVersion int         `json:"schema_version"`
		Mode          string      `json:"mode"`
		DryRun        bool        `json:"dry_run"`
		Writes        *strictZero `json:"writes"`
		WriteContract struct {
			Writes                 *strictZero             `json:"writes"`
			ResolvedReleaseUpdates []ResolvedReleaseUpdate `json:"resolved_release_updates"`
		} `json:"write_contract"`
	}

	decoder := json.NewDecoder(reader)
	decoder.UseNumber()
	if err := decoder.Decode(&payload); err != nil {
		return ResolvedWriteContractOracle{}, fmt.Errorf("decode resolved write contract oracle: %w", err)
	}
	var extra struct{}
	if err := decoder.Decode(&extra); err != io.EOF {
		return ResolvedWriteContractOracle{}, fmt.Errorf("decode resolved write contract oracle: trailing JSON data")
	}

	if payload.Writes == nil || payload.Writes.Value != 0 {
		return ResolvedWriteContractOracle{}, fmt.Errorf("resolved write contract oracle must have writes=0")
	}
	if payload.WriteContract.Writes == nil || payload.WriteContract.Writes.Value != 0 {
		return ResolvedWriteContractOracle{}, fmt.Errorf("resolved write contract oracle write_contract must have writes=0")
	}

	oracle := ResolvedWriteContractOracle{
		SchemaVersion: payload.SchemaVersion,
		Mode:          payload.Mode,
		DryRun:        payload.DryRun,
		Writes:        payload.Writes.Value,
		WriteContract: ResolvedWriteContract{
			Writes:                 payload.WriteContract.Writes.Value,
			ResolvedReleaseUpdates: payload.WriteContract.ResolvedReleaseUpdates,
		},
	}
	if err := validateResolvedOracle(oracle); err != nil {
		return ResolvedWriteContractOracle{}, err
	}

	return oracle, nil
}

func (z *strictZero) UnmarshalJSON(data []byte) error {
	if string(data) != "0" {
		return fmt.Errorf("must have writes=0")
	}

	z.Value = 0

	return nil
}

func rehearseHashedFixWriteContract(ctx context.Context, db *sql.DB, contract HashedFixWriteContract, oracle *ResolvedWriteContractOracle) (WriteRehearsalResult, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return WriteRehearsalResult{}, err
	}

	result := WriteRehearsalResult{
		BlockedReleaseIDs: make([]int64, 0, len(contract.ReleaseUpdates)),
		RolledBack:        true,
		WritesCommitted:   0,
	}

	rollback := func() error {
		if err := tx.Rollback(); err != nil {
			return fmt.Errorf("rollback write rehearsal: %w", err)
		}
		return nil
	}

	if oracle == nil {
		for _, releaseUpdate := range contract.ReleaseUpdates {
			result.ReleaseUpdatesBlocked++
			result.BlockedReleaseIDs = append(result.BlockedReleaseIDs, releaseUpdate.ReleaseID)
		}
	} else {
		resolvedUpdates, err := validateResolvedUpdates(contract, *oracle)
		if err != nil {
			if rollbackErr := rollback(); rollbackErr != nil {
				return result, rollbackErr
			}
			return result, err
		}
		eventsByRelease, err := requiredEventsByReleaseID(contract.RequiredEvents)
		if err != nil {
			if rollbackErr := rollback(); rollbackErr != nil {
				return result, rollbackErr
			}
			return result, err
		}

		for _, releaseUpdate := range contract.ReleaseUpdates {
			resolved, ok := resolvedUpdates[releaseUpdate.ReleaseID]
			if !ok {
				result.ReleaseUpdatesBlocked++
				result.BlockedReleaseIDs = append(result.BlockedReleaseIDs, releaseUpdate.ReleaseID)

				continue
			}

			event, ok := eventsByRelease[releaseUpdate.ReleaseID]
			if !ok {
				if rollbackErr := rollback(); rollbackErr != nil {
					return result, rollbackErr
				}
				return result, fmt.Errorf("release update %d is missing required event context", releaseUpdate.ReleaseID)
			}

			rowsAffected, err := rehearseResolvedReleaseUpdate(ctx, tx, releaseUpdate, resolved, event)
			if err != nil {
				if rollbackErr := rollback(); rollbackErr != nil {
					return result, rollbackErr
				}
				return result, err
			}

			result.ReleaseUpdatesAttempted++
			result.ReleaseUpdateRowsAffected += rowsAffected
		}
	}

	for _, update := range contract.SingleColumnUpdates {
		if !isRehearsableSingleColumn(update.Column) {
			if err := rollback(); err != nil {
				return result, err
			}
			return result, fmt.Errorf("single-column rehearsal does not allow column %q", update.Column)
		}
		if update.Value != procDone {
			if err := rollback(); err != nil {
				return result, err
			}
			return result, fmt.Errorf("single-column rehearsal does not allow value %d for %q", update.Value, update.Column)
		}

		res, err := tx.ExecContext(ctx, "UPDATE releases SET "+update.Column+" = ? WHERE id = ?", update.Value, update.ReleaseID)
		if err != nil {
			if rollbackErr := rollback(); rollbackErr != nil {
				return result, rollbackErr
			}
			return result, err
		}

		rowsAffected, err := res.RowsAffected()
		if err != nil {
			if rollbackErr := rollback(); rollbackErr != nil {
				return result, rollbackErr
			}
			return result, err
		}

		result.SingleColumnUpdatesAttempted++
		result.SingleColumnRowsAffected += rowsAffected
	}

	if err := rollback(); err != nil {
		return result, err
	}

	return result, nil
}

func WriteRehearsalSummary(result WriteRehearsalResult) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "hashed-fixnames write-rehearsal")
	fmt.Fprintf(&buffer, "single-column-updates-attempted=%d\n", result.SingleColumnUpdatesAttempted)
	fmt.Fprintf(&buffer, "single-column-rows-affected=%d\n", result.SingleColumnRowsAffected)
	fmt.Fprintf(&buffer, "release-updates-attempted=%d\n", result.ReleaseUpdatesAttempted)
	fmt.Fprintf(&buffer, "release-update-rows-affected=%d\n", result.ReleaseUpdateRowsAffected)
	fmt.Fprintf(&buffer, "release-updates-blocked=%d\n", result.ReleaseUpdatesBlocked)
	fmt.Fprintf(&buffer, "blocked-release-ids=%s\n", blockedReleaseIDsSummary(result.BlockedReleaseIDs))
	fmt.Fprintf(&buffer, "rolled-back=%t\n", result.RolledBack)
	fmt.Fprintf(&buffer, "writes-committed=%d\n", result.WritesCommitted)

	return buffer.String()
}

func MissStatusCommitSummary(result MissStatusCommitResult) string {
	return missStatusCommitSummary("hashed-fixnames", result)
}

func RegularFixMissStatusCommitSummary(result MissStatusCommitResult) string {
	return missStatusCommitSummary("fixnames", result)
}

func missStatusCommitSummary(job string, result MissStatusCommitResult) string {
	var buffer bytes.Buffer

	fmt.Fprintf(&buffer, "%s miss-status commit\n", job)
	fmt.Fprintf(&buffer, "lock-acquired=%t\n", result.LockAcquired)
	if result.LockMode != "" {
		fmt.Fprintf(&buffer, "lock-mode=%s\n", result.LockMode)
	}
	fmt.Fprintf(&buffer, "single-column-updates-attempted=%d\n", result.SingleColumnUpdatesAttempted)
	fmt.Fprintf(&buffer, "single-column-updates-committed=%d\n", result.SingleColumnUpdatesCommitted)
	fmt.Fprintf(&buffer, "single-column-updates-skipped=%d\n", result.SingleColumnUpdatesSkipped)
	fmt.Fprintf(&buffer, "single-column-updates-blocked=%d\n", result.SingleColumnUpdatesBlocked)
	fmt.Fprintf(&buffer, "single-column-rows-affected=%d\n", result.SingleColumnRowsAffected)
	fmt.Fprintf(&buffer, "release-updates-blocked=%d\n", result.ReleaseUpdatesBlocked)
	fmt.Fprintf(&buffer, "blocked-release-ids=%s\n", blockedReleaseIDsSummary(result.BlockedReleaseIDs))
	fmt.Fprintf(&buffer, "blocked-status-release-ids=%s\n", blockedReleaseIDsSummary(result.BlockedStatusReleaseIDs))
	fmt.Fprintf(&buffer, "committed-release-ids=%s\n", blockedReleaseIDsSummary(result.CommittedReleaseIDs))
	fmt.Fprintf(&buffer, "skipped-release-ids=%s\n", blockedReleaseIDsSummary(result.SkippedReleaseIDs))
	fmt.Fprintf(&buffer, "search-side-effect-rows-enqueued=%d\n", result.SearchSideEffectRowsEnqueued)
	fmt.Fprintf(&buffer, "search-updates-enqueued=%d\n", result.SearchUpdatesEnqueued)
	fmt.Fprintf(&buffer, "writes-committed=%d\n", result.WritesCommitted)

	return buffer.String()
}

func isCommittableMissStatusColumn(column string) bool {
	return column == "proc_crc32" || column == "proc_hash16k"
}

func isCommittableMissStatusReason(reason string) bool {
	return reason == "crc-miss" || reason == "par-hash-miss"
}

func validateResolvedOracle(oracle ResolvedWriteContractOracle) error {
	if oracle.SchemaVersion != 1 {
		return fmt.Errorf("resolved write contract oracle schema_version=%d, want 1", oracle.SchemaVersion)
	}
	if oracle.Mode != "native-write-contract-resolve" {
		return fmt.Errorf("resolved write contract oracle mode=%q, want native-write-contract-resolve", oracle.Mode)
	}
	if !oracle.DryRun {
		return fmt.Errorf("resolved write contract oracle must have dry_run=true")
	}
	if oracle.Writes != 0 || oracle.WriteContract.Writes != 0 {
		return fmt.Errorf("resolved write contract oracle must have writes=0")
	}

	return nil
}

func validateResolvedUpdates(contract HashedFixWriteContract, oracle ResolvedWriteContractOracle) (map[int64]ResolvedReleaseUpdate, error) {
	if err := validateResolvedOracle(oracle); err != nil {
		return nil, err
	}

	plannedIDs := map[int64]bool{}
	for _, releaseUpdate := range contract.ReleaseUpdates {
		plannedIDs[releaseUpdate.ReleaseID] = true
	}
	eventsByRelease, err := requiredEventsByReleaseID(contract.RequiredEvents)
	if err != nil {
		return nil, err
	}

	resolved := map[int64]ResolvedReleaseUpdate{}
	for _, releaseUpdate := range oracle.WriteContract.ResolvedReleaseUpdates {
		if !plannedIDs[releaseUpdate.ReleaseID] {
			return nil, fmt.Errorf("resolved release update %d was not in native write contract", releaseUpdate.ReleaseID)
		}
		if _, exists := resolved[releaseUpdate.ReleaseID]; exists {
			return nil, fmt.Errorf("duplicate resolved release update for release %d", releaseUpdate.ReleaseID)
		}
		event, ok := eventsByRelease[releaseUpdate.ReleaseID]
		if !ok {
			return nil, fmt.Errorf("resolved release update %d is missing native event context", releaseUpdate.ReleaseID)
		}
		if err := validateResolvedContext(contract, releaseUpdate, event); err != nil {
			return nil, err
		}

		resolved[releaseUpdate.ReleaseID] = releaseUpdate
	}

	return resolved, nil
}

func requiredEventsByReleaseID(events []ReleaseNameFixedEventContract) (map[int64]ReleaseNameFixedEventContract, error) {
	indexed := map[int64]ReleaseNameFixedEventContract{}
	for _, event := range events {
		if event.ReleaseID == 0 {
			return nil, fmt.Errorf("required event has invalid release id 0")
		}
		if _, exists := indexed[event.ReleaseID]; exists {
			return nil, fmt.Errorf("duplicate required event for release %d", event.ReleaseID)
		}

		indexed[event.ReleaseID] = event
	}

	return indexed, nil
}

func validateResolvedContext(contract HashedFixWriteContract, resolved ResolvedReleaseUpdate, event ReleaseNameFixedEventContract) error {
	if resolved.RequiredEvent.ReleaseID != event.ReleaseID {
		return fmt.Errorf("resolved release update %d required_event.release_id = %d, want %d", resolved.ReleaseID, resolved.RequiredEvent.ReleaseID, event.ReleaseID)
	}
	if resolved.RequiredEvent.OldName != event.OldName {
		return fmt.Errorf("resolved release update %d required_event.old_name mismatch", resolved.ReleaseID)
	}
	if resolved.RequiredEvent.NewName != event.NewName {
		return fmt.Errorf("resolved release update %d required_event.new_name mismatch", resolved.ReleaseID)
	}
	if resolved.RequiredEvent.OldCategoryID != event.OldCategoryID {
		return fmt.Errorf("resolved release update %d required_event.old_category_id = %d, want %d", resolved.ReleaseID, resolved.RequiredEvent.OldCategoryID, event.OldCategoryID)
	}
	if resolved.RequiredEvent.GroupID != event.GroupID {
		return fmt.Errorf("resolved release update %d required_event.group_id = %d, want %d", resolved.ReleaseID, resolved.RequiredEvent.GroupID, event.GroupID)
	}
	if resolved.RequiredEvent.PosterPresent != (event.Poster != "") {
		return fmt.Errorf("resolved release update %d required_event.poster_present mismatch", resolved.ReleaseID)
	}

	categoryColumn, ok := resolvedColumn(resolved.Columns, "categories_id")
	if !ok {
		return fmt.Errorf("resolved release update %d missing categories_id column", resolved.ReleaseID)
	}
	categoryID, ok := intValue(categoryColumn.Value)
	if !ok {
		return fmt.Errorf("resolved release update %d categories_id must be an integer", resolved.ReleaseID)
	}
	if resolved.RequiredEvent.NewCategoryID != categoryID {
		return fmt.Errorf("resolved release update %d required_event.new_category_id = %d, want %d", resolved.ReleaseID, resolved.RequiredEvent.NewCategoryID, categoryID)
	}
	if resolved.CategoryResolution.CategoriesID != categoryID {
		return fmt.Errorf("resolved release update %d category_resolution.categories_id = %d, want %d", resolved.ReleaseID, resolved.CategoryResolution.CategoriesID, categoryID)
	}
	if resolved.CategoryResolution.GroupID != event.GroupID {
		return fmt.Errorf("resolved release update %d category_resolution.group_id = %d, want %d", resolved.ReleaseID, resolved.CategoryResolution.GroupID, event.GroupID)
	}
	if resolved.CategoryResolution.NewName != event.NewName {
		return fmt.Errorf("resolved release update %d category_resolution.new_name mismatch", resolved.ReleaseID)
	}
	if resolved.CategoryResolution.PosterPresent != (event.Poster != "") {
		return fmt.Errorf("resolved release update %d category_resolution.poster_present mismatch", resolved.ReleaseID)
	}
	if resolved.CategoryResolution.ValueSource != categoryValueSource(contract, resolved.ReleaseID) {
		return fmt.Errorf("resolved release update %d category_resolution.value_source mismatch", resolved.ReleaseID)
	}

	return nil
}

func rehearseResolvedReleaseUpdate(ctx context.Context, tx *sql.Tx, planned ReleaseUpdateContract, resolved ResolvedReleaseUpdate, event ReleaseNameFixedEventContract) (int64, error) {
	if planned.ReleaseID != resolved.ReleaseID {
		return 0, fmt.Errorf("resolved release update id %d does not match planned release id %d", resolved.ReleaseID, planned.ReleaseID)
	}

	plannedColumns := map[string]PlannedColumn{}
	for _, column := range planned.Columns {
		if !rehearseReleaseUpdateColumns[column.Column] {
			return 0, fmt.Errorf("release-update rehearsal does not allow planned column %q", column.Column)
		}
		plannedColumns[column.Column] = column
	}

	seen := map[string]bool{}
	assignments := make([]string, 0, len(resolved.Columns))
	args := make([]any, 0, len(resolved.Columns)+1)
	for _, column := range resolved.Columns {
		if !rehearseReleaseUpdateColumns[column.Column] {
			return 0, fmt.Errorf("release-update rehearsal does not allow resolved column %q", column.Column)
		}
		plannedColumn, ok := plannedColumns[column.Column]
		if !ok {
			return 0, fmt.Errorf("resolved release update %d included unplanned column %q", resolved.ReleaseID, column.Column)
		}
		if seen[column.Column] {
			return 0, fmt.Errorf("resolved release update %d duplicated column %q", resolved.ReleaseID, column.Column)
		}
		seen[column.Column] = true

		if plannedColumn.ValueSource == "" && !valuesEquivalent(plannedColumn.Value, column.Value) {
			return 0, fmt.Errorf("resolved release update %d changed planned value for %q", resolved.ReleaseID, column.Column)
		}
		if plannedColumn.ValueSource != "" && column.Value == nil {
			return 0, fmt.Errorf("resolved release update %d did not resolve %q", resolved.ReleaseID, column.Column)
		}

		value, err := sqlValue(column.Value)
		if err != nil {
			return 0, fmt.Errorf("resolved release update %d column %q: %w", resolved.ReleaseID, column.Column, err)
		}
		assignments = append(assignments, "`"+column.Column+"` = ?")
		args = append(args, value)
	}

	for _, plannedColumn := range planned.Columns {
		if !seen[plannedColumn.Column] {
			return 0, fmt.Errorf("resolved release update %d missing planned column %q", resolved.ReleaseID, plannedColumn.Column)
		}
	}

	args = append(args, planned.ReleaseID, event.OldName, event.OldCategoryID)
	result, err := tx.ExecContext(ctx, "UPDATE releases SET "+strings.Join(assignments, ", ")+" WHERE id = ? AND searchname = ? AND categories_id = ?", args...)
	if err != nil {
		return 0, err
	}

	rowsAffected, err := result.RowsAffected()
	if err != nil {
		return 0, err
	}

	return rowsAffected, nil
}

func isRehearsableSingleColumn(column string) bool {
	switch column {
	case "proc_crc32", "proc_hash16k":
		return true
	default:
		return false
	}
}

func blockedReleaseIDsSummary(ids []int64) string {
	values := make([]string, 0, len(ids))
	for _, id := range ids {
		values = append(values, fmt.Sprintf("%d", id))
	}

	return strings.Join(values, ",")
}

func valuesEquivalent(expected any, actual any) bool {
	expectedValue, expectedOK := comparableValue(expected)
	actualValue, actualOK := comparableValue(actual)

	return expectedOK && actualOK && expectedValue == actualValue
}

func comparableValue(value any) (string, bool) {
	if value == nil {
		return "<nil>", true
	}

	switch typed := value.(type) {
	case string:
		return "s:" + typed, true
	case int:
		return fmt.Sprintf("i:%d", typed), true
	case int8:
		return fmt.Sprintf("i:%d", typed), true
	case int16:
		return fmt.Sprintf("i:%d", typed), true
	case int32:
		return fmt.Sprintf("i:%d", typed), true
	case int64:
		return fmt.Sprintf("i:%d", typed), true
	case uint:
		return fmt.Sprintf("i:%d", typed), true
	case uint8:
		return fmt.Sprintf("i:%d", typed), true
	case uint16:
		return fmt.Sprintf("i:%d", typed), true
	case uint32:
		return fmt.Sprintf("i:%d", typed), true
	case uint64:
		if typed > uint64(^uint(0)>>1) {
			return "", false
		}

		return fmt.Sprintf("i:%d", typed), true
	case json.Number:
		value, err := typed.Int64()
		if err != nil {
			return "", false
		}

		return fmt.Sprintf("i:%d", value), true
	default:
		return "", false
	}
}

func sqlValue(value any) (any, error) {
	if value == nil {
		return nil, nil
	}

	switch typed := value.(type) {
	case string:
		return typed, nil
	case int:
		return int64(typed), nil
	case int8:
		return int64(typed), nil
	case int16:
		return int64(typed), nil
	case int32:
		return int64(typed), nil
	case int64:
		return typed, nil
	case uint:
		return int64(typed), nil
	case uint8:
		return int64(typed), nil
	case uint16:
		return int64(typed), nil
	case uint32:
		return int64(typed), nil
	case uint64:
		if typed > uint64(^uint(0)>>1) {
			return nil, fmt.Errorf("unsigned integer is too large")
		}

		return int64(typed), nil
	case json.Number:
		value, err := strconv.ParseInt(typed.String(), 10, 64)
		if err != nil {
			return nil, fmt.Errorf("number must be an integer")
		}

		return value, nil
	default:
		return nil, fmt.Errorf("unsupported value type %T", value)
	}
}

func resolvedColumn(columns []PlannedColumn, name string) (PlannedColumn, bool) {
	for _, column := range columns {
		if column.Column == name {
			return column, true
		}
	}

	return PlannedColumn{}, false
}

func intValue(value any) (int, bool) {
	switch typed := value.(type) {
	case int:
		return typed, true
	case int8:
		return int(typed), true
	case int16:
		return int(typed), true
	case int32:
		return int(typed), true
	case int64:
		return int(typed), int64(int(typed)) == typed
	case uint:
		return int(typed), uint(int(typed)) == typed
	case uint8:
		return int(typed), true
	case uint16:
		return int(typed), true
	case uint32:
		return int(typed), uint32(int(typed)) == typed
	case uint64:
		return int(typed), uint64(int(typed)) == typed
	case json.Number:
		parsed, err := strconv.ParseInt(typed.String(), 10, 64)
		if err != nil {
			return 0, false
		}

		return int(parsed), int64(int(parsed)) == parsed
	default:
		return 0, false
	}
}

func categoryValueSource(contract HashedFixWriteContract, releaseID int64) string {
	for _, releaseUpdate := range contract.ReleaseUpdates {
		if releaseUpdate.ReleaseID != releaseID {
			continue
		}
		for _, column := range releaseUpdate.Columns {
			if column.Column == "categories_id" {
				return column.ValueSource
			}
		}
	}

	return ""
}
