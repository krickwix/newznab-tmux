package removecrap

import (
	"compress/gzip"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"html"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestBuildDryRunPlanCountsSupportedRemoveCrapTypesWithoutChangingMariaDB(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "gibberish", Time: "4", DeleteRequested: true},
		{Type: "executable", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 2 {
		t.Fatalf("Commands = %d, want 2", plan.Commands)
	}
	if plan.DestructiveCommands != 2 {
		t.Fatalf("DestructiveCommands = %d, want 2", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 2 {
		t.Fatalf("CandidateReleases = %d, want 2", plan.CandidateReleases)
	}
	if plan.CandidateRows != 3 {
		t.Fatalf("CandidateRows = %d, want 3", plan.CandidateRows)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}

	wantResults := []TypeResult{
		{
			Type:              "gibberish",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 100, GUID: "guid-gibberish", SearchName: "ABCDEFGHIJKLMNOP"}},
		},
		{
			Type:              "executable",
			CandidateReleases: 1,
			CandidateRows:     2,
			Candidates: []Candidate{
				{ID: 200, GUID: "guid-executable", SearchName: "Movie.Release.2026"},
				{ID: 200, GUID: "guid-executable", SearchName: "Movie.Release.2026"},
			},
		},
	}
	if !reflect.DeepEqual(plan.Results, wantResults) {
		t.Fatalf("Results = %#v, want %#v", plan.Results, wantResults)
	}

	summary := DryRunSummary(plan)
	for _, want := range []string{
		"removecrap mysql dry-run",
		"commands=2",
		"destructive-commands=2",
		"candidate-releases=2",
		"candidate-rows=3",
		"gibberish-candidates=1",
		"gibberish-rows=1",
		"executable-candidates=1",
		"executable-rows=2",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("summary = %q, missing %q", summary, want)
		}
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRehearseRemoveCrapWritesRollsBackCandidateDeletes(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "gibberish", Time: "4", DeleteRequested: true},
		{Type: "executable", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}
	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	rehearsal, err := RehearseRemoveCrapWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("RehearseRemoveCrapWrites: %v", err)
	}

	if rehearsal.CandidateReleases != 2 {
		t.Fatalf("CandidateReleases = %d, want 2", rehearsal.CandidateReleases)
	}
	if rehearsal.CandidateRows != 3 {
		t.Fatalf("CandidateRows = %d, want 3", rehearsal.CandidateRows)
	}
	if rehearsal.DeleteCommands != 2 {
		t.Fatalf("DeleteCommands = %d, want 2", rehearsal.DeleteCommands)
	}
	if rehearsal.ReleaseFileRowsAffected != 2 {
		t.Fatalf("ReleaseFileRowsAffected = %d, want 2", rehearsal.ReleaseFileRowsAffected)
	}
	if rehearsal.CollectionRowsAffected != 2 {
		t.Fatalf("CollectionRowsAffected = %d, want 2", rehearsal.CollectionRowsAffected)
	}
	if rehearsal.ReleaseRowsAffected != 2 {
		t.Fatalf("ReleaseRowsAffected = %d, want 2", rehearsal.ReleaseRowsAffected)
	}
	if !rehearsal.RolledBack {
		t.Fatal("RolledBack = false, want true")
	}
	if rehearsal.WritesCommitted != 0 {
		t.Fatalf("WritesCommitted = %d, want 0", rehearsal.WritesCommitted)
	}
	if len(rehearsal.DeletedReleaseIDs) != 0 {
		t.Fatalf("DeletedReleaseIDs = %#v, want none for rollback rehearsal", rehearsal.DeletedReleaseIDs)
	}
	if len(rehearsal.DeletedCollectionIDs) != 0 {
		t.Fatalf("DeletedCollectionIDs = %#v, want none for rollback rehearsal", rehearsal.DeletedCollectionIDs)
	}
	encoded, err := json.Marshal(rehearsal)
	if err != nil {
		t.Fatalf("marshal rehearsal: %v", err)
	}
	var marshaled map[string]any
	if err := json.Unmarshal(encoded, &marshaled); err != nil {
		t.Fatalf("unmarshal rehearsal json: %v", err)
	}
	if ids, ok := marshaled["deleted_release_ids"].([]any); !ok || len(ids) != 0 {
		t.Fatalf("deleted_release_ids json = %#v, want empty array", marshaled["deleted_release_ids"])
	}
	if ids, ok := marshaled["deleted_collection_ids"].([]any); !ok || len(ids) != 0 {
		t.Fatalf("deleted_collection_ids json = %#v, want empty array", marshaled["deleted_collection_ids"])
	}
	if _, ok := marshaled["release_file_cleanup_rows_enqueued"].(float64); !ok {
		t.Fatalf("release_file_cleanup_rows_enqueued json = %#v, want number", marshaled["release_file_cleanup_rows_enqueued"])
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitRemoveCrapWritesCommitsCandidateDeletes(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "gibberish", Time: "4", DeleteRequested: true},
		{Type: "executable", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}
	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	commit, err := CommitRemoveCrapWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("CommitRemoveCrapWrites: %v", err)
	}

	if commit.CandidateReleases != 2 {
		t.Fatalf("CandidateReleases = %d, want 2", commit.CandidateReleases)
	}
	if commit.CandidateRows != 3 {
		t.Fatalf("CandidateRows = %d, want 3", commit.CandidateRows)
	}
	if commit.CollectionRowsAffected != 2 || commit.ReleaseFileRowsAffected != 2 || commit.ReleaseRowsAffected != 2 {
		t.Fatalf("removecrap write commit rows = %#v", commit)
	}
	if !reflect.DeepEqual(commit.DeletedReleaseIDs, []int64{100, 200}) {
		t.Fatalf("DeletedReleaseIDs = %#v, want [100 200]", commit.DeletedReleaseIDs)
	}
	if !reflect.DeepEqual(commit.DeletedCollectionIDs, []int64{1, 2}) {
		t.Fatalf("DeletedCollectionIDs = %#v, want [1 2]", commit.DeletedCollectionIDs)
	}
	if commit.FileCleanupRowsEnqueued != commit.ReleaseRowsAffected {
		t.Fatalf("FileCleanupRowsEnqueued = %d, want one per deleted release row %d", commit.FileCleanupRowsEnqueued, commit.ReleaseRowsAffected)
	}
	if commit.RolledBack {
		t.Fatal("RolledBack = true, want committed writes")
	}
	if commit.WritesCommitted != 6 {
		t.Fatalf("WritesCommitted = %d, want 6", commit.WritesCommitted)
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanCountsAdditionalSQLOnlyRemoveCrapTypes(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "hashed", Time: "full", DeleteRequested: true},
		{Type: "short", Time: "4", DeleteRequested: true},
		{Type: "installbin", Time: "4", DeleteRequested: true},
		{Type: "passwordurl", Time: "4", DeleteRequested: true},
		{Type: "nzb", Time: "4", DeleteRequested: true},
		{Type: "scr", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 6 {
		t.Fatalf("Commands = %d, want 6", plan.Commands)
	}
	if plan.DestructiveCommands != 6 {
		t.Fatalf("DestructiveCommands = %d, want 6", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 6 {
		t.Fatalf("CandidateReleases = %d, want 6", plan.CandidateReleases)
	}
	if plan.CandidateRows != 6 {
		t.Fatalf("CandidateRows = %d, want 6", plan.CandidateRows)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}

	wantResults := []TypeResult{
		{
			Type:              "hashed",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 300, GUID: "guid-hashed", SearchName: "ABCDEFGHIJKLMNOPQRSTUVWXY"}},
		},
		{
			Type:              "short",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 310, GUID: "guid-short", SearchName: "AB12"}},
		},
		{
			Type:              "installbin",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 320, GUID: "guid-installbin", SearchName: "Install.Bin.Release"}},
		},
		{
			Type:              "passwordurl",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 321, GUID: "guid-passwordurl", SearchName: "URL.File.Release"}},
		},
		{
			Type:              "nzb",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 322, GUID: "guid-nzb", SearchName: "Single.Nzb.Release"}},
		},
		{
			Type:              "scr",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 323, GUID: "guid-scr", SearchName: "Screen.Saver.Release"}},
		},
	}
	if !reflect.DeepEqual(plan.Results, wantResults) {
		t.Fatalf("Results = %#v, want %#v", plan.Results, wantResults)
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanCountsPasswordedSampleAndSizeRemoveCrapTypes(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "passworded", Time: "4", DeleteRequested: true},
		{Type: "sample", Time: "4", DeleteRequested: true},
		{Type: "size", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 3 {
		t.Fatalf("Commands = %d, want 3", plan.Commands)
	}
	if plan.DestructiveCommands != 3 {
		t.Fatalf("DestructiveCommands = %d, want 3", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 4 {
		t.Fatalf("CandidateReleases = %d, want 4", plan.CandidateReleases)
	}
	if plan.CandidateRows != 4 {
		t.Fatalf("CandidateRows = %d, want 4", plan.CandidateRows)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}

	wantResults := []TypeResult{
		{
			Type:              "passworded",
			CandidateReleases: 2,
			CandidateRows:     2,
			Candidates: []Candidate{
				{ID: 330, GUID: "guid-passwordstatus", SearchName: "Password.Status.Release"},
				{ID: 331, GUID: "guid-password-file", SearchName: "Password.File.Release"},
			},
		},
		{
			Type:              "sample",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 340, GUID: "guid-sample", SearchName: "Movie.Sample.Release"}},
		},
		{
			Type:              "size",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 350, GUID: "guid-size", SearchName: "Tiny.Release"}},
		},
	}
	if !reflect.DeepEqual(plan.Results, wantResults) {
		t.Fatalf("Results = %#v, want %#v", plan.Results, wantResults)
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanCountsCodecRemoveCrapType(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "codec", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 1 {
		t.Fatalf("Commands = %d, want 1", plan.Commands)
	}
	if plan.DestructiveCommands != 1 {
		t.Fatalf("DestructiveCommands = %d, want 1", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 1 {
		t.Fatalf("CandidateReleases = %d, want 1", plan.CandidateReleases)
	}
	if plan.CandidateRows != 1 {
		t.Fatalf("CandidateRows = %d, want 1", plan.CandidateRows)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}

	wantResults := []TypeResult{
		{
			Type:              "codec",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 360, GUID: "guid-codec", SearchName: "Movie.Codec.Release"}},
		},
	}
	if !reflect.DeepEqual(plan.Results, wantResults) {
		t.Fatalf("Results = %#v, want %#v", plan.Results, wantResults)
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanCountsWmvAllRemoveCrapType(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "wmv_all", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 1 {
		t.Fatalf("Commands = %d, want 1", plan.Commands)
	}
	if plan.DestructiveCommands != 1 {
		t.Fatalf("DestructiveCommands = %d, want 1", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 1 {
		t.Fatalf("CandidateReleases = %d, want 1", plan.CandidateReleases)
	}
	if plan.CandidateRows != 1 {
		t.Fatalf("CandidateRows = %d, want 1", plan.CandidateRows)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}

	wantResults := []TypeResult{
		{
			Type:              "wmv_all",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 370, GUID: "guid-wmv-all", SearchName: "TV.WMV.Release"}},
		},
	}
	if !reflect.DeepEqual(plan.Results, wantResults) {
		t.Fatalf("Results = %#v, want %#v", plan.Results, wantResults)
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanCountsBlacklistFilesRemoveCrapType(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "blfiles", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 1 {
		t.Fatalf("Commands = %d, want 1", plan.Commands)
	}
	if plan.DestructiveCommands != 1 {
		t.Fatalf("DestructiveCommands = %d, want 1", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 1 {
		t.Fatalf("CandidateReleases = %d, want 1", plan.CandidateReleases)
	}
	if plan.CandidateRows != 1 {
		t.Fatalf("CandidateRows = %d, want 1", plan.CandidateRows)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}

	wantResults := []TypeResult{
		{
			Type:              "blfiles",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 380, GUID: "guid-blfiles", SearchName: "Blacklist.Files.Release"}},
		},
	}
	if !reflect.DeepEqual(plan.Results, wantResults) {
		t.Fatalf("Results = %#v, want %#v", plan.Results, wantResults)
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanCountsBoundedBlacklistRemoveCrapType(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "blacklist", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 1 {
		t.Fatalf("Commands = %d, want 1", plan.Commands)
	}
	if plan.DestructiveCommands != 1 {
		t.Fatalf("DestructiveCommands = %d, want 1", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 2 {
		t.Fatalf("CandidateReleases = %d, want 2", plan.CandidateReleases)
	}
	if plan.CandidateRows != 2 {
		t.Fatalf("CandidateRows = %d, want 2", plan.CandidateRows)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}

	wantResults := []TypeResult{
		{
			Type:              "blacklist",
			CandidateReleases: 2,
			CandidateRows:     2,
			Candidates: []Candidate{
				{ID: 390, GUID: "guid-blacklist-subject", SearchName: "Bad.Subject.Release"},
				{ID: 392, GUID: "guid-blacklist-poster", SearchName: "Poster.Blacklist.Release"},
			},
		},
	}
	if !reflect.DeepEqual(plan.Results, wantResults) {
		t.Fatalf("Results = %#v, want %#v", plan.Results, wantResults)
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanFiltersBoundedBlacklistByID(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "blacklist", Time: "4", DeleteRequested: true, BlacklistID: "6"},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 1 {
		t.Fatalf("Commands = %d, want 1", plan.Commands)
	}
	if plan.DestructiveCommands != 1 {
		t.Fatalf("DestructiveCommands = %d, want 1", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 1 {
		t.Fatalf("CandidateReleases = %d, want 1", plan.CandidateReleases)
	}
	if plan.CandidateRows != 1 {
		t.Fatalf("CandidateRows = %d, want 1", plan.CandidateRows)
	}

	wantResults := []TypeResult{
		{
			Type:              "blacklist",
			CandidateReleases: 1,
			CandidateRows:     1,
			Candidates:        []Candidate{{ID: 392, GUID: "guid-blacklist-poster", SearchName: "Poster.Blacklist.Release"}},
		},
	}
	if !reflect.DeepEqual(plan.Results, wantResults) {
		t.Fatalf("Results = %#v, want %#v", plan.Results, wantResults)
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanExpandsEmptyTypeAsPHPAllRemoval(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	nzbRoot := t.TempDir()
	t.Setenv("PATH_TO_NZBS", nzbRoot)
	writeGzippedNZB(t, nzbRoot, "guid-par2-hashed-nzb", []string{
		`[1/2] "repair.par2" yEnc`,
		`[2/2] "repair.vol000+001.par2" yEnc`,
	})
	writeGzippedNZB(t, nzbRoot, "guid-par2-hashed-content", []string{
		`[1/2] "repair.par2" yEnc`,
		`[2/2] "movie.mkv" yEnc`,
	})

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "", Time: "2", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 1 {
		t.Fatalf("Commands = %d, want 1", plan.Commands)
	}
	if plan.DestructiveCommands != 1 {
		t.Fatalf("DestructiveCommands = %d, want 1", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 17 {
		t.Fatalf("CandidateReleases = %d, want 17", plan.CandidateReleases)
	}
	if plan.CandidateRows != 18 {
		t.Fatalf("CandidateRows = %d, want 18", plan.CandidateRows)
	}

	wantResults := []struct {
		Type              string
		CandidateReleases int
		CandidateRows     int
	}{
		{Type: "blacklist", CandidateReleases: 2, CandidateRows: 2},
		{Type: "blfiles", CandidateReleases: 1, CandidateRows: 1},
		{Type: "executable", CandidateReleases: 1, CandidateRows: 2},
		{Type: "gibberish", CandidateReleases: 1, CandidateRows: 1},
		{Type: "hashed", CandidateReleases: 0, CandidateRows: 0},
		{Type: "installbin", CandidateReleases: 1, CandidateRows: 1},
		{Type: "passworded", CandidateReleases: 2, CandidateRows: 2},
		{Type: "sample", CandidateReleases: 1, CandidateRows: 1},
		{Type: "scr", CandidateReleases: 1, CandidateRows: 1},
		{Type: "short", CandidateReleases: 1, CandidateRows: 1},
		{Type: "size", CandidateReleases: 1, CandidateRows: 1},
		{Type: "nzb", CandidateReleases: 1, CandidateRows: 1},
		{Type: "codec", CandidateReleases: 1, CandidateRows: 1},
		{Type: "par2only", CandidateReleases: 3, CandidateRows: 3},
	}
	if len(plan.Results) != len(wantResults) {
		t.Fatalf("Results length = %d, want %d: %#v", len(plan.Results), len(wantResults), plan.Results)
	}
	for index, want := range wantResults {
		got := plan.Results[index]
		if got.Type != want.Type || got.CandidateReleases != want.CandidateReleases || got.CandidateRows != want.CandidateRows {
			t.Fatalf("Result[%d] = %#v, want %#v", index, got, want)
		}
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanCountsPar2OnlySQLStrategies(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "par2only", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 1 {
		t.Fatalf("Commands = %d, want 1", plan.Commands)
	}
	if plan.DestructiveCommands != 1 {
		t.Fatalf("DestructiveCommands = %d, want 1", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 2 {
		t.Fatalf("CandidateReleases = %d, want 2", plan.CandidateReleases)
	}
	if plan.CandidateRows != 2 {
		t.Fatalf("CandidateRows = %d, want 2", plan.CandidateRows)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}

	wantResults := []TypeResult{
		{
			Type:              "par2only",
			CandidateReleases: 2,
			CandidateRows:     2,
			Candidates: []Candidate{
				{ID: 400, GUID: "guid-par2-searchname", SearchName: "Only.Par2.par2_"},
				{ID: 402, GUID: "guid-par2-files-only", SearchName: "All.Files.Are.Repair"},
			},
		},
	}
	if !reflect.DeepEqual(plan.Results, wantResults) {
		t.Fatalf("Results = %#v, want %#v", plan.Results, wantResults)
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanCountsPar2OnlyHashedNZBStrategy(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetRemoveCrapTables(t, ctx, db)
	seedRemoveCrapRows(t, ctx, db)

	nzbRoot := t.TempDir()
	t.Setenv("PATH_TO_NZBS", nzbRoot)
	writeGzippedNZB(t, nzbRoot, "guid-par2-hashed-nzb", []string{
		`[1/2] "repair.par2" yEnc`,
		`[2/2] "repair.vol000+001.par2" yEnc`,
	})
	writeGzippedNZB(t, nzbRoot, "guid-par2-hashed-content", []string{
		`[1/2] "repair.par2" yEnc`,
		`[2/2] "movie.mkv" yEnc`,
	})

	fingerprintBefore := removeCrapFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "par2only", Time: "4", DeleteRequested: true},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 1 {
		t.Fatalf("Commands = %d, want 1", plan.Commands)
	}
	if plan.DestructiveCommands != 1 {
		t.Fatalf("DestructiveCommands = %d, want 1", plan.DestructiveCommands)
	}
	if plan.CandidateReleases != 3 {
		t.Fatalf("CandidateReleases = %d, want 3", plan.CandidateReleases)
	}
	if plan.CandidateRows != 3 {
		t.Fatalf("CandidateRows = %d, want 3", plan.CandidateRows)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}

	wantResults := []TypeResult{
		{
			Type:              "par2only",
			CandidateReleases: 3,
			CandidateRows:     3,
			Candidates: []Candidate{
				{ID: 400, GUID: "guid-par2-searchname", SearchName: "Only.Par2.par2_"},
				{ID: 402, GUID: "guid-par2-files-only", SearchName: "All.Files.Are.Repair"},
				{ID: 405, GUID: "guid-par2-hashed-nzb", SearchName: "Hashed.Par2.NZB"},
			},
		},
	}
	if !reflect.DeepEqual(plan.Results, wantResults) {
		t.Fatalf("Results = %#v, want %#v", plan.Results, wantResults)
	}

	if fingerprintAfter := removeCrapFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildDryRunPlanRejectsUnsupportedTypes(t *testing.T) {
	t.Parallel()

	_, err := BuildDryRunPlan(context.Background(), nil, []Request{{Type: "all", Time: "4"}})
	if err == nil {
		t.Fatal("BuildDryRunPlan succeeded for unsupported type")
	}
	if !strings.Contains(err.Error(), `unsupported removecrap type "all"`) {
		t.Fatalf("error = %v, want unsupported type", err)
	}
}

func TestPlanJSONDoesNotExposeReleaseDetails(t *testing.T) {
	t.Parallel()

	plan := Plan{
		Commands:            1,
		DestructiveCommands: 1,
		CandidateReleases:   1,
		Results: []TypeResult{{
			Type:              "gibberish",
			CandidateReleases: 1,
			Candidates: []Candidate{{
				ID:         100,
				GUID:       "release-guid",
				SearchName: "ABCDEFGHIJKLMNOP",
			}},
		}},
	}

	encoded, err := json.Marshal(plan)
	if err != nil {
		t.Fatalf("marshal Plan: %v", err)
	}

	for _, forbidden := range []string{"candidates", "release-guid", "ABCDEFGHIJKLMNOP"} {
		if strings.Contains(string(encoded), forbidden) {
			t.Fatalf("Plan JSON exposed %q: %s", forbidden, encoded)
		}
	}
}

func resetRemoveCrapTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS binaryblacklist",
		"DROP TABLE IF EXISTS native_worker_side_effects",
		"DROP TABLE IF EXISTS collections",
		"DROP TABLE IF EXISTS release_files",
		"DROP TABLE IF EXISTS releases",
		"DROP TABLE IF EXISTS settings",
		"DROP TABLE IF EXISTS usenet_groups",
		`CREATE TABLE settings (
			name VARCHAR(255) PRIMARY KEY,
			value TEXT NULL
		)`,
		`CREATE TABLE usenet_groups (
			id INT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE binaryblacklist (
			id INT PRIMARY KEY,
			groupname VARCHAR(255) NOT NULL DEFAULT '',
			regex VARCHAR(255) NOT NULL DEFAULT '',
			msgcol INT NOT NULL DEFAULT 1,
			optype INT NOT NULL DEFAULT 1,
			status INT NOT NULL DEFAULT 1
		)`,
		`CREATE TABLE native_worker_side_effects (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			operation_key VARCHAR(191) NOT NULL UNIQUE,
			job VARCHAR(64) NOT NULL,
			effect VARCHAR(64) NOT NULL,
			release_id BIGINT UNSIGNED NOT NULL,
			status_column VARCHAR(32) NOT NULL,
			status_reason VARCHAR(64) NOT NULL,
			status_value TINYINT UNSIGNED NOT NULL,
			payload_text VARCHAR(255) NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			available_at TIMESTAMP NULL,
			processed_at TIMESTAMP NULL,
			last_error_code VARCHAR(64) NULL,
			created_at TIMESTAMP NULL,
			updated_at TIMESTAMP NULL,
			INDEX ix_native_worker_side_effects_status_available (status, available_at, id),
			INDEX ix_native_worker_side_effects_release_status (release_id, status),
			INDEX ix_native_worker_side_effects_job_effect_status (job, effect, status)
		)`,
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			guid VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			fromname VARCHAR(255) NOT NULL DEFAULT '',
			groups_id INT NOT NULL DEFAULT 0,
			categories_id INT NOT NULL DEFAULT 0,
			passwordstatus INT NOT NULL DEFAULT 0,
			nfostatus TINYINT NOT NULL DEFAULT 0,
			haspreview INT NOT NULL DEFAULT 0,
			jpgstatus INT NOT NULL DEFAULT 0,
			predb_id INT NOT NULL DEFAULT 0,
			videostatus INT NOT NULL DEFAULT 0,
			imdbid VARCHAR(32) NULL,
			isrenamed TINYINT NOT NULL DEFAULT 0,
			iscategorized TINYINT NOT NULL DEFAULT 0,
			rarinnerfilecount INT NOT NULL DEFAULT 0,
			totalpart INT NOT NULL DEFAULT 0,
			size BIGINT NOT NULL DEFAULT 0,
			adddate DATETIME NOT NULL
		)`,
		`CREATE TABLE release_files (
			id INT AUTO_INCREMENT PRIMARY KEY,
			releases_id INT NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			passworded INT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE collections (
			id INT AUTO_INCREMENT PRIMARY KEY,
			groups_id INT NOT NULL DEFAULT 0,
			releases_id INT NULL
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func seedRemoveCrapRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES
			('minsizetoformrelease', '2097152')`,
		`INSERT INTO usenet_groups (id, name) VALUES
			(88, 'alt.binaries.movies'),
			(89, 'alt.binaries.tv')`,
		`INSERT INTO binaryblacklist (id, groupname, regex, msgcol, optype, status) VALUES
			(1, 'alt[.]binaries[.]movies', 'badcodec[.]dat', 1, 1, 1),
			(2, 'alt.binaries.*', 'disabledbad[.]dat', 1, 1, 0),
			(3, 'alt.binaries.*', 'whitelistbad[.]dat', 1, 2, 1),
			(4, 'alt.binaries.*', 'frombad[.]dat', 2, 1, 1),
			(5, 'alt[.]binaries[.]movies', 'Bad[.]Subject', 1, 1, 1),
			(6, 'alt.binaries.*', 'BadPoster', 2, 1, 1),
			(7, 'alt.binaries.*', 'Disabled[.]Subject', 1, 1, 0),
			(8, 'alt.binaries.*', 'Whitelist[.]Subject', 1, 2, 1)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, iscategorized, rarinnerfilecount, adddate) VALUES
			(100, 'guid-gibberish', 'ABCDEFGHIJKLMNOP', 'ABCDEFGHIJKLMNOP', 2000, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(101, 'guid-old-gibberish', 'QRSTUVWXYZABCDE', 'QRSTUVWXYZABCDE', 2000, 0, 0, 1, 0, NOW() - INTERVAL 12 HOUR),
			(102, 'guid-hashed-category', 'ABCDEFGHIJKLMNOPQ', 'ABCDEFGHIJKLMNOPQ', 20, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(103, 'guid-not-categorized', 'ABCDEFGHIJKLMNOPR', 'ABCDEFGHIJKLMNOPR', 2000, 0, 0, 0, 0, NOW() - INTERVAL 1 HOUR),
			(200, 'guid-executable', 'Movie.Release.2026', 'Movie.Release.2026', 2040, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(201, 'guid-pc-game-exe', 'Game.Release.2026', 'Game.Release.2026', 4050, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(202, 'guid-old-exe', 'Old.Release.2026', 'Old.Release.2026', 2040, 0, 0, 1, 0, NOW() - INTERVAL 12 HOUR)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, iscategorized, rarinnerfilecount, totalpart, size, adddate) VALUES
			(300, 'guid-hashed', 'ABCDEFGHIJKLMNOPQRSTUVWXY', 'ABCDEFGHIJKLMNOPQRSTUVWXY', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(301, 'guid-hashed-misc', 'ABCDEFGHIJKLMNOPQRSTUVWXY1', 'ABCDEFGHIJKLMNOPQRSTUVWXY1', 10, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(310, 'guid-short', 'AB12', 'AB12', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(311, 'guid-short-misc', 'AB13', 'AB13', 10, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(320, 'guid-installbin', 'Install.Bin.Release', 'Install.Bin.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(321, 'guid-passwordurl', 'URL.File.Release', 'URL.File.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(322, 'guid-nzb', 'Single.Nzb.Release', 'Single.Nzb.Release', 2000, 0, 0, 1, 0, 1, 3000000, NOW() - INTERVAL 1 HOUR),
			(323, 'guid-scr', 'Screen.Saver.Release', 'Screen.Saver.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(330, 'guid-passwordstatus', 'Password.Status.Release', 'Password.Status.Release', 2000, 1, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(331, 'guid-password-file', 'Password.File.Release', 'Password.File.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(332, 'guid-password-false-positive', 'No password Release', 'No password Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(340, 'guid-sample', 'Movie.sample.avi', 'Movie.Sample.Release', 2040, 0, 0, 1, 0, 2, 20000000, NOW() - INTERVAL 1 HOUR),
			(341, 'guid-sample-large', 'Movie.sample.large.avi', 'Movie.Sample.Large.Release', 2040, 0, 0, 1, 0, 2, 50000000, NOW() - INTERVAL 1 HOUR),
			(350, 'guid-size', 'Tiny.Release', 'Tiny.Release', 2000, 0, 0, 1, 0, 1, 1000000, NOW() - INTERVAL 1 HOUR),
			(351, 'guid-size-music', 'Tiny.Music.Release', 'Tiny.Music.Release', 3010, 0, 0, 1, 0, 1, 1000000, NOW() - INTERVAL 1 HOUR)`,
		`INSERT INTO releases (
			id, guid, name, searchname, categories_id, passwordstatus, nfostatus, haspreview,
			jpgstatus, predb_id, videostatus, imdbid, iscategorized, rarinnerfilecount,
			totalpart, size, adddate
		) VALUES
			(360, 'guid-codec', 'Movie.Codec.Release', 'Movie.Codec.Release', 2040, 0, 1, 0, 0, 0, 0, 'tt1234567', 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(361, 'guid-codec-no-imdb', 'Movie.Codec.No.Imdb', 'Movie.Codec.No.Imdb', 2040, 0, 1, 0, 0, 0, 0, '', 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(362, 'guid-codec-preview', 'Movie.Codec.Preview', 'Movie.Codec.Preview', 2040, 0, 1, 1, 0, 0, 0, 'tt7654321', 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, iscategorized, rarinnerfilecount, totalpart, size, adddate) VALUES
			(370, 'guid-wmv-all', 'TV.WMV.Release', 'TV.WMV.Release', 5040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(371, 'guid-wmv-movie', 'Movie.WMV.Release', 'Movie.WMV.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(372, 'guid-wmv-nonmatch', 'TV.NonWMV.Release', 'TV.NonWMV.Release', 5040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(380, 'guid-blfiles', 'Blacklist.Files.Release', 'Blacklist.Files.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(381, 'guid-blfiles-wrong-group', 'Blacklist.Files.Wrong.Group', 'Blacklist.Files.Wrong.Group', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(382, 'guid-blfiles-disabled', 'Blacklist.Files.Disabled', 'Blacklist.Files.Disabled', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(383, 'guid-blfiles-whitelist', 'Blacklist.Files.Whitelist', 'Blacklist.Files.Whitelist', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(384, 'guid-blfiles-from', 'Blacklist.Files.From', 'Blacklist.Files.From', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(385, 'guid-blfiles-old', 'Blacklist.Files.Old', 'Blacklist.Files.Old', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(390, 'guid-blacklist-subject', 'Bad.Subject.Release', 'Bad.Subject.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(391, 'guid-blacklist-wrong-group', 'Bad.Subject.Wrong.Group', 'Bad.Subject.Wrong.Group', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(392, 'guid-blacklist-poster', 'Poster.Blacklist.Release', 'Poster.Blacklist.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(393, 'guid-blacklist-disabled', 'Disabled.Subject.Release', 'Disabled.Subject.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(394, 'guid-blacklist-whitelist', 'Whitelist.Subject.Release', 'Whitelist.Subject.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(395, 'guid-blacklist-old', 'Bad.Subject.Old', 'Bad.Subject.Old', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(400, 'guid-par2-searchname', 'Only.Par2.par2_', 'Only.Par2.par2_', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(401, 'guid-par2-searchname-mixed', 'Mixed.Par2.par2_', 'Mixed.Par2.par2_', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(402, 'guid-par2-files-only', 'All.Files.Are.Repair', 'All.Files.Are.Repair', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(403, 'guid-par2-files-mixed', 'Mixed.Files.Release', 'Mixed.Files.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(404, 'guid-par2-old', 'Old.Par2.par2_', 'Old.Par2.par2_', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(405, 'guid-par2-hashed-nzb', 'Hashed.Par2.NZB', 'Hashed.Par2.NZB', 20, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(406, 'guid-par2-hashed-content', 'Hashed.Mixed.NZB', 'Hashed.Mixed.NZB', 20, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR)`,
		`UPDATE releases SET groups_id = 88 WHERE id IN (380, 382, 383, 384, 385, 390, 392, 393, 394, 395)`,
		`UPDATE releases SET groups_id = 89 WHERE id IN (381, 391)`,
		`UPDATE releases SET fromname = 'BadPoster' WHERE id = 392`,
		`INSERT INTO release_files (releases_id, name, passworded) VALUES
			(200, 'setup.exe', 0),
			(200, 'bonus.exe', 0),
			(201, 'game.exe', 0),
			(202, 'old.exe', 0),
			(320, 'install.bin', 0),
			(321, 'password.url', 0),
			(322, 'release.nzb', 0),
			(323, 'danger.scr ', 0),
			(331, 'archive.rar', 1),
			(360, 'XviD-abc.avi', 0),
			(361, 'XviD-def.avi', 0),
			(362, 'XviD-ghi.avi', 0),
			(370, 'show.x264.sample.wmv', 0),
			(371, 'movie.x264.sample.wmv', 0),
			(372, 'show.xvid.sample.avi', 0),
			(380, 'badcodec.dat', 0),
			(381, 'badcodec.dat', 0),
			(382, 'disabledbad.dat', 0),
			(383, 'whitelistbad.dat', 0),
			(384, 'frombad.dat', 0),
			(385, 'badcodec.dat', 0),
			(400, 'volume.par2', 0),
			(400, 'volume.vol000+001.par2', 0),
			(401, 'volume.par2', 0),
			(401, 'movie.mkv', 0),
			(402, 'repair.par2', 0),
			(402, 'repair.vol000+001.par2', 0),
			(403, 'repair.par2', 0),
			(403, 'archive.rar', 0),
			(404, 'old.par2', 0)`,
		`INSERT INTO collections (id, groups_id, releases_id) VALUES
			(1, 10, 100),
			(2, 10, 200),
			(3, 10, 201),
			(4, 10, NULL)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func removeCrapFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"binaryblacklist": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, groupname, regex, msgcol, optype, status) ORDER BY id SEPARATOR '|'), '') FROM binaryblacklist",
		"settings":        "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', name, COALESCE(value, '')) ORDER BY name SEPARATOR '|'), '') FROM settings",
		"usenet_groups":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, name) ORDER BY id SEPARATOR '|'), '') FROM usenet_groups",
		"releases":        "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, guid, name, searchname, fromname, groups_id, categories_id, passwordstatus, nfostatus, haspreview, jpgstatus, predb_id, videostatus, COALESCE(imdbid, ''), isrenamed, iscategorized, rarinnerfilecount, totalpart, size, adddate) ORDER BY id SEPARATOR '|'), '') FROM releases",
		"release_files":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, releases_id, name, passworded) ORDER BY id SEPARATOR '|'), '') FROM release_files",
		"collections":     "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, groups_id, COALESCE(releases_id, '')) ORDER BY id SEPARATOR '|'), '') FROM collections",
		"side_effects":    "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', operation_key, job, effect, release_id, status_column, status_reason, status_value, COALESCE(payload_text, ''), status, attempts, COALESCE(last_error_code, '')) ORDER BY id SEPARATOR '|'), '') FROM native_worker_side_effects",
	}

	fingerprint := map[string]string{}
	for table, query := range queries {
		var value string
		if err := db.QueryRowContext(ctx, query).Scan(&value); err != nil {
			t.Fatalf("fingerprint %s: %v", table, err)
		}
		fingerprint[table] = value
	}

	return fingerprint
}

func writeGzippedNZB(t *testing.T, basePath string, guid string, subjects []string) {
	t.Helper()

	if guid == "" {
		t.Fatal("guid is required")
	}
	dir := filepath.Join(basePath, guid[:1])
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatalf("mkdir nzb path: %v", err)
	}

	file, err := os.Create(filepath.Join(dir, guid+".nzb.gz"))
	if err != nil {
		t.Fatalf("create nzb: %v", err)
	}
	defer file.Close()

	gzipWriter := gzip.NewWriter(file)
	defer gzipWriter.Close()

	fmt.Fprintln(gzipWriter, `<?xml version="1.0" encoding="UTF-8"?>`)
	fmt.Fprintln(gzipWriter, `<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">`)
	for _, subject := range subjects {
		fmt.Fprintf(gzipWriter, `  <file poster="poster" date="1" subject="%s">`+"\n", html.EscapeString(subject))
		fmt.Fprintln(gzipWriter, `    <groups><group>alt.binaries.test</group></groups>`)
		fmt.Fprintln(gzipWriter, `    <segments><segment bytes="1" number="1">message-id</segment></segments>`)
		fmt.Fprintln(gzipWriter, `  </file>`)
	}
	fmt.Fprintln(gzipWriter, `</nzb>`)
}
