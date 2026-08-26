package postprocess

import (
	"bytes"
	"context"
	"database/sql"
	"fmt"
	"strconv"
	"strings"
)

const (
	categoryGameRoot       = 1000
	categoryGameOther      = 1999
	categoryMovieRoot      = 2000
	categoryMovieOther     = 2999
	categoryMusicMP3       = 3010
	categoryMusicAudiobook = 3030
	categoryMusicLossless  = 3040
	categoryMusicOther     = 3999
	categoryPCGames        = 4050
	categoryBooksRoot      = 7000
	categoryBooksUnknown   = 7999
)

type Request struct {
	Type        string
	RenamedOnly bool
}

type Bucket struct {
	Type    string
	Bucket  string
	Renamed int
	Command string
}

type TypeResult struct {
	Type          string   `json:"type"`
	BucketEntries int      `json:"bucket_entries"`
	MaxProcesses  int      `json:"max_processes"`
	RenamedMode   int      `json:"renamed_mode"`
	Pipeline      bool     `json:"pipeline"`
	Buckets       []Bucket `json:"-"`
}

type Plan struct {
	Commands      int          `json:"commands"`
	Types         int          `json:"types"`
	BucketEntries int          `json:"bucket_entries"`
	Results       []TypeResult `json:"results"`
	Writes        int          `json:"writes"`
}

func BuildDryRunPlan(ctx context.Context, db *sql.DB, requests []Request) (Plan, error) {
	plan := Plan{
		Commands: len(requests),
		Results:  []TypeResult{},
		Writes:   0,
	}

	for _, request := range requests {
		results, err := buildTypeResults(ctx, db, request)
		if err != nil {
			return Plan{}, err
		}

		for _, result := range results {
			plan.Results = append(plan.Results, result)
			plan.BucketEntries += result.BucketEntries
		}
	}
	plan.Types = len(plan.Results)

	return plan, nil
}

func DryRunSummary(plan Plan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "postprocess mysql dry-run")
	fmt.Fprintf(&buffer, "commands=%d\n", plan.Commands)
	fmt.Fprintf(&buffer, "types=%d\n", plan.Types)
	fmt.Fprintf(&buffer, "bucket-entries=%d\n", plan.BucketEntries)
	for _, result := range plan.Results {
		fmt.Fprintf(&buffer, "%s-buckets=%d\n", result.Type, result.BucketEntries)
		fmt.Fprintf(&buffer, "%s-max-processes=%d\n", result.Type, result.MaxProcesses)
		fmt.Fprintf(&buffer, "%s-renamed-mode=%d\n", result.Type, result.RenamedMode)
		fmt.Fprintf(&buffer, "%s-pipeline=%t\n", result.Type, result.Pipeline)
	}
	fmt.Fprintf(&buffer, "writes=%d\n", plan.Writes)

	return buffer.String()
}

func buildTypeResults(ctx context.Context, db *sql.DB, request Request) ([]TypeResult, error) {
	switch normalizeType(request.Type) {
	case "additional":
		result, err := buildAdditionalResult(ctx, db)
		return singleResult(result, err)
	case "nfo":
		result, err := buildNFOResult(ctx, db)
		return singleResult(result, err)
	case "movie":
		result, err := buildMovieResult(ctx, db, request)
		return singleResult(result, err)
	case "tv":
		result, err := buildTVResult(ctx, db, request)
		return singleResult(result, err)
	case "anime":
		result, err := buildAnimeResult(ctx, db)
		return singleResult(result, err)
	case "amazon":
		return buildAmazonResults(ctx, db)
	case "books":
		result, err := buildBooksResult(ctx, db)
		return singleResult(result, err)
	case "music":
		result, err := buildMusicResult(ctx, db)
		return singleResult(result, err)
	case "console":
		result, err := buildConsoleResult(ctx, db)
		return singleResult(result, err)
	case "games":
		result, err := buildGamesResult(ctx, db)
		return singleResult(result, err)
	default:
		return nil, fmt.Errorf("unsupported postprocess type %q in native dry-run planner", strings.TrimSpace(request.Type))
	}
}

