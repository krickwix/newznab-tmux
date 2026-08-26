package postprocess

import (
	"context"
	"database/sql"
	"encoding/json"
	"reflect"
	"strings"
	"testing"

	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

func TestBuildPostTVDryRunPlanSelectsTVAndAnimeBucketsWithoutChangingMariaDB(t *testing.T) {
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
	resetPostprocessTables(t, ctx, db)
	seedPostTVRows(t, ctx, db)

	fingerprintBefore := postprocessFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "tv"},
		{Type: "ani"},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 2 {
		t.Fatalf("Commands = %d, want 2", plan.Commands)
	}
	if plan.Types != 2 {
		t.Fatalf("Types = %d, want 2", plan.Types)
	}
	if plan.BucketEntries != 3 {
		t.Fatalf("BucketEntries = %d, want 3", plan.BucketEntries)
	}
	if plan.Writes != 0 {
		t.Fatalf("Writes = %d, want 0", plan.Writes)
	}
	if len(plan.Results) != 2 {
		t.Fatalf("Results = %#v, want two type results", plan.Results)
	}

	tv := plan.Results[0]
	if tv.Type != "tv" || tv.BucketEntries != 2 || tv.MaxProcesses != 3 || tv.RenamedMode != 1 || !tv.Pipeline {
		t.Fatalf("tv result = %#v", tv)
	}
	if got := bucketRenamedMap(tv.Buckets); !reflect.DeepEqual(got, map[string]int{"A": 1, "b": 1}) {
		t.Fatalf("tv buckets = %#v", got)
	}

	anime := plan.Results[1]
	if anime.Type != "anime" || anime.BucketEntries != 1 || anime.MaxProcesses != 3 || anime.RenamedMode != 0 || anime.Pipeline {
		t.Fatalf("anime result = %#v", anime)
	}
	if got := bucketRenamedMap(anime.Buckets); !reflect.DeepEqual(got, map[string]int{"c": 0}) {
		t.Fatalf("anime buckets = %#v", got)
	}

	summary := DryRunSummary(plan)
	for _, want := range []string{
		"postprocess mysql dry-run",
		"commands=2",
		"types=2",
		"bucket-entries=3",
		"tv-buckets=2",
		"tv-max-processes=3",
		"tv-renamed-mode=1",
		"tv-pipeline=true",
		"anime-buckets=1",
		"anime-max-processes=3",
		"anime-pipeline=false",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("summary = %q, missing %q", summary, want)
		}
	}

	if fingerprintAfter := postprocessFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildPostTVDryRunPlanHonorsLookupSettings(t *testing.T) {
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
	resetPostprocessTables(t, ctx, db)
	seedPostTVRows(t, ctx, db)

	if _, err := db.ExecContext(ctx, `
		UPDATE settings
		SET value = CASE name
			WHEN 'lookuptv' THEN '2'
			WHEN 'lookupanidb' THEN '0'
			ELSE value
		END
		WHERE name IN ('lookuptv', 'lookupanidb')`); err != nil {
		t.Fatalf("update settings: %v", err)
	}

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "tv"},
		{Type: "ani"},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.BucketEntries != 1 {
		t.Fatalf("BucketEntries = %d, want renamed-only TV bucket", plan.BucketEntries)
	}
	if len(plan.Results) != 2 {
		t.Fatalf("Results = %#v, want two type results", plan.Results)
	}

	tv := plan.Results[0]
	if tv.Type != "tv" || tv.BucketEntries != 1 || tv.RenamedMode != 1 || !tv.Pipeline {
		t.Fatalf("tv result = %#v", tv)
	}
	if got := bucketRenamedMap(tv.Buckets); !reflect.DeepEqual(got, map[string]int{"b": 1}) {
		t.Fatalf("tv buckets = %#v", got)
	}

	anime := plan.Results[1]
	if anime.Type != "anime" || anime.BucketEntries != 0 || len(anime.Buckets) != 0 {
		t.Fatalf("anime result = %#v, want disabled zero result", anime)
	}
}

