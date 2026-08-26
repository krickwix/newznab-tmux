package namefix

import (
	"context"
	"database/sql"
	"os"
	"reflect"
	"strings"
	"testing"

	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestCRCPriorityMatchesPHPFilePrioritizer(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name string
		want int
	}{
		{name: "Movie.Name.2026.1080p.BluRay.x264-GRP.rar", want: 2},
		{name: "Movie.Name.2026.1080p.BluRay.x264-GRP.part01.rar", want: 3},
		{name: "Movie.Name.2026.1080p.BluRay.x264-GRP.mkv", want: 4},
		{name: "Movie.Name.2026.1080p.BluRay.x264-GRP.nfo", want: 5},
		{name: "Movie.Name.2026.1080p.BluRay.x264-GRP.r00", want: 6},
		{name: "Movie.Name.2026.1080p.BluRay.x264-GRP.sample.mkv", want: 100},
	}

	for _, test := range tests {
		test := test
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()

			if got := CRCPriority(test.name); got != test.want {
				t.Fatalf("CRCPriority(%q) = %d, want %d", test.name, got, test.want)
			}
		})
	}
}

func TestBuildHashedFixDryRunPlanSelectsCRCAndParHashMutationsFromMariaDB(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native hashed fix-name integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetHashedFixTables(t, ctx, db)
	seedHashedFixRows(t, ctx, db)

	fingerprintBefore := hashedFixTableFingerprint(t, ctx, db)

	plan, err := BuildHashedFixDryRunPlan(ctx, db, 10)
	if err != nil {
		t.Fatalf("BuildHashedFixDryRunPlan: %v", err)
	}

	if got := plan.CRCMutations; !reflect.DeepEqual(got, []ReleaseNameMutation{
		{
			ReleaseID:     100,
			OldSearchName: "Hash.Target.CRC.PreDB",
			NewSearchName: "Predb.Match.2026.1080p.BluRay.x264-GRP",
			PreDBID:       10,
			Method:        "crcCheck: PreDB CRC",
			StatusColumn:  "proc_crc32",
			MatchSource:   "predb-crc",
		},
		{
			ReleaseID:     101,
			OldSearchName: "Hash.Target.CRC.Release",
			NewSearchName: "Known.Release.2026.1080p.BluRay.x264-GRP",
			PreDBID:       77,
			Method:        "crcCheck: CRC32",
			StatusColumn:  "proc_crc32",
			MatchSource:   "release-crc",
		},
	}) {
		t.Fatalf("CRCMutations = %#v", got)
	}

	if got := plan.CRCStatusOnly; !reflect.DeepEqual(got, []StatusUpdate{
		{ReleaseID: 102, Column: "proc_crc32", Value: 1, Reason: "crc-miss"},
	}) {
		t.Fatalf("CRCStatusOnly = %#v", got)
	}

	if got := plan.ParHashMutations; !reflect.DeepEqual(got, []ReleaseNameMutation{
		{
			ReleaseID:     300,
			OldSearchName: "Hash.Target.Par.Match",
			NewSearchName: "Known.Par.Release.2026.2160p.WEB.x265-GRP",
			PreDBID:       88,
			Method:        "hashCheck: PAR2 hash_16K",
			StatusColumn:  "proc_hash16k",
			MatchSource:   "par-hash",
		},
	}) {
		t.Fatalf("ParHashMutations = %#v", got)
	}

	if got := plan.ParHashStatusOnly; !reflect.DeepEqual(got, []StatusUpdate{
		{ReleaseID: 301, Column: "proc_hash16k", Value: 1, Reason: "par-hash-miss"},
	}) {
		t.Fatalf("ParHashStatusOnly = %#v", got)
	}

	if fingerprintAfter := hashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildHashedFixWriteContractPlansPHPUpdateSideEffectsWithoutWriting(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native hashed fix-name integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetHashedFixTables(t, ctx, db)
	seedHashedFixRows(t, ctx, db)

	dryRunPlan, err := BuildHashedFixDryRunPlan(ctx, db, 10)
	if err != nil {
		t.Fatalf("BuildHashedFixDryRunPlan: %v", err)
	}

	fingerprintBefore := hashedFixTableFingerprint(t, ctx, db)

	contract, err := BuildHashedFixWriteContract(ctx, db, dryRunPlan, WriteContractOptions{
		MethodOrder: []string{"20", "16"},
		SetStatus:   true,
	})
	if err != nil {
		t.Fatalf("BuildHashedFixWriteContract: %v", err)
	}

	if got := contract.ReleaseUpdates; !reflect.DeepEqual(got, []ReleaseUpdateContract{
		{
			ReleaseID:   100,
			Type:        "CRC32, ",
			Method:      "crcCheck: PreDB CRC",
			MatchSource: "predb-crc",
			Columns:     expectedStatusRenameColumns(int64(10), "Predb.Match.2026.1080p.BluRay.x264-GRP", "proc_crc32"),
		},
		{
			ReleaseID:   101,
			Type:        "CRC32, ",
			Method:      "crcCheck: CRC32",
			MatchSource: "release-crc",
			Columns:     expectedStatusRenameColumns(int64(77), "Known.Release.2026.1080p.BluRay.x264-GRP", "proc_crc32"),
		},
		{
			ReleaseID:   300,
			Type:        "PAR2 hash, ",
			Method:      "hashCheck: PAR2 hash_16K",
			MatchSource: "par-hash",
			Columns:     expectedStatusRenameColumns(int64(88), "Known.Par.Release.2026.2160p.WEB.x265-GRP", "proc_hash16k"),
		},
	}) {
		t.Fatalf("ReleaseUpdates = %#v", got)
	}

	if got := contract.SingleColumnUpdates; !reflect.DeepEqual(got, []SingleColumnUpdateContract{
		{ReleaseID: 100, Column: "proc_crc32", Value: 1, Reason: "crc-predb-match-confirmation"},
		{ReleaseID: 102, Column: "proc_crc32", Value: 1, Reason: "crc-miss"},
		{ReleaseID: 301, Column: "proc_hash16k", Value: 1, Reason: "par-hash-miss"},
	}) {
		t.Fatalf("SingleColumnUpdates = %#v", got)
	}

	if got := contract.RequiredEvents; !reflect.DeepEqual(got, []ReleaseNameFixedEventContract{
		{
			ReleaseID:     100,
			OldName:       "Hash.Target.CRC.PreDB",
			NewName:       "Predb.Match.2026.1080p.BluRay.x264-GRP",
			OldCategoryID: 20,
			GroupID:       1,
			Poster:        "poster@example",
		},
		{
			ReleaseID:     101,
			OldName:       "Hash.Target.CRC.Release",
			NewName:       "Known.Release.2026.1080p.BluRay.x264-GRP",
			OldCategoryID: 20,
			GroupID:       1,
			Poster:        "poster@example",
		},
		{
			ReleaseID:     300,
			OldName:       "Hash.Target.Par.Match",
			NewName:       "Known.Par.Release.2026.2160p.WEB.x265-GRP",
			OldCategoryID: 20,
			GroupID:       1,
			Poster:        "poster@example",
		},
	}) {
		t.Fatalf("RequiredEvents = %#v", got)
	}

	if got := contract.SearchUpdates; !reflect.DeepEqual(got, []SearchUpdateContract{
		{ReleaseID: 100, Reason: "release-update"},
		{ReleaseID: 100, Reason: "crc-predb-match-confirmation"},
		{ReleaseID: 101, Reason: "release-update"},
		{ReleaseID: 102, Reason: "crc-miss"},
		{ReleaseID: 300, Reason: "release-update"},
		{ReleaseID: 301, Reason: "par-hash-miss"},
	}) {
		t.Fatalf("SearchUpdates = %#v", got)
	}

	if got := contract.CategoryResolutionRequired; got != 3 {
		t.Fatalf("CategoryResolutionRequired = %d, want 3", got)
	}

	if fingerprintAfter := hashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write contract changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildHashedFixWriteContractHonorsMethodOrderForOverlappingMatches(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native hashed fix-name integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetHashedFixTables(t, ctx, db)
	seedHashedFixOverlapRows(t, ctx, db)

	dryRunPlan, err := BuildHashedFixDryRunPlan(ctx, db, 10)
	if err != nil {
		t.Fatalf("BuildHashedFixDryRunPlan: %v", err)
	}

	metadataOrder, err := BuildHashedFixWriteContract(ctx, db, dryRunPlan, WriteContractOptions{
		MethodOrder: []string{"20", "16"},
		SetStatus:   true,
	})
	if err != nil {
		t.Fatalf("BuildHashedFixWriteContract metadata order: %v", err)
	}

	if got := metadataOrder.ReleaseUpdates; len(got) != 1 || got[0].ReleaseID != 500 || got[0].Type != "CRC32, " {
		t.Fatalf("metadata order ReleaseUpdates = %#v, want only CRC update for release 500", got)
	}
	if got := metadataOrder.SearchUpdates; !reflect.DeepEqual(got, []SearchUpdateContract{
		{ReleaseID: 500, Reason: "release-update"},
		{ReleaseID: 500, Reason: "crc-predb-match-confirmation"},
	}) {
		t.Fatalf("metadata order SearchUpdates = %#v", got)
	}

	hashedOrder, err := BuildHashedFixWriteContract(ctx, db, dryRunPlan, WriteContractOptions{
		MethodOrder: []string{"16", "20"},
		SetStatus:   true,
	})
	if err != nil {
		t.Fatalf("BuildHashedFixWriteContract hashed order: %v", err)
	}

	if got := hashedOrder.ReleaseUpdates; len(got) != 1 || got[0].ReleaseID != 500 || got[0].Type != "PAR2 hash, " {
		t.Fatalf("hashed order ReleaseUpdates = %#v, want only PAR hash update for release 500", got)
	}
	if got := hashedOrder.SingleColumnUpdates; len(got) != 0 {
		t.Fatalf("hashed order SingleColumnUpdates = %#v, want none after PAR hash wins", got)
	}
}

func TestRehearseHashedFixWriteContractRollsBackConcreteStatusUpdates(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native hashed fix-name integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetHashedFixTables(t, ctx, db)
	seedHashedFixRows(t, ctx, db)

	dryRunPlan, err := BuildHashedFixDryRunPlan(ctx, db, 10)
	if err != nil {
		t.Fatalf("BuildHashedFixDryRunPlan: %v", err)
	}
	contract, err := BuildHashedFixWriteContract(ctx, db, dryRunPlan, WriteContractOptions{
		MethodOrder: []string{"20", "16"},
		SetStatus:   true,
	})
	if err != nil {
		t.Fatalf("BuildHashedFixWriteContract: %v", err)
	}

	fingerprintBefore := hashedFixTableFingerprint(t, ctx, db)

	result, err := RehearseHashedFixWriteContract(ctx, db, contract)
	if err != nil {
		t.Fatalf("RehearseHashedFixWriteContract: %v", err)
	}

	if result.SingleColumnUpdatesAttempted != 3 {
		t.Fatalf("SingleColumnUpdatesAttempted = %d, want 3", result.SingleColumnUpdatesAttempted)
	}
	if result.SingleColumnRowsAffected != 3 {
		t.Fatalf("SingleColumnRowsAffected = %d, want 3", result.SingleColumnRowsAffected)
	}
	if result.ReleaseUpdatesBlocked != 3 {
		t.Fatalf("ReleaseUpdatesBlocked = %d, want 3", result.ReleaseUpdatesBlocked)
	}
	if result.ReleaseUpdatesAttempted != 0 {
		t.Fatalf("ReleaseUpdatesAttempted = %d, want 0", result.ReleaseUpdatesAttempted)
	}
	if !result.RolledBack {
		t.Fatalf("RolledBack = false, want true")
	}
	if result.WritesCommitted != 0 {
		t.Fatalf("WritesCommitted = %d, want 0", result.WritesCommitted)
	}
	if len(result.BlockedReleaseIDs) != 3 || result.BlockedReleaseIDs[0] != 100 || result.BlockedReleaseIDs[1] != 101 || result.BlockedReleaseIDs[2] != 300 {
		t.Fatalf("BlockedReleaseIDs = %#v", result.BlockedReleaseIDs)
	}

	if fingerprintAfter := hashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRehearseHashedFixWriteContractRejectsUnsafeSingleColumnUpdateAndRollsBack(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native hashed fix-name integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetHashedFixTables(t, ctx, db)
	seedHashedFixRows(t, ctx, db)

	fingerprintBefore := hashedFixTableFingerprint(t, ctx, db)

	_, err = RehearseHashedFixWriteContract(ctx, db, HashedFixWriteContract{
		SingleColumnUpdates: []SingleColumnUpdateContract{
			{ReleaseID: 100, Column: "searchname", Value: 1, Reason: "unsafe-column"},
		},
	})
	if err == nil {
		t.Fatalf("RehearseHashedFixWriteContract succeeded with unsafe column")
	}
	if !strings.Contains(err.Error(), `does not allow column "searchname"`) {
		t.Fatalf("error = %v, want unsafe column rejection", err)
	}

	if fingerprintAfter := hashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("unsafe rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestRehearseResolvedHashedFixWriteContractRollsBackReleaseUpdates(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native hashed fix-name integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetHashedFixTables(t, ctx, db)
	seedHashedFixRows(t, ctx, db)

	dryRunPlan, err := BuildHashedFixDryRunPlan(ctx, db, 10)
	if err != nil {
		t.Fatalf("BuildHashedFixDryRunPlan: %v", err)
	}
	contract, err := BuildHashedFixWriteContract(ctx, db, dryRunPlan, WriteContractOptions{
		MethodOrder: []string{"20", "16"},
		SetStatus:   true,
	})
	if err != nil {
		t.Fatalf("BuildHashedFixWriteContract: %v", err)
	}

	fingerprintBefore := hashedFixTableFingerprint(t, ctx, db)

	result, err := RehearseResolvedHashedFixWriteContract(ctx, db, contract, resolvedOracleForContract(contract, map[int64]int{
		100: 5040,
		101: 5040,
		300: 5045,
	}))
	if err != nil {
		t.Fatalf("RehearseResolvedHashedFixWriteContract: %v", err)
	}

	if result.ReleaseUpdatesAttempted != 3 {
		t.Fatalf("ReleaseUpdatesAttempted = %d, want 3", result.ReleaseUpdatesAttempted)
	}
	if result.ReleaseUpdateRowsAffected != 3 {
		t.Fatalf("ReleaseUpdateRowsAffected = %d, want 3", result.ReleaseUpdateRowsAffected)
	}
	if result.ReleaseUpdatesBlocked != 0 {
		t.Fatalf("ReleaseUpdatesBlocked = %d, want 0", result.ReleaseUpdatesBlocked)
	}
	if len(result.BlockedReleaseIDs) != 0 {
		t.Fatalf("BlockedReleaseIDs = %#v, want empty", result.BlockedReleaseIDs)
	}
	if result.SingleColumnUpdatesAttempted != 3 {
		t.Fatalf("SingleColumnUpdatesAttempted = %d, want 3", result.SingleColumnUpdatesAttempted)
	}
	if result.SingleColumnRowsAffected != 2 {
		t.Fatalf("SingleColumnRowsAffected = %d, want 2 after resolved release update set-status overlap", result.SingleColumnRowsAffected)
	}
	if !result.RolledBack {
		t.Fatalf("RolledBack = false, want true")
	}
	if result.WritesCommitted != 0 {
		t.Fatalf("WritesCommitted = %d, want 0", result.WritesCommitted)
	}

	if fingerprintAfter := hashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("resolved write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitHashedFixMissStatusUpdatesCommitsOnlyTrueMisses(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native hashed fix-name integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetHashedFixTables(t, ctx, db)
	seedHashedFixRows(t, ctx, db)

	dryRunPlan, err := BuildHashedFixDryRunPlan(ctx, db, 10)
	if err != nil {
		t.Fatalf("BuildHashedFixDryRunPlan: %v", err)
	}
	contract, err := BuildHashedFixWriteContract(ctx, db, dryRunPlan, WriteContractOptions{
		MethodOrder: []string{"20", "16"},
		SetStatus:   true,
	})
	if err != nil {
		t.Fatalf("BuildHashedFixWriteContract: %v", err)
	}

	result, err := CommitHashedFixMissStatusUpdates(ctx, db, contract)
	if err != nil {
		t.Fatalf("CommitHashedFixMissStatusUpdates: %v", err)
	}

	if result.SingleColumnUpdatesAttempted != 2 {
		t.Fatalf("SingleColumnUpdatesAttempted = %d, want 2", result.SingleColumnUpdatesAttempted)
	}
	if result.SingleColumnUpdatesCommitted != 2 || result.SingleColumnRowsAffected != 2 {
		t.Fatalf("committed=%d rows=%d, want 2/2", result.SingleColumnUpdatesCommitted, result.SingleColumnRowsAffected)
	}
	if result.SingleColumnUpdatesBlocked != 1 {
		t.Fatalf("SingleColumnUpdatesBlocked = %d, want crc-predb confirmation blocked", result.SingleColumnUpdatesBlocked)
	}
	if result.ReleaseUpdatesBlocked != 3 {
		t.Fatalf("ReleaseUpdatesBlocked = %d, want release renames blocked", result.ReleaseUpdatesBlocked)
	}
	if result.WritesCommitted != 2 {
		t.Fatalf("WritesCommitted = %d, want 2", result.WritesCommitted)
	}
	if !reflect.DeepEqual(result.CommittedReleaseIDs, []int64{102, 301}) {
		t.Fatalf("CommittedReleaseIDs = %#v, want [102 301]", result.CommittedReleaseIDs)
	}
	if !reflect.DeepEqual(result.BlockedReleaseIDs, []int64{100, 101, 300}) {
		t.Fatalf("BlockedReleaseIDs = %#v, want blocked release updates", result.BlockedReleaseIDs)
	}
	if !reflect.DeepEqual(result.BlockedStatusReleaseIDs, []int64{100}) {
		t.Fatalf("BlockedStatusReleaseIDs = %#v, want [100]", result.BlockedStatusReleaseIDs)
	}
	if result.SearchSideEffectRowsEnqueued != 2 {
		t.Fatalf("SearchSideEffectRowsEnqueued = %d, want 2", result.SearchSideEffectRowsEnqueued)
	}
	if result.SearchUpdatesEnqueued != 2 {
		t.Fatalf("SearchUpdatesEnqueued = %d, want 2", result.SearchUpdatesEnqueued)
	}

	outboxRows := nativeSearchSideEffectOutboxRows(t, ctx, db)
	if len(outboxRows) != 2 {
		t.Fatalf("outbox rows = %#v, want two pending search sync rows", outboxRows)
	}
	for _, row := range outboxRows {
		if row.Job != "hashed-fixnames" || row.Effect != "release-search-sync" || row.Status != "pending" {
			t.Fatalf("outbox row metadata = %#v, want pending hashed-fixnames search sync", row)
		}
		if row.StatusValue != 1 {
			t.Fatalf("outbox row status value = %#v, want 1", row)
		}
	}
	if outboxRows[0].ReleaseID != 102 || outboxRows[0].StatusColumn != "proc_crc32" || outboxRows[0].StatusReason != "crc-miss" {
		t.Fatalf("first outbox row = %#v, want release 102 crc miss", outboxRows[0])
	}
	if outboxRows[1].ReleaseID != 301 || outboxRows[1].StatusColumn != "proc_hash16k" || outboxRows[1].StatusReason != "par-hash-miss" {
		t.Fatalf("second outbox row = %#v, want release 301 par-hash miss", outboxRows[1])
	}

	assertHashedFixStatus(t, ctx, db, 102, "proc_crc32", 1)
	assertHashedFixStatus(t, ctx, db, 301, "proc_hash16k", 1)
	assertHashedFixStatus(t, ctx, db, 100, "proc_crc32", 0)
	assertHashedFixReleaseName(t, ctx, db, 100, "Hash.Target.CRC.PreDB", 20, 0)
	assertHashedFixReleaseName(t, ctx, db, 101, "Hash.Target.CRC.Release", 20, 0)
	assertHashedFixReleaseName(t, ctx, db, 300, "Hash.Target.Par.Match", 20, 0)

	second, err := CommitHashedFixMissStatusUpdates(ctx, db, contract)
	if err != nil {
		t.Fatalf("second CommitHashedFixMissStatusUpdates: %v", err)
	}
	if second.SingleColumnUpdatesCommitted != 0 || second.SingleColumnUpdatesSkipped != 2 || second.WritesCommitted != 0 {
		t.Fatalf("second commit = %#v, want idempotent skip with zero writes", second)
	}
	if !reflect.DeepEqual(second.SkippedReleaseIDs, []int64{102, 301}) {
		t.Fatalf("second SkippedReleaseIDs = %#v, want [102 301]", second.SkippedReleaseIDs)
	}
	if second.SearchSideEffectRowsEnqueued != 0 || second.SearchUpdatesEnqueued != 0 {
		t.Fatalf("second outbox counters = rows:%d updates:%d, want zero", second.SearchSideEffectRowsEnqueued, second.SearchUpdatesEnqueued)
	}
	if outboxRows := nativeSearchSideEffectOutboxRows(t, ctx, db); len(outboxRows) != 2 {
		t.Fatalf("outbox rows after idempotent second commit = %#v, want original two rows only", outboxRows)
	}
}

func TestCommitHashedFixMissStatusUpdatesRejectsUnsafeContract(t *testing.T) {
	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native hashed fix-name integration tests")
	}

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireHashedFixIntegrationLock(t, ctx, db)
	defer unlock()

	testdb.RequireSafeMySQL(t, ctx, db, dsn)
	resetHashedFixTables(t, ctx, db)
	seedHashedFixRows(t, ctx, db)
	fingerprintBefore := hashedFixTableFingerprint(t, ctx, db)

	_, err = CommitHashedFixMissStatusUpdates(ctx, db, HashedFixWriteContract{
		SingleColumnUpdates: []SingleColumnUpdateContract{
			{ReleaseID: 102, Column: "searchname", Value: 1, Reason: "crc-miss"},
		},
	})
	if err == nil || !strings.Contains(err.Error(), `does not allow column "searchname"`) {
		t.Fatalf("error = %v, want unsafe column rejection", err)
	}

	if fingerprintAfter := hashedFixTableFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("unsafe commit changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
	if outboxRows := nativeSearchSideEffectOutboxRows(t, ctx, db); len(outboxRows) != 0 {
		t.Fatalf("unsafe commit left outbox rows: %#v", outboxRows)
	}
}

func TestRehearseResolvedHashedFixWriteContractRejectsStaleOracleMetadata(t *testing.T) {
	contract := HashedFixWriteContract{
		ReleaseUpdates: []ReleaseUpdateContract{
			{
				ReleaseID: 100,
				Columns: []PlannedColumn{
					{Column: "searchname", Value: "Fresh.Name.2026.1080p-GRP"},
					{
						Column:      "categories_id",
						ValueSource: "CategorizationService.determineCategory(groups_id, new_title, fromname)",
					},
				},
			},
		},
		RequiredEvents: []ReleaseNameFixedEventContract{
			{
				ReleaseID:     100,
				OldName:       "Old.Hash.Name",
				NewName:       "Fresh.Name.2026.1080p-GRP",
				OldCategoryID: 20,
				GroupID:       4,
				Poster:        "poster@example",
			},
		},
	}
	oracle := resolvedOracleForContract(contract, map[int64]int{100: 5040})
	oracle.WriteContract.ResolvedReleaseUpdates[0].RequiredEvent.NewName = "Stale.Name.2026.1080p-GRP"

	_, err := validateResolvedUpdates(contract, oracle)
	if err == nil {
		t.Fatalf("validateResolvedUpdates succeeded with stale oracle metadata")
	}
	if !strings.Contains(err.Error(), "required_event.new_name") {
		t.Fatalf("error = %v, want required_event.new_name mismatch", err)
	}
}

func TestDecodeResolvedWriteContractOracleRejectsNonZeroOrStringWrites(t *testing.T) {
	t.Parallel()

	for _, payload := range []string{
		`{"schema_version":1,"dry_run":true,"writes":"0","write_contract":{"writes":0,"resolved_release_updates":[]}}`,
		`{"schema_version":1,"dry_run":true,"writes":1,"write_contract":{"writes":0,"resolved_release_updates":[]}}`,
		`{"schema_version":1,"dry_run":true,"writes":0,"write_contract":{"writes":"0","resolved_release_updates":[]}}`,
	} {
		_, err := DecodeResolvedWriteContractOracle(strings.NewReader(payload))
		if err == nil {
			t.Fatalf("DecodeResolvedWriteContractOracle(%s) succeeded, want validation error", payload)
		}
		if !strings.Contains(err.Error(), "writes=0") {
			t.Fatalf("error = %v, want writes validation", err)
		}
	}
}

func TestWriteContractSummaryReportsPlannedSideEffectsWithoutWrites(t *testing.T) {
	t.Parallel()

	summary := WriteContractSummary(HashedFixWriteContract{
		ReleaseUpdates:             []ReleaseUpdateContract{{ReleaseID: 1}, {ReleaseID: 2}},
		SingleColumnUpdates:        []SingleColumnUpdateContract{{ReleaseID: 3}},
		RequiredEvents:             []ReleaseNameFixedEventContract{{ReleaseID: 1}},
		SearchUpdates:              []SearchUpdateContract{{ReleaseID: 1}, {ReleaseID: 3}},
		CategoryResolutionRequired: 2,
	})

	for _, want := range []string{
		"hashed-fixnames write-contract",
		"planned-release-updates=2",
		"planned-single-column-updates=1",
		"required-events=1",
		"required-search-updates=2",
		"category-resolution-required=2",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("WriteContractSummary() = %q, missing %q", summary, want)
		}
	}
}

func resolvedOracleForContract(contract HashedFixWriteContract, categories map[int64]int) ResolvedWriteContractOracle {
	updates := make([]ResolvedReleaseUpdate, 0, len(contract.ReleaseUpdates))
	events := map[int64]ReleaseNameFixedEventContract{}
	for _, event := range contract.RequiredEvents {
		events[event.ReleaseID] = event
	}

	for _, releaseUpdate := range contract.ReleaseUpdates {
		event := events[releaseUpdate.ReleaseID]
		categoryID := categories[releaseUpdate.ReleaseID]
		columns := make([]PlannedColumn, 0, len(releaseUpdate.Columns))
		for _, column := range releaseUpdate.Columns {
			if column.Column == "categories_id" {
				column.Value = categoryID
			}
			columns = append(columns, column)
		}

		updates = append(updates, ResolvedReleaseUpdate{
			ReleaseID: releaseUpdate.ReleaseID,
			Columns:   columns,
			CategoryResolution: ResolvedCategoryResolution{
				GroupID:       event.GroupID,
				NewName:       event.NewName,
				PosterPresent: event.Poster != "",
				CategoriesID:  categoryID,
				ValueSource:   categoryValueSource(contract, releaseUpdate.ReleaseID),
			},
			RequiredEvent: ResolvedRequiredEvent{
				ReleaseID:     event.ReleaseID,
				OldName:       event.OldName,
				NewName:       event.NewName,
				OldCategoryID: event.OldCategoryID,
				NewCategoryID: categoryID,
				GroupID:       event.GroupID,
				PosterPresent: event.Poster != "",
			},
		})
	}

	return ResolvedWriteContractOracle{
		SchemaVersion: 1,
		Mode:          "native-write-contract-resolve",
		DryRun:        true,
		Writes:        0,
		WriteContract: ResolvedWriteContract{
			Writes:                 0,
			ResolvedReleaseUpdates: updates,
		},
	}
}

func expectedStatusRenameColumns(preDBID int64, searchName string, statusColumn string) []PlannedColumn {
	return []PlannedColumn{
		{Column: "videos_id", Value: 0},
		{Column: "tv_episodes_id", Value: 0},
		{Column: "imdbid", Value: nil},
		{Column: "musicinfo_id", Value: ""},
		{Column: "consoleinfo_id", Value: ""},
		{Column: "bookinfo_id", Value: ""},
		{Column: "anidbid", Value: ""},
		{Column: "predb_id", Value: preDBID},
		{Column: "searchname", Value: searchName},
		{
			Column:      "categories_id",
			ValueSource: "CategorizationService.determineCategory(groups_id, new_title, fromname)",
		},
		{Column: "isrenamed", Value: 1},
		{Column: "iscategorized", Value: 1},
		{Column: statusColumn, Value: 1},
	}
}

func acquireHashedFixIntegrationLock(t *testing.T, ctx context.Context, db *sql.DB) func() {
	t.Helper()

	conn, err := db.Conn(ctx)
	if err != nil {
		t.Fatalf("open mysql lock connection: %v", err)
	}

	var acquired int
	if err := conn.QueryRowContext(ctx, "SELECT GET_LOCK('nntmux_native_integration_schema_test', 30)").Scan(&acquired); err != nil {
		_ = conn.Close()
		t.Fatalf("acquire mysql integration lock: %v", err)
	}
	if acquired != 1 {
		_ = conn.Close()
		t.Fatalf("acquire mysql integration lock = %d, want 1", acquired)
	}

	return func() {
		t.Helper()

		defer conn.Close()

		if _, err := conn.ExecContext(ctx, "SELECT RELEASE_LOCK('nntmux_native_integration_schema_test')"); err != nil {
			t.Fatalf("release mysql integration lock: %v", err)
		}
	}
}

func resetHashedFixTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS native_worker_side_effects",
		"DROP TABLE IF EXISTS release_files",
		"DROP TABLE IF EXISTS par_hashes",
		"DROP TABLE IF EXISTS predb_crcs",
		"DROP TABLE IF EXISTS predb",
		"DROP TABLE IF EXISTS releases",
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			groups_id INT NOT NULL DEFAULT 0,
			size BIGINT NOT NULL DEFAULT 0,
			postdate DATETIME NULL,
			adddate DATETIME NULL,
			fromname VARCHAR(255) NULL,
			categories_id INT NOT NULL DEFAULT 10,
			videos_id INT NOT NULL DEFAULT 0,
			tv_episodes_id INT NOT NULL DEFAULT 0,
			imdbid VARCHAR(16) NULL,
			musicinfo_id VARCHAR(32) NULL,
			consoleinfo_id VARCHAR(32) NULL,
			bookinfo_id VARCHAR(32) NULL,
			predb_id INT NOT NULL DEFAULT 0,
			anidbid VARCHAR(32) NULL,
			isrenamed TINYINT NOT NULL DEFAULT 0,
			iscategorized TINYINT NOT NULL DEFAULT 0,
			proc_crc32 TINYINT NOT NULL DEFAULT 0,
			proc_hash16k TINYINT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE release_files (
			id INT AUTO_INCREMENT PRIMARY KEY,
			releases_id INT NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			size BIGINT NOT NULL DEFAULT 0,
			crc32 VARCHAR(32) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL
		)`,
		`CREATE TABLE predb (
			id INT AUTO_INCREMENT PRIMARY KEY,
			title VARCHAR(255) NOT NULL DEFAULT '',
			predate DATETIME NULL,
			source VARCHAR(64) NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE predb_crcs (
			id INT AUTO_INCREMENT PRIMARY KEY,
			predb_id INT NOT NULL,
			crchash VARCHAR(32) NOT NULL DEFAULT '',
			filesize BIGINT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE par_hashes (
			releases_id INT NOT NULL,
			hash VARCHAR(32) NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE native_worker_side_effects (
			id BIGINT AUTO_INCREMENT PRIMARY KEY,
			operation_key VARCHAR(191) NOT NULL,
			job VARCHAR(64) NOT NULL,
			effect VARCHAR(64) NOT NULL,
			release_id BIGINT UNSIGNED NOT NULL,
			status_column VARCHAR(32) NOT NULL,
			status_reason VARCHAR(64) NOT NULL,
			status_value TINYINT UNSIGNED NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			available_at TIMESTAMP(6) NULL,
			processed_at TIMESTAMP(6) NULL,
			last_error_code VARCHAR(64) NULL,
			created_at TIMESTAMP(6) NULL,
			updated_at TIMESTAMP(6) NULL,
			UNIQUE KEY ux_native_worker_side_effects_operation_key (operation_key),
			KEY ix_native_worker_side_effects_status_available (status, available_at, id),
			KEY ix_native_worker_side_effects_release_status (release_id, status)
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func seedHashedFixRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO predb (id, title, predate, source) VALUES
			(10, 'Predb.Match.2026.1080p.BluRay.x264-GRP', '2026-06-14 00:00:00', 'srrdb')`,
		`INSERT INTO predb_crcs (predb_id, crchash, filesize) VALUES
			(10, 'AABBCCDD', 15000000)`,
		`INSERT INTO releases (id, name, searchname, groups_id, size, postdate, adddate, fromname, categories_id, predb_id, anidbid, isrenamed, proc_crc32, proc_hash16k) VALUES
			(100, 'hash-target-crc-predb', 'Hash.Target.CRC.PreDB', 1, 15000000, '2026-06-15 12:05:00', '2026-06-15 12:05:00', 'poster@example', 20, 0, 0, 0, 0, 0),
			(101, 'hash-target-crc-release', 'Hash.Target.CRC.Release', 1, 20000000, '2026-06-15 12:04:00', '2026-06-15 12:04:00', 'poster@example', 20, 0, 0, 0, 0, 0),
			(102, 'hash-target-crc-miss', 'Hash.Target.CRC.Miss', 1, 10000000, '2026-06-15 12:03:00', '2026-06-15 12:03:00', 'poster@example', 20, 0, 0, 0, 0, 0),
			(103, 'other-category-crc', 'Other.Category.CRC', 1, 15000000, '2026-06-15 12:02:30', '2026-06-15 12:02:30', 'poster@example', 10, 0, 0, 0, 0, 0),
			(200, 'known-release-crc', 'Known.Release.2026.1080p.BluRay.x264-GRP', 1, 20400000, '2026-06-14 12:00:00', '2026-06-14 12:00:00', 'poster@example', 5040, 77, 0, 1, 1, 1),
			(300, 'hash-target-par-match', 'Hash.Target.Par.Match', 1, 40000000, '2026-06-15 12:02:00', '2026-06-15 12:02:00', 'poster@example', 20, 0, 0, 0, 0, 0),
			(301, 'hash-target-par-miss', 'Hash.Target.Par.Miss', 1, 50000000, '2026-06-15 12:01:00', '2026-06-15 12:01:00', 'poster@example', 20, 0, 0, 0, 0, 0),
			(400, 'known-par-release', 'Known.Par.Release.2026.2160p.WEB.x265-GRP', 1, 41000000, '2026-06-14 11:00:00', '2026-06-14 11:00:00', 'poster@example', 5040, 88, 0, 1, 1, 1),
			(401, 'known-par-outside-size', 'Known.Par.Outside.Size.2026.2160p.WEB.x265-GRP', 1, 90000000, '2026-06-14 10:00:00', '2026-06-14 10:00:00', 'poster@example', 5040, 89, 0, 1, 1, 1)`,
		`INSERT INTO release_files (releases_id, name, size, crc32, created_at) VALUES
			(100, 'Predb.Match.2026.1080p.BluRay.x264-GRP.sample.mkv', 15000000, 'BADBAD00', '2026-06-15 12:05:00'),
			(100, 'Predb.Match.2026.1080p.BluRay.x264-GRP.rar', 15000000, 'aabbccdd', '2026-06-15 12:05:01'),
			(101, 'Known.Release.2026.1080p.BluRay.x264-GRP.r00', 20000000, 'DDCCBBAA', '2026-06-15 12:04:00'),
			(102, 'No.Match.2026.1080p.BluRay.x264-GRP.r00', 10000000, 'EEFF0011', '2026-06-15 12:03:00'),
			(103, 'Excluded.Other.Category.2026.1080p.BluRay.x264-GRP.rar', 15000000, 'AABBCCDD', '2026-06-15 12:02:30'),
			(200, 'Known.Release.2026.1080p.BluRay.x264-GRP.r00', 20400000, 'DDCCBBAA', '2026-06-14 12:00:00')`,
		`INSERT INTO par_hashes (releases_id, hash) VALUES
			(300, 'parhash-match'),
			(301, 'parhash-miss'),
			(400, 'parhash-match'),
			(401, 'parhash-miss')`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func seedHashedFixOverlapRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO predb (id, title, predate, source) VALUES
			(50, 'CRC.Winning.Name.2026.1080p.BluRay.x264-GRP', '2026-06-14 00:00:00', 'srrdb')`,
		`INSERT INTO predb_crcs (predb_id, crchash, filesize) VALUES
			(50, '1234ABCD', 60000000)`,
		`INSERT INTO releases (id, name, searchname, groups_id, size, postdate, adddate, fromname, categories_id, predb_id, anidbid, isrenamed, proc_crc32, proc_hash16k) VALUES
			(500, 'hash-target-overlap', 'Hash.Target.Overlap', 1, 60000000, '2026-06-15 12:10:00', '2026-06-15 12:10:00', 'poster@example', 20, 0, 0, 0, 0, 0),
			(501, 'known-par-overlap', 'PAR.Winning.Name.2026.1080p.BluRay.x264-GRP', 1, 61000000, '2026-06-14 11:00:00', '2026-06-14 11:00:00', 'poster@example', 5040, 99, 0, 1, 1, 1)`,
		`INSERT INTO release_files (releases_id, name, size, crc32, created_at) VALUES
			(500, 'CRC.Winning.Name.2026.1080p.BluRay.x264-GRP.rar', 60000000, '1234ABCD', '2026-06-15 12:10:00')`,
		`INSERT INTO par_hashes (releases_id, hash) VALUES
			(500, 'overlap-hash'),
			(501, 'overlap-hash')`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed overlap %q: %v", statement, err)
		}
	}
}

func hashedFixTableFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"releases":      "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, searchname, categories_id, predb_id, proc_crc32, proc_hash16k) ORDER BY id SEPARATOR '|'), '') FROM releases",
		"release_files": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', releases_id, name, size, crc32) ORDER BY releases_id, name SEPARATOR '|'), '') FROM release_files",
		"predb":         "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, title, predate) ORDER BY id SEPARATOR '|'), '') FROM predb",
		"predb_crcs":    "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, predb_id, crchash, filesize) ORDER BY id SEPARATOR '|'), '') FROM predb_crcs",
		"par_hashes":    "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', releases_id, hash) ORDER BY releases_id, hash SEPARATOR '|'), '') FROM par_hashes",
		"outbox":        "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', operation_key, job, effect, release_id, status_column, status_reason, status_value, status, attempts, COALESCE(last_error_code, '')) ORDER BY id SEPARATOR '|'), '') FROM native_worker_side_effects",
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

type nativeSearchSideEffectOutboxRow struct {
	OperationKey string
	Job          string
	Effect       string
	Status       string
	ReleaseID    int64
	StatusColumn string
	StatusReason string
	StatusValue  int
}

func nativeSearchSideEffectOutboxRows(t *testing.T, ctx context.Context, db *sql.DB) []nativeSearchSideEffectOutboxRow {
	t.Helper()

	rows, err := db.QueryContext(ctx, "SELECT operation_key, job, effect, status, release_id, status_column, status_reason, status_value FROM native_worker_side_effects ORDER BY release_id")
	if err != nil {
		t.Fatalf("query native side-effect outbox: %v", err)
	}
	defer rows.Close()

	outboxRows := []nativeSearchSideEffectOutboxRow{}
	for rows.Next() {
		var row nativeSearchSideEffectOutboxRow
		if err := rows.Scan(&row.OperationKey, &row.Job, &row.Effect, &row.Status, &row.ReleaseID, &row.StatusColumn, &row.StatusReason, &row.StatusValue); err != nil {
			t.Fatalf("scan native side-effect outbox: %v", err)
		}
		outboxRows = append(outboxRows, row)
	}
	if err := rows.Err(); err != nil {
		t.Fatalf("read native side-effect outbox: %v", err)
	}

	return outboxRows
}

func assertHashedFixStatus(t *testing.T, ctx context.Context, db *sql.DB, releaseID int64, column string, want int) {
	t.Helper()

	var got int
	if err := db.QueryRowContext(ctx, "SELECT "+column+" FROM releases WHERE id = ?", releaseID).Scan(&got); err != nil {
		t.Fatalf("read release %d %s: %v", releaseID, column, err)
	}
	if got != want {
		t.Fatalf("release %d %s = %d, want %d", releaseID, column, got, want)
	}
}

func assertHashedFixReleaseName(t *testing.T, ctx context.Context, db *sql.DB, releaseID int64, wantSearchName string, wantCategoryID int, wantPreDBID int) {
	t.Helper()

	var searchName string
	var categoryID int
	var preDBID int
	if err := db.QueryRowContext(ctx, "SELECT searchname, categories_id, predb_id FROM releases WHERE id = ?", releaseID).Scan(&searchName, &categoryID, &preDBID); err != nil {
		t.Fatalf("read release %d name/category/predb: %v", releaseID, err)
	}
	if searchName != wantSearchName || categoryID != wantCategoryID || preDBID != wantPreDBID {
		t.Fatalf("release %d = (%q,%d,%d), want (%q,%d,%d)", releaseID, searchName, categoryID, preDBID, wantSearchName, wantCategoryID, wantPreDBID)
	}
}