func buildAdditionalResult(ctx context.Context, db *sql.DB) (TypeResult, error) {
	maxProcesses, err := settingInt(ctx, db, "postthreads")
	if err != nil {
		return TypeResult{}, err
	}

	result := TypeResult{
		Type:         "additional",
		MaxProcesses: maxProcesses,
		Buckets:      []Bucket{},
	}

	minSizeMB, err := settingIntDefaultWhenEmpty(ctx, db, "minsizetopostprocess", 1)
	if err != nil {
		return TypeResult{}, err
	}
	maxSizeGB, err := settingIntDefaultWhenEmpty(ctx, db, "maxsizetopostprocess", 100)
	if err != nil {
		return TypeResult{}, err
	}

	sizeFilter := ""
	args := []any{}
	if minSizeMB > 0 {
		sizeFilter += "AND r.size > ?\n"
		args = append(args, int64(minSizeMB)*1048576)
	}
	if maxSizeGB > 0 {
		sizeFilter += "AND r.size < ?\n"
		args = append(args, int64(maxSizeGB)*1073741824)
	}

	query := `
		SELECT DISTINCT LEFT(r.leftguid, 1) AS bucket
		FROM releases r
		LEFT JOIN categories c ON c.id = r.categories_id
		WHERE r.passwordstatus = -1
			AND r.haspreview = -1
			AND r.nzbstatus = 1
			AND c.disablepreview = 0
			` + sizeFilter + `
		LIMIT 16`
	buckets, err := selectBuckets(ctx, db, query, postprocessGUIDBucket("additional"), args...)
	if err != nil {
		return TypeResult{}, fmt.Errorf("select additional postprocess buckets: %w", err)
	}

	result.Buckets = buckets
	result.BucketEntries = len(buckets)

	return result, nil
}

func buildNFOResult(ctx context.Context, db *sql.DB) (TypeResult, error) {
	maxProcesses, err := settingInt(ctx, db, "nfothreads")
	if err != nil {
		return TypeResult{}, err
	}

	result := TypeResult{
		Type:         "nfo",
		MaxProcesses: maxProcesses,
		Buckets:      []Bucket{},
	}

	lookupNFO, err := settingInt(ctx, db, "lookupnfo")
	if err != nil {
		return TypeResult{}, err
	}
	if lookupNFO != 1 {
		return result, nil
	}

	maxNFOrRetries, err := settingInt(ctx, db, "maxnforetries")
	if err != nil {
		return TypeResult{}, err
	}
	lowerRetryBound := -1
	if maxNFOrRetries >= 0 {
		lowerRetryBound = -(maxNFOrRetries + 1)
	}
	if lowerRetryBound < -8 {
		lowerRetryBound = -8
	}

	maxSizeGB, err := settingInt(ctx, db, "maxsizetoprocessnfo")
	if err != nil {
		return TypeResult{}, err
	}
	minSizeMB, err := settingInt(ctx, db, "minsizetoprocessnfo")
	if err != nil {
		return TypeResult{}, err
	}

	sizeFilter := ""
	args := []any{lowerRetryBound, -1}
	if maxSizeGB > 0 {
		sizeFilter += "AND r.size < ?\n"
		args = append(args, int64(maxSizeGB)*1073741824)
	}
	if minSizeMB > 0 {
		sizeFilter += "AND r.size > ?\n"
		args = append(args, int64(minSizeMB)*1048576)
	}

	query := `
		SELECT DISTINCT LEFT(r.leftguid, 1) AS bucket
		FROM releases r
		WHERE r.nfostatus BETWEEN ? AND ?
			` + sizeFilter + `
		LIMIT 16`
	buckets, err := selectBuckets(ctx, db, query, postprocessGUIDBucket("nfo"), args...)
	if err != nil {
		return TypeResult{}, fmt.Errorf("select nfo postprocess buckets: %w", err)
	}

	result.Buckets = buckets
	result.BucketEntries = len(buckets)

	return result, nil
}