func TestBuildPostMovieDryRunPlanSelectsMovieBucketsWithoutChangingMariaDB(t *testing.T) {
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
	resetPostprocessTables(t, ctx, db)
	seedPostMovieRows(t, ctx, db)

	fingerprintBefore := postprocessFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{{Type: "mov"}})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 1 || plan.Types != 1 || plan.BucketEntries != 2 || plan.Writes != 0 {
		t.Fatalf("plan = %#v", plan)
	}
	if len(plan.Results) != 1 {
		t.Fatalf("Results = %#v, want one movie result", plan.Results)
	}

	movie := plan.Results[0]
	if movie.Type != "movie" || movie.BucketEntries != 2 || movie.MaxProcesses != 3 || movie.RenamedMode != 1 || movie.Pipeline {
		t.Fatalf("movie result = %#v", movie)
	}
	if got := bucketRenamedMap(movie.Buckets); !reflect.DeepEqual(got, map[string]int{"m": 1, "n": 1}) {
		t.Fatalf("movie buckets = %#v", got)
	}

	if fingerprintAfter := postprocessFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildPostMovieDryRunPlanHonorsRenamedSettingsAndCommandMode(t *testing.T) {
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
	resetPostprocessTables(t, ctx, db)
	seedPostMovieRows(t, ctx, db)

	if _, err := db.ExecContext(ctx, `
		UPDATE settings
		SET value = '2'
		WHERE name = 'lookupimdb'`); err != nil {
		t.Fatalf("update lookupimdb: %v", err)
	}

	plan, err := BuildDryRunPlan(ctx, db, []Request{{Type: "mov", RenamedOnly: true}})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.BucketEntries != 1 || len(plan.Results) != 1 {
		t.Fatalf("plan = %#v", plan)
	}
	movie := plan.Results[0]
	if movie.Type != "movie" || movie.RenamedMode != 2 {
		t.Fatalf("movie result = %#v", movie)
	}
	if got := bucketRenamedMap(movie.Buckets); !reflect.DeepEqual(got, map[string]int{"n": 2}) {
		t.Fatalf("movie buckets = %#v", got)
	}
}

func TestBuildPostAmazonDryRunPlanSelectsSubtypeBucketsWithoutChangingMariaDB(t *testing.T) {
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
	resetPostprocessTables(t, ctx, db)
	seedPostAmazonRows(t, ctx, db)

	fingerprintBefore := postprocessFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{{Type: "ama"}})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 1 || plan.Types != 4 || plan.BucketEntries != 8 || plan.Writes != 0 {
		t.Fatalf("plan = %#v", plan)
	}
	if len(plan.Results) != 4 {
		t.Fatalf("Results = %#v, want four amazon subtype results", plan.Results)
	}

	want := map[string]map[string]int{
		"books":   {"B": 0, "q": 0},
		"music":   {"M": 0, "N": 0},
		"console": {"C": 0, "D": 0},
		"games":   {"G": 0, "H": 0},
	}
	for _, result := range plan.Results {
		if result.MaxProcesses != 4 || result.RenamedMode != 0 || result.Pipeline {
			t.Fatalf("amazon result = %#v", result)
		}
		got := bucketRenamedMap(result.Buckets)
		if !reflect.DeepEqual(got, want[result.Type]) {
			t.Fatalf("%s buckets = %#v, want %#v", result.Type, got, want[result.Type])
		}
	}

	summary := DryRunSummary(plan)
	for _, want := range []string{
		"postprocess mysql dry-run",
		"commands=1",
		"types=4",
		"bucket-entries=8",
		"books-buckets=2",
		"music-buckets=2",
		"console-buckets=2",
		"games-buckets=2",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("summary = %q, missing %q", summary, want)
		}
	}

	if fingerprintAfter := postprocessFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildPostAmazonDryRunPlanHonorsLookupSettings(t *testing.T) {
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
	resetPostprocessTables(t, ctx, db)
	seedPostAmazonRows(t, ctx, db)

	if _, err := db.ExecContext(ctx, `
		UPDATE settings
		SET value = CASE name
			WHEN 'lookupbooks' THEN '0'
			WHEN 'lookupmusic' THEN '0'
			WHEN 'lookupgames' THEN '2'
			ELSE value
		END
		WHERE name IN ('lookupbooks', 'lookupmusic', 'lookupgames')`); err != nil {
		t.Fatalf("update settings: %v", err)
	}

	plan, err := BuildDryRunPlan(ctx, db, []Request{{Type: "ama"}})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Types != 4 || plan.BucketEntries != 2 {
		t.Fatalf("plan = %#v", plan)
	}
	want := map[string]map[string]int{
		"books":   {},
		"music":   {},
		"console": {"D": 0},
		"games":   {"H": 0},
	}
	for _, result := range plan.Results {
		got := bucketRenamedMap(result.Buckets)
		if !reflect.DeepEqual(got, want[result.Type]) {
			t.Fatalf("%s buckets = %#v, want %#v", result.Type, got, want[result.Type])
		}
	}
}

func TestBuildPostAdditionalDryRunPlanSelectsAdditionalAndNFOBucketsWithoutChangingMariaDB(t *testing.T) {
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
	resetPostprocessTables(t, ctx, db)
	seedPostAdditionalRows(t, ctx, db)

	fingerprintBefore := postprocessFingerprint(t, ctx, db)

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "add"},
		{Type: "nfo"},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Commands != 2 || plan.Types != 2 || plan.BucketEntries != 4 || plan.Writes != 0 {
		t.Fatalf("plan = %#v", plan)
	}
	if len(plan.Results) != 2 {
		t.Fatalf("Results = %#v, want two type results", plan.Results)
	}

	additional := plan.Results[0]
	if additional.Type != "additional" || additional.BucketEntries != 2 || additional.MaxProcesses != 5 || additional.RenamedMode != 0 || additional.Pipeline {
		t.Fatalf("additional result = %#v", additional)
	}
	if got := bucketRenamedMap(additional.Buckets); !reflect.DeepEqual(got, map[string]int{"a": 0, "B": 0}) {
		t.Fatalf("additional buckets = %#v", got)
	}

	nfo := plan.Results[1]
	if nfo.Type != "nfo" || nfo.BucketEntries != 2 || nfo.MaxProcesses != 2 || nfo.RenamedMode != 0 || nfo.Pipeline {
		t.Fatalf("nfo result = %#v", nfo)
	}
	if got := bucketRenamedMap(nfo.Buckets); !reflect.DeepEqual(got, map[string]int{"N": 0, "o": 0}) {
		t.Fatalf("nfo buckets = %#v", got)
	}

	summary := DryRunSummary(plan)
	for _, want := range []string{
		"postprocess mysql dry-run",
		"commands=2",
		"types=2",
		"bucket-entries=4",
		"additional-buckets=2",
		"additional-max-processes=5",
		"nfo-buckets=2",
		"nfo-max-processes=2",
		"writes=0",
	} {
		if !strings.Contains(summary, want) {
			t.Fatalf("summary = %q, missing %q", summary, want)
		}
	}

	if fingerprintAfter := postprocessFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("dry-run changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestBuildPostAdditionalDryRunPlanHonorsLookupAndSizeSettings(t *testing.T) {
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
	resetPostprocessTables(t, ctx, db)
	seedPostAdditionalRows(t, ctx, db)

	if _, err := db.ExecContext(ctx, `
		UPDATE settings
		SET value = CASE name
			WHEN 'minsizetopostprocess' THEN '20'
			WHEN 'maxsizetopostprocess' THEN '0'
			WHEN 'lookupnfo' THEN '0'
			ELSE value
		END
		WHERE name IN ('minsizetopostprocess', 'maxsizetopostprocess', 'lookupnfo')`); err != nil {
		t.Fatalf("update settings: %v", err)
	}

	plan, err := BuildDryRunPlan(ctx, db, []Request{
		{Type: "add"},
		{Type: "nfo"},
	})
	if err != nil {
		t.Fatalf("BuildDryRunPlan: %v", err)
	}

	if plan.Types != 2 || plan.BucketEntries != 1 {
		t.Fatalf("plan = %#v", plan)
	}
	want := map[string]map[string]int{
		"additional": {"B": 0},
		"nfo":        {},
	}
	for _, result := range plan.Results {
		got := bucketRenamedMap(result.Buckets)
		if !reflect.DeepEqual(got, want[result.Type]) {
			t.Fatalf("%s buckets = %#v, want %#v", result.Type, got, want[result.Type])
		}
	}
}