func buildMovieResult(ctx context.Context, db *sql.DB, request Request) (TypeResult, error) {
	maxProcesses, err := settingInt(ctx, db, "postthreadsnon")
	if err != nil {
		return TypeResult{}, err
	}

	renamedMode := 1
	if request.RenamedOnly {
		renamedMode = 2
	}

	result := TypeResult{
		Type:         "movie",
		MaxProcesses: maxProcesses,
		RenamedMode:  renamedMode,
		Buckets:      []Bucket{},
	}

	lookupIMDB, err := settingInt(ctx, db, "lookupimdb")
	if err != nil {
		return TypeResult{}, err
	}
	if lookupIMDB <= 0 {
		return result, nil
	}

	renamedFilter := ""
	if lookupIMDB == 2 || request.RenamedOnly {
		renamedFilter = "AND isrenamed = 1"
	}

	query := `
		SELECT DISTINCT LEFT(leftguid, 1) AS bucket
		FROM releases
		WHERE categories_id BETWEEN ? AND ?
			AND (
				` + imdbIDNeedsLookupSQL("imdbid") + `
				OR ` + movieInfoNeedsRepairSQL("imdbid", "movieinfo_id") + `
			)
			` + renamedFilter + `
		LIMIT 16`
	buckets, err := selectBuckets(ctx, db, query, func(bucket string) Bucket {
		return Bucket{
			Type:    "movie",
			Bucket:  bucket,
			Renamed: renamedMode,
			Command: fmt.Sprintf("postprocess:guid movie %s", bucket),
		}
	}, categoryMovieRoot, categoryMovieOther)
	if err != nil {
		return TypeResult{}, fmt.Errorf("select movie postprocess buckets: %w", err)
	}

	result.Buckets = buckets
	result.BucketEntries = len(buckets)

	return result, nil
}

func buildTVResult(ctx context.Context, db *sql.DB, request Request) (TypeResult, error) {
	maxProcesses, err := settingInt(ctx, db, "postthreadsnon")
	if err != nil {
		return TypeResult{}, err
	}

	renamedMode := 1
	if request.RenamedOnly {
		renamedMode = 2
	}

	result := TypeResult{
		Type:         "tv",
		MaxProcesses: maxProcesses,
		RenamedMode:  renamedMode,
		Pipeline:     true,
		Buckets:      []Bucket{},
	}

	lookupTV, err := settingInt(ctx, db, "lookuptv")
	if err != nil {
		return TypeResult{}, err
	}
	if lookupTV <= 0 {
		return result, nil
	}

	renamedFilter := ""
	if lookupTV == 2 || request.RenamedOnly {
		renamedFilter = "AND isrenamed = 1"
	}

	query := `
		SELECT DISTINCT LEFT(leftguid, 1) AS bucket
		FROM releases
		WHERE categories_id BETWEEN ? AND ?
			AND categories_id != ?
			AND videos_id = 0
			AND tv_episodes_id BETWEEN -3 AND 0
			AND size > 1048576
			` + renamedFilter + `
		LIMIT 16`
	buckets, err := selectBuckets(ctx, db, query, func(bucket string) Bucket {
		return Bucket{
			Type:    "tv",
			Bucket:  bucket,
			Renamed: renamedMode,
			Command: fmt.Sprintf("postprocess:tv-pipeline %s %d --mode=pipeline", bucket, renamedMode),
		}
	}, 5000, 5999, 5070)
	if err != nil {
		return TypeResult{}, fmt.Errorf("select tv postprocess buckets: %w", err)
	}

	result.Buckets = buckets
	result.BucketEntries = len(buckets)

	return result, nil
}

func buildAnimeResult(ctx context.Context, db *sql.DB) (TypeResult, error) {
	maxProcesses, err := settingInt(ctx, db, "postthreadsnon")
	if err != nil {
		return TypeResult{}, err
	}

	result := TypeResult{
		Type:         "anime",
		MaxProcesses: maxProcesses,
		Buckets:      []Bucket{},
	}

	lookupAniDB, err := settingInt(ctx, db, "lookupanidb")
	if err != nil {
		return TypeResult{}, err
	}
	if lookupAniDB <= 0 {
		return result, nil
	}

	query := `
		SELECT DISTINCT LEFT(leftguid, 1) AS bucket
		FROM releases
		WHERE categories_id = ?
			AND anidbid IS NULL
		LIMIT 16`
	buckets, err := selectBuckets(ctx, db, query, func(bucket string) Bucket {
		return Bucket{
			Type:    "anime",
			Bucket:  bucket,
			Command: fmt.Sprintf("postprocess:guid anime %s", bucket),
		}
	}, 5070)
	if err != nil {
		return TypeResult{}, fmt.Errorf("select anime postprocess buckets: %w", err)
	}

	result.Buckets = buckets
	result.BucketEntries = len(buckets)

	return result, nil
}