func TestRehearsePostprocessWritesRollsBackRepresentativeBucketUpdates(t *testing.T) {
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
	resetPostprocessTables(t, ctx, db)
	seedPostprocessWriteRehearsalRows(t, ctx, db)

	plan := Plan{
		Commands:      4,
		Types:         9,
		BucketEntries: 9,
		Results: []TypeResult{
			{Type: "additional", BucketEntries: 1, Buckets: []Bucket{{Type: "additional", Bucket: "a"}}},
			{Type: "nfo", BucketEntries: 1, Buckets: []Bucket{{Type: "nfo", Bucket: "n"}}},
			{Type: "movie", BucketEntries: 1, Buckets: []Bucket{{Type: "movie", Bucket: "m", Renamed: 1}}},
			{Type: "tv", BucketEntries: 1, Buckets: []Bucket{{Type: "tv", Bucket: "t", Renamed: 1}}},
			{Type: "anime", BucketEntries: 1, Buckets: []Bucket{{Type: "anime", Bucket: "z"}}},
			{Type: "books", BucketEntries: 1, Buckets: []Bucket{{Type: "books", Bucket: "b"}}},
			{Type: "music", BucketEntries: 1, Buckets: []Bucket{{Type: "music", Bucket: "u"}}},
			{Type: "console", BucketEntries: 1, Buckets: []Bucket{{Type: "console", Bucket: "c"}}},
			{Type: "games", BucketEntries: 1, Buckets: []Bucket{{Type: "games", Bucket: "g"}}},
		},
	}
	fingerprintBefore := postprocessFingerprint(t, ctx, db)

	rehearsal, err := RehearsePostprocessWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("RehearsePostprocessWrites: %v", err)
	}

	if rehearsal.BucketEntries != 9 {
		t.Fatalf("BucketEntries = %d, want 9", rehearsal.BucketEntries)
	}
	if rehearsal.BucketUpdatesAttempted != 9 {
		t.Fatalf("BucketUpdatesAttempted = %d, want 9", rehearsal.BucketUpdatesAttempted)
	}
	if rehearsal.ReleaseRowsAffected != 9 {
		t.Fatalf("ReleaseRowsAffected = %d, want 9", rehearsal.ReleaseRowsAffected)
	}
	if !rehearsal.RolledBack {
		t.Fatal("RolledBack = false, want true")
	}
	if rehearsal.WritesCommitted != 0 {
		t.Fatalf("WritesCommitted = %d, want 0", rehearsal.WritesCommitted)
	}

	if fingerprintAfter := postprocessFingerprint(t, ctx, db); !reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write rehearsal changed table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitPostprocessWritesCommitsRepresentativeBucketUpdates(t *testing.T) {
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
	resetPostprocessTables(t, ctx, db)
	seedPostprocessWriteRehearsalRows(t, ctx, db)

	plan := Plan{
		Commands:      4,
		Types:         9,
		BucketEntries: 9,
		Results: []TypeResult{
			{Type: "additional", BucketEntries: 1, Buckets: []Bucket{{Type: "additional", Bucket: "a"}}},
			{Type: "nfo", BucketEntries: 1, Buckets: []Bucket{{Type: "nfo", Bucket: "n"}}},
			{Type: "movie", BucketEntries: 1, Buckets: []Bucket{{Type: "movie", Bucket: "m", Renamed: 1}}},
			{Type: "tv", BucketEntries: 1, Buckets: []Bucket{{Type: "tv", Bucket: "t", Renamed: 1}}},
			{Type: "anime", BucketEntries: 1, Buckets: []Bucket{{Type: "anime", Bucket: "z"}}},
			{Type: "books", BucketEntries: 1, Buckets: []Bucket{{Type: "books", Bucket: "b"}}},
			{Type: "music", BucketEntries: 1, Buckets: []Bucket{{Type: "music", Bucket: "u"}}},
			{Type: "console", BucketEntries: 1, Buckets: []Bucket{{Type: "console", Bucket: "c"}}},
			{Type: "games", BucketEntries: 1, Buckets: []Bucket{{Type: "games", Bucket: "g"}}},
		},
	}
	fingerprintBefore := postprocessFingerprint(t, ctx, db)

	commit, err := CommitPostprocessWrites(ctx, db, plan)
	if err != nil {
		t.Fatalf("CommitPostprocessWrites: %v", err)
	}

	if commit.BucketEntries != 9 {
		t.Fatalf("BucketEntries = %d, want 9", commit.BucketEntries)
	}
	if commit.BucketUpdatesAttempted != 9 {
		t.Fatalf("BucketUpdatesAttempted = %d, want 9", commit.BucketUpdatesAttempted)
	}
	if commit.ReleaseRowsAffected != 9 {
		t.Fatalf("ReleaseRowsAffected = %d, want 9", commit.ReleaseRowsAffected)
	}
	if commit.RolledBack {
		t.Fatal("RolledBack = true, want committed writes")
	}
	if commit.WritesCommitted != 9 {
		t.Fatalf("WritesCommitted = %d, want 9", commit.WritesCommitted)
	}
	if want := []int64{1000, 1001, 1002, 1003, 1004, 1005, 1006, 1007, 1008}; !reflect.DeepEqual(commit.CommittedReleaseIDs, want) {
		t.Fatalf("CommittedReleaseIDs = %#v, want %#v", commit.CommittedReleaseIDs, want)
	}
	var anidbID, musicInfoID, consoleInfoID int
	if err := db.QueryRowContext(ctx, "SELECT anidbid FROM releases WHERE id = 1004").Scan(&anidbID); err != nil {
		t.Fatalf("select committed anime sentinel: %v", err)
	}
	if err := db.QueryRowContext(ctx, "SELECT musicinfo_id FROM releases WHERE id = 1006").Scan(&musicInfoID); err != nil {
		t.Fatalf("select committed music sentinel: %v", err)
	}
	if err := db.QueryRowContext(ctx, "SELECT consoleinfo_id FROM releases WHERE id = 1007").Scan(&consoleInfoID); err != nil {
		t.Fatalf("select committed console sentinel: %v", err)
	}
	if anidbID != -2 || musicInfoID != -2 || consoleInfoID != -2 {
		t.Fatalf("postprocess sentinels = anime:%d music:%d console:%d, want -2/-2/-2", anidbID, musicInfoID, consoleInfoID)
	}

	if fingerprintAfter := postprocessFingerprint(t, ctx, db); reflect.DeepEqual(fingerprintAfter, fingerprintBefore) {
		t.Fatalf("write commit did not change table data: before=%v after=%v", fingerprintBefore, fingerprintAfter)
	}
}

func TestCommitPostprocessWritesReportsEmptyCommittedReleaseIDsArray(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	testdb.RequireSafeMySQL(t, ctx, db, dsn)

	commit, err := CommitPostprocessWrites(ctx, db, Plan{})
	if err != nil {
		t.Fatalf("CommitPostprocessWrites: %v", err)
	}
	if commit.CommittedReleaseIDs == nil {
		t.Fatalf("CommittedReleaseIDs = nil, want empty slice")
	}

	encoded, err := json.Marshal(commit)
	if err != nil {
		t.Fatalf("marshal commit: %v", err)
	}
	if !strings.Contains(string(encoded), `"committed_release_ids":[]`) {
		t.Fatalf("commit JSON = %s, want empty committed_release_ids array", encoded)
	}
}

func TestPostprocessPlanJSONDoesNotExposeBucketsOrReleaseDetails(t *testing.T) {
	t.Parallel()

	plan := Plan{
		Commands:      1,
		Types:         1,
		BucketEntries: 1,
		Results: []TypeResult{{
			Type:          "tv",
			BucketEntries: 1,
			MaxProcesses:  3,
			RenamedMode:   1,
			Pipeline:      true,
			Buckets: []Bucket{{
				Type:    "tv",
				Bucket:  "Z",
				Renamed: 1,
				Command: "postprocess:tv-pipeline Z 1 --mode=pipeline",
			}},
		}},
		Writes: 0,
	}

	encoded, err := json.Marshal(plan)
	if err != nil {
		t.Fatalf("marshal Plan: %v", err)
	}

	for _, forbidden := range []string{"buckets", "Z", "postprocess:tv-pipeline", "leftguid", "release-id", "Movie.Release", "Book.Release"} {
		if strings.Contains(string(encoded), forbidden) {
			t.Fatalf("Plan JSON exposed %q: %s", forbidden, encoded)
		}
	}
}

func TestBuildDryRunPlanRejectsUnsupportedTypes(t *testing.T) {
	t.Parallel()

	_, err := BuildDryRunPlan(context.Background(), nil, []Request{{Type: "unknown"}})
	if err == nil {
		t.Fatal("BuildDryRunPlan succeeded for unsupported type")
	}
	if !strings.Contains(err.Error(), `unsupported postprocess type "unknown"`) {
		t.Fatalf("error = %v, want unsupported type", err)
	}
}