func buildAmazonResults(ctx context.Context, db *sql.DB) ([]TypeResult, error) {
	builders := []func(context.Context, *sql.DB) (TypeResult, error){
		buildBooksResult,
		buildMusicResult,
		buildConsoleResult,
		buildGamesResult,
	}
	results := make([]TypeResult, 0, len(builders))

	for _, builder := range builders {
		result, err := builder(ctx, db)
		if err != nil {
			return nil, err
		}
		results = append(results, result)
	}

	return results, nil
}

func buildBooksResult(ctx context.Context, db *sql.DB) (TypeResult, error) {
	result, err := amazonTypeResult(ctx, db, "books")
	if err != nil {
		return TypeResult{}, err
	}

	lookupBooks, err := settingInt(ctx, db, "lookupbooks")
	if err != nil {
		return TypeResult{}, err
	}
	if lookupBooks <= 0 {
		return result, nil
	}

	query := `
		SELECT DISTINCT LEFT(leftguid, 1) AS bucket
		FROM releases
		WHERE (
				categories_id BETWEEN ? AND ?
				OR categories_id = ?
			)
			AND (
				bookinfo_id IS NULL
				OR searchname LIKE "N:/NZB%"
				OR searchname LIKE "N_NZB_%"
				OR name LIKE "N:/NZB%"
				OR name LIKE "N_NZB_%"
			)
		LIMIT 16`
	buckets, err := selectBuckets(ctx, db, query, postprocessGUIDBucket("books"), categoryBooksRoot, categoryBooksUnknown, categoryMusicAudiobook)
	if err != nil {
		return TypeResult{}, fmt.Errorf("select books postprocess buckets: %w", err)
	}

	result.Buckets = buckets
	result.BucketEntries = len(buckets)

	return result, nil
}

func buildMusicResult(ctx context.Context, db *sql.DB) (TypeResult, error) {
	result, err := amazonTypeResult(ctx, db, "music")
	if err != nil {
		return TypeResult{}, err
	}

	lookupMusic, err := settingInt(ctx, db, "lookupmusic")
	if err != nil {
		return TypeResult{}, err
	}
	if lookupMusic <= 0 {
		return result, nil
	}

	query := `
		SELECT DISTINCT LEFT(leftguid, 1) AS bucket
		FROM releases
		WHERE categories_id IN (?, ?, ?)
			AND musicinfo_id IS NULL
		LIMIT 16`
	buckets, err := selectBuckets(ctx, db, query, postprocessGUIDBucket("music"), categoryMusicMP3, categoryMusicLossless, categoryMusicOther)
	if err != nil {
		return TypeResult{}, fmt.Errorf("select music postprocess buckets: %w", err)
	}

	result.Buckets = buckets
	result.BucketEntries = len(buckets)

	return result, nil
}

func buildConsoleResult(ctx context.Context, db *sql.DB) (TypeResult, error) {
	result, err := amazonTypeResult(ctx, db, "console")
	if err != nil {
		return TypeResult{}, err
	}

	lookupGames, err := settingInt(ctx, db, "lookupgames")
	if err != nil {
		return TypeResult{}, err
	}
	if lookupGames <= 0 {
		return result, nil
	}

	renamedFilter := ""
	if lookupGames == 2 {
		renamedFilter = "AND isrenamed = 1"
	}

	query := `
		SELECT DISTINCT LEFT(leftguid, 1) AS bucket
		FROM releases
		WHERE categories_id BETWEEN ? AND ?
			AND consoleinfo_id IS NULL
			` + renamedFilter + `
		LIMIT 16`
	buckets, err := selectBuckets(ctx, db, query, postprocessGUIDBucket("console"), categoryGameRoot, categoryGameOther)
	if err != nil {
		return TypeResult{}, fmt.Errorf("select console postprocess buckets: %w", err)
	}

	result.Buckets = buckets
	result.BucketEntries = len(buckets)

	return result, nil
}

func buildGamesResult(ctx context.Context, db *sql.DB) (TypeResult, error) {
	result, err := amazonTypeResult(ctx, db, "games")
	if err != nil {
		return TypeResult{}, err
	}

	lookupGames, err := settingInt(ctx, db, "lookupgames")
	if err != nil {
		return TypeResult{}, err
	}
	if lookupGames <= 0 {
		return result, nil
	}

	renamedFilter := ""
	if lookupGames == 2 {
		renamedFilter = "AND isrenamed = 1"
	}

	query := `
		SELECT DISTINCT LEFT(leftguid, 1) AS bucket
		FROM releases
		WHERE categories_id = ?
			AND gamesinfo_id = 0
			` + renamedFilter + `
		LIMIT 16`
	buckets, err := selectBuckets(ctx, db, query, postprocessGUIDBucket("games"), categoryPCGames)
	if err != nil {
		return TypeResult{}, fmt.Errorf("select games postprocess buckets: %w", err)
	}

	result.Buckets = buckets
	result.BucketEntries = len(buckets)

	return result, nil
}

func amazonTypeResult(ctx context.Context, db *sql.DB, postprocessType string) (TypeResult, error) {
	maxProcesses, err := settingInt(ctx, db, "postthreadsamazon")
	if err != nil {
		return TypeResult{}, err
	}

	return TypeResult{
		Type:         postprocessType,
		MaxProcesses: maxProcesses,
		Buckets:      []Bucket{},
	}, nil
}

func selectBuckets(ctx context.Context, db *sql.DB, query string, build func(string) Bucket, args ...any) ([]Bucket, error) {
	rows, err := db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	buckets := []Bucket{}
	for rows.Next() {
		var bucket string
		if err := rows.Scan(&bucket); err != nil {
			return nil, err
		}
		bucket = strings.TrimSpace(bucket)
		if bucket == "" {
			continue
		}
		buckets = append(buckets, build(bucket))
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	return buckets, nil
}

func singleResult(result TypeResult, err error) ([]TypeResult, error) {
	if err != nil {
		return nil, err
	}

	return []TypeResult{result}, nil
}

func postprocessGUIDBucket(postprocessType string) func(string) Bucket {
	return func(bucket string) Bucket {
		return Bucket{
			Type:    postprocessType,
			Bucket:  bucket,
			Command: fmt.Sprintf("postprocess:guid %s %s", postprocessType, bucket),
		}
	}
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

func settingIntDefaultWhenEmpty(ctx context.Context, db *sql.DB, name string, defaultValue int) (int, error) {
	var value sql.NullString
	if err := db.QueryRowContext(ctx, "SELECT value FROM settings WHERE name = ?", name).Scan(&value); err != nil {
		if err == sql.ErrNoRows {
			return defaultValue, nil
		}

		return 0, fmt.Errorf("read setting %s: %w", name, err)
	}
	if !value.Valid || strings.TrimSpace(value.String) == "" {
		return defaultValue, nil
	}

	parsed, err := strconv.Atoi(strings.TrimSpace(value.String))
	if err != nil {
		return 0, nil
	}

	return parsed, nil
}

func normalizeType(value string) string {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "add", "additional":
		return "additional"
	case "nfo":
		return "nfo"
	case "mov", "movie":
		return "movie"
	case "tv":
		return "tv"
	case "ani", "anime":
		return "anime"
	case "ama", "amazon":
		return "amazon"
	case "boo", "book", "books":
		return "books"
	case "mus", "music":
		return "music"
	case "con", "console":
		return "console"
	case "gam", "games":
		return "games"
	default:
		return ""
	}
}

func imdbIDNeedsLookupSQL(column string) string {
	return fmt.Sprintf("(%s IS NULL OR %s IN ('0', '0000000', '00000000'))", column, column)
}

func movieInfoNeedsRepairSQL(imdbColumn string, movieInfoColumn string) string {
	return fmt.Sprintf(
		"(%s IS NOT NULL AND %s <> '' AND %s NOT IN ('0', '0000000', '00000000') AND (%s IS NULL OR %s = 0))",
		imdbColumn,
		imdbColumn,
		imdbColumn,
		movieInfoColumn,
		movieInfoColumn,
	)
}