func resetPostprocessTables(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		"DROP TABLE IF EXISTS categories",
		"DROP TABLE IF EXISTS releases",
		"DROP TABLE IF EXISTS settings",
		`CREATE TABLE settings (
			name VARCHAR(255) PRIMARY KEY,
			value TEXT NULL
		)`,
		`CREATE TABLE categories (
			id INT PRIMARY KEY,
			disablepreview TINYINT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			guid VARCHAR(64) NOT NULL DEFAULT '',
			leftguid VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			groups_id INT NOT NULL DEFAULT 0,
			categories_id INT NOT NULL DEFAULT 0,
			passwordstatus INT NOT NULL DEFAULT 0,
			haspreview INT NOT NULL DEFAULT 0,
			nzbstatus INT NOT NULL DEFAULT 1,
			nfostatus INT NOT NULL DEFAULT 0,
			videos_id INT NOT NULL DEFAULT 0,
			tv_episodes_id INT NOT NULL DEFAULT 0,
			size BIGINT NOT NULL DEFAULT 0,
			isrenamed TINYINT NOT NULL DEFAULT 0,
			anidbid INT NULL,
			imdbid VARCHAR(16) NULL,
			movieinfo_id INT NULL,
			bookinfo_id INT NULL,
			musicinfo_id INT NULL,
			consoleinfo_id INT NULL,
			gamesinfo_id INT NULL DEFAULT 0
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec %q: %v", statement, err)
		}
	}
}

func seedPostTVRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES
			('lookuptv', '1'),
			('lookupanidb', '1'),
			('postthreadsnon', '3')`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, categories_id, videos_id, tv_episodes_id, size, isrenamed, anidbid) VALUES
			(100, 'guid-tv-a', 'A-tv-eligible-1', 'TV.Release.A', 'TV.Release.A', 5000, 0, -1, 2097152, 0, NULL),
			(101, 'guid-tv-b', 'b-tv-eligible-2', 'TV.Release.B', 'TV.Release.B', 5020, 0, 0, 3145728, 1, NULL),
			(102, 'guid-tv-a-duplicate', 'A-tv-eligible-duplicate', 'TV.Release.A2', 'TV.Release.A2', 5030, 0, -2, 4194304, 0, NULL),
			(103, 'guid-tv-anime-category', 'x-tv-anime-category', 'TV.Anime.Category', 'TV.Anime.Category', 5070, 0, -1, 2097152, 1, 99999),
			(104, 'guid-tv-has-video', 'y-tv-has-video', 'TV.Has.Video', 'TV.Has.Video', 5000, 7, -1, 2097152, 1, NULL),
			(105, 'guid-tv-too-small', 'z-tv-too-small', 'TV.Too.Small', 'TV.Too.Small', 5000, 0, -1, 1048576, 1, NULL),
			(106, 'guid-tv-episode-linked', 'w-tv-episode-linked', 'TV.Episode.Linked', 'TV.Episode.Linked', 5000, 0, 1, 2097152, 1, NULL),
			(107, 'guid-tv-wrong-category', 'v-tv-wrong-category', 'TV.Wrong.Category', 'TV.Wrong.Category', 6000, 0, -1, 2097152, 1, NULL),
			(200, 'guid-anime-c', 'c-anime-eligible', 'Anime.Release.C', 'Anime.Release.C', 5070, 0, 0, 2097152, 0, NULL),
			(201, 'guid-anime-known', 'd-anime-known', 'Anime.Release.D', 'Anime.Release.D', 5070, 0, 0, 2097152, 0, 12345)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func seedPostMovieRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES
			('lookupimdb', '1'),
			('postthreadsnon', '3')`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, categories_id, imdbid, movieinfo_id, isrenamed) VALUES
			(300, 'guid-movie-m', 'm-movie-pending', 'Movie.Release.M', 'Movie.Release.M', 2040, NULL, NULL, 0),
			(301, 'guid-movie-n', 'n-movie-repair', 'Movie.Release.N', 'Movie.Release.N', 2080, '1234567', 0, 1),
			(302, 'guid-movie-duplicate', 'm-movie-duplicate', 'Movie.Release.M2', 'Movie.Release.M2', 2010, '00000000', NULL, 0),
			(303, 'guid-movie-empty-imdb', 'x-movie-empty-imdb', 'Movie.Release.Empty', 'Movie.Release.Empty', 2040, '', NULL, 1),
			(304, 'guid-movie-linked', 'y-movie-linked', 'Movie.Release.Linked', 'Movie.Release.Linked', 2040, '7654321', 55, 1),
			(305, 'guid-movie-wrong-category', 'z-movie-wrong-category', 'Movie.Release.Wrong', 'Movie.Release.Wrong', 3000, NULL, NULL, 1)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func seedPostAmazonRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES
			('lookupbooks', '1'),
			('lookupmusic', '1'),
			('lookupgames', '1'),
			('postthreadsamazon', '4')`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, categories_id, bookinfo_id, musicinfo_id, consoleinfo_id, gamesinfo_id, isrenamed) VALUES
			(400, 'guid-book-b', 'B-book-eligible', 'Book.Release.B', 'Book.Release.B', 7010, NULL, NULL, NULL, 0, 0),
			(401, 'guid-book-q', 'q-book-nzb', 'N:/NZB/book-q', 'N:/NZB/book-q', 3030, 77, NULL, NULL, 0, 0),
			(402, 'guid-book-linked', 'r-book-linked', 'Book.Release.Linked', 'Book.Release.Linked', 7020, 77, NULL, NULL, 0, 0),
			(410, 'guid-music-m', 'M-music-eligible', 'Music.Release.M', 'Music.Release.M', 3010, NULL, NULL, NULL, 0, 0),
			(411, 'guid-music-n', 'N-music-eligible', 'Music.Release.N', 'Music.Release.N', 3040, NULL, NULL, NULL, 0, 0),
			(412, 'guid-music-video', 'o-music-video', 'Music.Release.Video', 'Music.Release.Video', 3020, NULL, NULL, NULL, 0, 0),
			(413, 'guid-music-linked', 'p-music-linked', 'Music.Release.Linked', 'Music.Release.Linked', 3010, NULL, 9001, NULL, 0, 0),
			(420, 'guid-console-c', 'C-console-eligible', 'Console.Release.C', 'Console.Release.C', 1010, NULL, NULL, NULL, 0, 0),
			(421, 'guid-console-d', 'D-console-renamed', 'Console.Release.D', 'Console.Release.D', 1180, NULL, NULL, NULL, 0, 1),
			(422, 'guid-console-linked', 'e-console-linked', 'Console.Release.Linked', 'Console.Release.Linked', 1080, NULL, NULL, 9101, 0, 1),
			(430, 'guid-game-g', 'G-game-eligible', 'Game.Release.G', 'Game.Release.G', 4050, NULL, NULL, NULL, 0, 0),
			(431, 'guid-game-h', 'H-game-renamed', 'Game.Release.H', 'Game.Release.H', 4050, NULL, NULL, NULL, 0, 1),
			(432, 'guid-game-linked', 'i-game-linked', 'Game.Release.Linked', 'Game.Release.Linked', 4050, NULL, NULL, NULL, 44, 1),
			(433, 'guid-game-null-info', 'j-game-null-info', 'Game.Release.NullInfo', 'Game.Release.NullInfo', 4050, NULL, NULL, NULL, NULL, 1)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func seedPostAdditionalRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO settings (name, value) VALUES
			('postthreads', '5'),
			('nfothreads', '2'),
			('lookupnfo', '1'),
			('minsizetopostprocess', '1'),
			('maxsizetopostprocess', '100'),
			('minsizetoprocessnfo', '1'),
			('maxsizetoprocessnfo', '2'),
			('maxnforetries', '7')`,
		`INSERT INTO categories (id, disablepreview) VALUES
			(2000, 0),
			(3000, 1)`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, groups_id, categories_id, passwordstatus, haspreview, nzbstatus, nfostatus, size) VALUES
			(600, 'guid-add-a', 'a-add-eligible', 'Additional.Release.A', 'Additional.Release.A', 1, 2000, -1, -1, 1, 0, 2097152),
			(601, 'guid-add-b', 'B-add-eligible-large', 'Additional.Release.B', 'Additional.Release.B', 1, 2000, -1, -1, 1, 0, 31457280),
			(602, 'guid-add-a-duplicate', 'a-add-duplicate', 'Additional.Release.A2', 'Additional.Release.A2', 1, 2000, -1, -1, 1, 0, 3145728),
			(607, 'guid-add-blank-leftguid', '', 'Additional.Release.Blank', 'Additional.Release.Blank', 1, 2000, -1, -1, 1, 0, 4194304),
			(603, 'guid-add-too-small', 'c-add-too-small', 'Additional.Release.Small', 'Additional.Release.Small', 1, 2000, -1, -1, 1, 0, 1048576),
			(604, 'guid-add-preview-disabled', 'd-add-preview-disabled', 'Additional.Release.Disabled', 'Additional.Release.Disabled', 1, 3000, -1, -1, 1, 0, 4194304),
			(605, 'guid-add-already-previewed', 'e-add-previewed', 'Additional.Release.Previewed', 'Additional.Release.Previewed', 1, 2000, -1, 0, 1, 0, 4194304),
			(606, 'guid-add-missing-nzb', 'f-add-missing-nzb', 'Additional.Release.NoNZB', 'Additional.Release.NoNZB', 1, 2000, -1, -1, 0, 0, 4194304),
			(700, 'guid-nfo-n', 'N-nfo-eligible', 'NFO.Release.N', 'NFO.Release.N', 1, 2000, 0, 0, 1, -1, 2097152),
			(701, 'guid-nfo-o', 'o-nfo-retry', 'NFO.Release.O', 'NFO.Release.O', 1, 2000, 0, 0, 1, -8, 3145728),
			(702, 'guid-nfo-n-duplicate', 'N-nfo-duplicate', 'NFO.Release.N2', 'NFO.Release.N2', 1, 2000, 0, 0, 1, -2, 4194304),
			(703, 'guid-nfo-exhausted', 'p-nfo-exhausted', 'NFO.Release.Exhausted', 'NFO.Release.Exhausted', 1, 2000, 0, 0, 1, -9, 4194304),
			(704, 'guid-nfo-too-small', 'q-nfo-too-small', 'NFO.Release.Small', 'NFO.Release.Small', 1, 2000, 0, 0, 1, -1, 1048576),
			(705, 'guid-nfo-too-large', 'r-nfo-too-large', 'NFO.Release.Large', 'NFO.Release.Large', 1, 2000, 0, 0, 1, -1, 2147483648)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func seedPostprocessWriteRehearsalRows(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()

	statements := []string{
		`INSERT INTO categories (id, disablepreview) VALUES
			(2000, 0)`,
		`INSERT INTO releases (
			id, guid, leftguid, name, searchname, groups_id, categories_id,
			passwordstatus, haspreview, nzbstatus, nfostatus, videos_id,
			tv_episodes_id, size, isrenamed, anidbid, imdbid, movieinfo_id,
			bookinfo_id, musicinfo_id, consoleinfo_id, gamesinfo_id
		) VALUES
			(1000, 'guid-add', 'a-add-rehearsal', 'Additional.Rehearsal', 'Additional.Rehearsal', 1, 2000, -1, -1, 1, 0, 0, 0, 2097152, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0),
			(1001, 'guid-nfo', 'n-nfo-rehearsal', 'NFO.Rehearsal', 'NFO.Rehearsal', 1, 2000, 0, 0, 1, -1, 0, 0, 2097152, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0),
			(1002, 'guid-movie', 'm-movie-rehearsal', 'Movie.Rehearsal', 'Movie.Rehearsal', 1, 2040, 0, 0, 1, 0, 0, 0, 2097152, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0),
			(1003, 'guid-tv', 't-tv-rehearsal', 'TV.Rehearsal', 'TV.Rehearsal', 1, 5000, 0, 0, 1, 0, 0, -1, 2097152, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0),
			(1004, 'guid-anime', 'z-anime-rehearsal', 'Anime.Rehearsal', 'Anime.Rehearsal', 1, 5070, 0, 0, 1, 0, 0, 0, 2097152, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0),
			(1005, 'guid-book', 'b-book-rehearsal', 'Book.Rehearsal', 'Book.Rehearsal', 1, 7010, 0, 0, 1, 0, 0, 0, 2097152, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0),
			(1006, 'guid-music', 'u-music-rehearsal', 'Music.Rehearsal', 'Music.Rehearsal', 1, 3010, 0, 0, 1, 0, 0, 0, 2097152, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0),
			(1007, 'guid-console', 'c-console-rehearsal', 'Console.Rehearsal', 'Console.Rehearsal', 1, 1010, 0, 0, 1, 0, 0, 0, 2097152, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0),
			(1008, 'guid-game', 'g-game-rehearsal', 'Game.Rehearsal', 'Game.Rehearsal', 1, 4050, 0, 0, 1, 0, 0, 0, 2097152, 0, NULL, NULL, NULL, NULL, NULL, NULL, 0)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			t.Fatalf("exec seed %q: %v", statement, err)
		}
	}
}

func postprocessFingerprint(t *testing.T, ctx context.Context, db *sql.DB) map[string]string {
	t.Helper()

	queries := map[string]string{
		"settings":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', name, COALESCE(value, '')) ORDER BY name SEPARATOR '|'), '') FROM settings",
		"categories": "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, disablepreview) ORDER BY id SEPARATOR '|'), '') FROM categories",
		"releases":   "SELECT COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, guid, leftguid, name, searchname, groups_id, categories_id, passwordstatus, haspreview, nzbstatus, nfostatus, videos_id, tv_episodes_id, size, isrenamed, COALESCE(anidbid, ''), COALESCE(imdbid, ''), COALESCE(movieinfo_id, ''), COALESCE(bookinfo_id, ''), COALESCE(musicinfo_id, ''), COALESCE(consoleinfo_id, ''), COALESCE(gamesinfo_id, '')) ORDER BY id SEPARATOR '|'), '') FROM releases",
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

func bucketRenamedMap(buckets []Bucket) map[string]int {
	result := map[string]int{}
	for _, bucket := range buckets {
		result[bucket.Bucket] = bucket.Renamed
	}

	return result
}
