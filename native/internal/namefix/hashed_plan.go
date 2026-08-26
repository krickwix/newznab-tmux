package namefix

import (
	"bytes"
	"context"
	"database/sql"
	"fmt"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

const (
	otherMiscCategory   = 10
	otherHashedCategory = 20
	booksUnknown        = 7999
	gameOtherCategory   = 1999
	movieForeign        = 2010
	movieOther          = 2999
	movieSD             = 2030
	movieHD             = 2040
	movieUHD            = 2045
	movie3D             = 2050
	movieBluRay         = 2060
	movieDVD            = 2070
	movieWEBDL          = 2080
	movieX265           = 2090
	musicOther          = 3999
	pcPhoneOther        = 4040
	tvOther             = 5999
	xxxOther            = 6999

	procDone = 1
)

var sampleOrProofPattern = regexp.MustCompile(`(?i)[._-](sample|proof|subs?|thumbs?)[._-]`)

type HashedFixDryRunPlan struct {
	CRCMutations      []ReleaseNameMutation
	CRCStatusOnly     []StatusUpdate
	ParHashMutations  []ReleaseNameMutation
	ParHashStatusOnly []StatusUpdate
}

type RegularFixRequest struct {
	Method   string
	Category string
	Limit    int
}

type WriteContractOptions struct {
	MethodOrder []string
	SetStatus   bool
}

type HashedFixWriteContract struct {
	ReleaseUpdates             []ReleaseUpdateContract         `json:"release_updates"`
	SingleColumnUpdates        []SingleColumnUpdateContract    `json:"single_column_updates"`
	RequiredEvents             []ReleaseNameFixedEventContract `json:"required_events"`
	SearchUpdates              []SearchUpdateContract          `json:"search_updates"`
	CategoryResolutionRequired int                             `json:"category_resolution_required"`
	Writes                     int                             `json:"writes"`
}

type ReleaseNameMutation struct {
	ReleaseID     int64
	OldSearchName string
	NewSearchName string
	PreDBID       int64
	Method        string
	StatusColumn  string
	MatchSource   string
}

type StatusUpdate struct {
	ReleaseID int64
	Column    string
	Value     int
	Reason    string
}

type ReleaseUpdateContract struct {
	ReleaseID   int64           `json:"release_id"`
	Type        string          `json:"type"`
	Method      string          `json:"method"`
	MatchSource string          `json:"match_source"`
	Columns     []PlannedColumn `json:"columns"`
}

type PlannedColumn struct {
	Column      string `json:"column"`
	Value       any    `json:"value"`
	ValueSource string `json:"value_source,omitempty"`
}

type SingleColumnUpdateContract struct {
	ReleaseID int64  `json:"release_id"`
	Column    string `json:"column"`
	Value     int    `json:"value"`
	Reason    string `json:"reason"`
}

type ReleaseNameFixedEventContract struct {
	ReleaseID     int64  `json:"release_id"`
	OldName       string `json:"old_name"`
	NewName       string `json:"new_name"`
	OldCategoryID int    `json:"old_category_id"`
	GroupID       int64  `json:"group_id"`
	Poster        string `json:"poster"`
}

type SearchUpdateContract struct {
	ReleaseID int64  `json:"release_id"`
	Reason    string `json:"reason"`
}

type releaseCandidate struct {
	ID         int64
	SearchName string
	Size       int64
}

type crcFileCandidate struct {
	Name string
	Size int64
	CRC  string
}

type parHashCandidate struct {
	releaseCandidate
	Hash string
}

type releaseMatch struct {
	SearchName string
	Size       int64
	PreDBID    int64
}

type releaseWriteContext struct {
	ID         int64
	SearchName string
	CategoryID int
	GroupID    int64
	FromName   string
}

func BuildHashedFixDryRunPlan(ctx context.Context, db *sql.DB, crcLimit int) (HashedFixDryRunPlan, error) {
	crcMutations, crcStatusOnly, err := buildCRCDryRunPlan(ctx, db, crcDryRunOptions{
		Categories: []int{otherHashedCategory},
		Limit:      crcLimit,
	})
	if err != nil {
		return HashedFixDryRunPlan{}, err
	}

	parHashMutations, parHashStatusOnly, err := buildParHashDryRunPlan(ctx, db, parHashDryRunOptions{
		Categories: []int{otherHashedCategory},
	})
	if err != nil {
		return HashedFixDryRunPlan{}, err
	}

	return HashedFixDryRunPlan{
		CRCMutations:      crcMutations,
		CRCStatusOnly:     crcStatusOnly,
		ParHashMutations:  parHashMutations,
		ParHashStatusOnly: parHashStatusOnly,
	}, nil
}

func BuildRegularFixDryRunPlan(ctx context.Context, db *sql.DB, requests []RegularFixRequest) (HashedFixDryRunPlan, error) {
	plan := HashedFixDryRunPlan{}

	for _, request := range requests {
		categories, err := regularFixCategories(request.Category)
		if err != nil {
			return HashedFixDryRunPlan{}, err
		}

		switch request.Method {
		case "19":
			mutations, statusOnly, err := buildCRCDryRunPlan(ctx, db, crcDryRunOptions{
				Categories: categories,
				RecentOnly: true,
				Limit:      request.Limit,
			})
			if err != nil {
				return HashedFixDryRunPlan{}, err
			}
			plan.CRCMutations = append(plan.CRCMutations, mutations...)
			plan.CRCStatusOnly = append(plan.CRCStatusOnly, statusOnly...)
		case "15":
			mutations, statusOnly, err := buildParHashDryRunPlan(ctx, db, parHashDryRunOptions{
				Categories: categories,
				RecentOnly: true,
				Limit:      request.Limit,
			})
			if err != nil {
				return HashedFixDryRunPlan{}, err
			}
			plan.ParHashMutations = append(plan.ParHashMutations, mutations...)
			plan.ParHashStatusOnly = append(plan.ParHashStatusOnly, statusOnly...)
		default:
			return HashedFixDryRunPlan{}, fmt.Errorf("unsupported regular fix-name native method %q", request.Method)
		}
	}

	return plan, nil
}

func BuildRegularFixWriteContract(ctx context.Context, db *sql.DB, plan HashedFixDryRunPlan, requests []RegularFixRequest, setStatus bool) (HashedFixWriteContract, error) {
	updatedReleases := map[int64]bool{}
	contract := HashedFixWriteContract{}

	for _, request := range requests {
		switch request.Method {
		case "19":
			if err := appendMutationContracts(ctx, db, &contract, plan.CRCMutations, updatedReleases, "CRC32, ", setStatus); err != nil {
				return HashedFixWriteContract{}, err
			}
			appendStatusContracts(&contract, plan.CRCStatusOnly, updatedReleases)
		case "15":
			if err := appendMutationContracts(ctx, db, &contract, plan.ParHashMutations, updatedReleases, "PAR2 hash, ", setStatus); err != nil {
				return HashedFixWriteContract{}, err
			}
			appendStatusContracts(&contract, plan.ParHashStatusOnly, updatedReleases)
		default:
			return HashedFixWriteContract{}, fmt.Errorf("unsupported regular fix-name write-contract method %q", request.Method)
		}
	}

	return contract, nil
}

func DryRunSummary(plan HashedFixDryRunPlan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "hashed-fixnames mysql dry-run")
	fmt.Fprintf(&buffer, "crc-mutations=%d\n", len(plan.CRCMutations))
	fmt.Fprintf(&buffer, "crc-status-only=%d\n", len(plan.CRCStatusOnly))
	fmt.Fprintf(&buffer, "par-hash-mutations=%d\n", len(plan.ParHashMutations))
	fmt.Fprintf(&buffer, "par-hash-status-only=%d\n", len(plan.ParHashStatusOnly))
	fmt.Fprintln(&buffer, "writes=0")

	return buffer.String()
}

func RegularFixDryRunSummary(plan HashedFixDryRunPlan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "fixnames mysql dry-run")
	fmt.Fprintf(&buffer, "crc-mutations=%d\n", len(plan.CRCMutations))
	fmt.Fprintf(&buffer, "crc-status-only=%d\n", len(plan.CRCStatusOnly))
	fmt.Fprintf(&buffer, "par-hash-mutations=%d\n", len(plan.ParHashMutations))
	fmt.Fprintf(&buffer, "par-hash-status-only=%d\n", len(plan.ParHashStatusOnly))
	fmt.Fprintln(&buffer, "writes=0")

	return buffer.String()
}

func BuildHashedFixWriteContract(ctx context.Context, db *sql.DB, plan HashedFixDryRunPlan, options WriteContractOptions) (HashedFixWriteContract, error) {
	methodOrder := normalizeMethodOrder(options.MethodOrder)
	updatedReleases := map[int64]bool{}

	contract := HashedFixWriteContract{}
	for _, method := range methodOrder {
		switch method {
		case "20":
			if err := appendMutationContracts(ctx, db, &contract, plan.CRCMutations, updatedReleases, "CRC32, ", options.SetStatus); err != nil {
				return HashedFixWriteContract{}, err
			}
			appendStatusContracts(&contract, plan.CRCStatusOnly, updatedReleases)
		case "16":
			if err := appendMutationContracts(ctx, db, &contract, plan.ParHashMutations, updatedReleases, "PAR2 hash, ", options.SetStatus); err != nil {
				return HashedFixWriteContract{}, err
			}
			appendStatusContracts(&contract, plan.ParHashStatusOnly, updatedReleases)
		}
	}

	return contract, nil
}

func WriteContractSummary(contract HashedFixWriteContract) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "hashed-fixnames write-contract")
	fmt.Fprintf(&buffer, "planned-release-updates=%d\n", len(contract.ReleaseUpdates))
	fmt.Fprintf(&buffer, "planned-single-column-updates=%d\n", len(contract.SingleColumnUpdates))
	fmt.Fprintf(&buffer, "required-events=%d\n", len(contract.RequiredEvents))
	fmt.Fprintf(&buffer, "required-search-updates=%d\n", len(contract.SearchUpdates))
	fmt.Fprintf(&buffer, "category-resolution-required=%d\n", contract.CategoryResolutionRequired)
	fmt.Fprintln(&buffer, "writes=0")

	return buffer.String()
}

func normalizeMethodOrder(methodOrder []string) []string {
	if len(methodOrder) == 0 {
		return []string{"20", "16"}
	}

	normalized := []string{}
	seen := map[string]bool{}
	for _, method := range methodOrder {
		if method != "20" && method != "16" {
			continue
		}
		if seen[method] {
			continue
		}
		seen[method] = true
		normalized = append(normalized, method)
	}
	if len(normalized) == 0 {
		return []string{"20", "16"}
	}

	return normalized
}

func appendMutationContracts(ctx context.Context, db *sql.DB, contract *HashedFixWriteContract, mutations []ReleaseNameMutation, updatedReleases map[int64]bool, mutationType string, setStatus bool) error {
	for _, mutation := range mutations {
		if updatedReleases[mutation.ReleaseID] {
			continue
		}

		release, err := loadReleaseWriteContext(ctx, db, mutation.ReleaseID)
		if err != nil {
			return err
		}

		contract.ReleaseUpdates = append(contract.ReleaseUpdates, ReleaseUpdateContract{
			ReleaseID:   mutation.ReleaseID,
			Type:        mutationType,
			Method:      mutation.Method,
			MatchSource: mutation.MatchSource,
			Columns:     releaseUpdateColumns(mutation, setStatus),
		})
		contract.RequiredEvents = append(contract.RequiredEvents, ReleaseNameFixedEventContract{
			ReleaseID:     release.ID,
			OldName:       release.SearchName,
			NewName:       mutation.NewSearchName,
			OldCategoryID: release.CategoryID,
			GroupID:       release.GroupID,
			Poster:        release.FromName,
		})
		contract.SearchUpdates = append(contract.SearchUpdates, SearchUpdateContract{
			ReleaseID: mutation.ReleaseID,
			Reason:    "release-update",
		})
		contract.CategoryResolutionRequired++
		updatedReleases[mutation.ReleaseID] = true

		if mutation.MatchSource == "predb-crc" {
			appendSingleColumnContract(contract, StatusUpdate{
				ReleaseID: mutation.ReleaseID,
				Column:    "proc_crc32",
				Value:     procDone,
				Reason:    "crc-predb-match-confirmation",
			})
		}
	}

	return nil
}

func appendStatusContracts(contract *HashedFixWriteContract, statuses []StatusUpdate, updatedReleases map[int64]bool) {
	for _, status := range statuses {
		if updatedReleases[status.ReleaseID] {
			continue
		}

		appendSingleColumnContract(contract, status)
	}
}

func appendSingleColumnContract(contract *HashedFixWriteContract, status StatusUpdate) {
	contract.SingleColumnUpdates = append(contract.SingleColumnUpdates, SingleColumnUpdateContract{
		ReleaseID: status.ReleaseID,
		Column:    status.Column,
		Value:     status.Value,
		Reason:    status.Reason,
	})
	contract.SearchUpdates = append(contract.SearchUpdates, SearchUpdateContract{
		ReleaseID: status.ReleaseID,
		Reason:    status.Reason,
	})
}

func releaseUpdateColumns(mutation ReleaseNameMutation, setStatus bool) []PlannedColumn {
	columns := []PlannedColumn{
		{Column: "videos_id", Value: 0},
		{Column: "tv_episodes_id", Value: 0},
		{Column: "imdbid", Value: nil},
	}

	if setStatus {
		columns = append(columns,
			PlannedColumn{Column: "musicinfo_id", Value: ""},
			PlannedColumn{Column: "consoleinfo_id", Value: ""},
			PlannedColumn{Column: "bookinfo_id", Value: ""},
			PlannedColumn{Column: "anidbid", Value: ""},
		)
	} else {
		columns = append(columns,
			PlannedColumn{Column: "musicinfo_id", Value: nil},
			PlannedColumn{Column: "consoleinfo_id", Value: nil},
			PlannedColumn{Column: "bookinfo_id", Value: nil},
			PlannedColumn{Column: "anidbid", Value: nil},
		)
	}

	columns = append(columns,
		PlannedColumn{Column: "predb_id", Value: mutation.PreDBID},
		PlannedColumn{Column: "searchname", Value: mutation.NewSearchName},
		PlannedColumn{
			Column:      "categories_id",
			ValueSource: "CategorizationService.determineCategory(groups_id, new_title, fromname)",
		},
	)

	if setStatus {
		columns = append(columns,
			PlannedColumn{Column: "isrenamed", Value: 1},
			PlannedColumn{Column: "iscategorized", Value: 1},
			PlannedColumn{Column: mutation.StatusColumn, Value: 1},
		)
	} else {
		columns = append(columns, PlannedColumn{Column: "iscategorized", Value: 1})
	}

	return columns
}

func loadReleaseWriteContext(ctx context.Context, db *sql.DB, releaseID int64) (releaseWriteContext, error) {
	var release releaseWriteContext
	err := db.QueryRowContext(ctx, `
		SELECT id, searchname, categories_id, groups_id, COALESCE(fromname, '')
		FROM releases
		WHERE id = ?`, releaseID).Scan(
		&release.ID,
		&release.SearchName,
		&release.CategoryID,
		&release.GroupID,
		&release.FromName,
	)
	if err != nil {
		return releaseWriteContext{}, err
	}

	return release, nil
}

func CRCPriority(filename string) int {
	lower := strings.ToLower(filename)

	if sampleOrProofPattern.MatchString(filename) {
		return 100
	}

	if isVideoFile(lower) {
		return 4
	}

	if isMainRAR(lower) {
		return 2
	}

	if isFirstSplitRAR(lower) {
		return 3
	}

	if matched, _ := regexp.MatchString(`(?i)\.(rar|r\d{2,3})$`, filename); matched {
		return 6
	}

	if strings.HasSuffix(lower, ".nfo") {
		return 5
	}

	return 50
}

type crcDryRunOptions struct {
	Categories []int
	RecentOnly bool
	Limit      int
}

type parHashDryRunOptions struct {
	Categories []int
	RecentOnly bool
	Limit      int
}

func buildCRCDryRunPlan(ctx context.Context, db *sql.DB, options crcDryRunOptions) ([]ReleaseNameMutation, []StatusUpdate, error) {
	if len(options.Categories) == 0 {
		return nil, nil, fmt.Errorf("crc dry-run requires at least one category")
	}

	query := `
		SELECT rel.id, rel.searchname, rel.size
		FROM releases rel
		WHERE (rel.isrenamed = 0 OR rel.categories_id IN (` + placeholders(2) + `))
		  AND rel.predb_id = 0
		  AND rel.proc_crc32 = 0
		  AND rel.categories_id IN (` + placeholders(len(options.Categories)) + `)
		  AND EXISTS (
		      SELECT 1
		      FROM release_files rf
		      WHERE rf.releases_id = rel.id
		        AND rf.crc32 != ''
		        AND rf.crc32 IS NOT NULL
		  )
`
	args := []any{otherMiscCategory, otherHashedCategory}
	for _, category := range options.Categories {
		args = append(args, category)
	}
	if options.RecentOnly {
		query += "\t\t  AND rel.adddate > (NOW() - INTERVAL 6 HOUR)\n"
	}
	query += "\t\tORDER BY rel.adddate DESC, rel.id DESC"
	if options.Limit > 0 {
		query += "\n\t\tLIMIT ?"
		args = append(args, options.Limit)
	}

	rows, err := db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()

	mutations := []ReleaseNameMutation{}
	statusOnly := []StatusUpdate{}
	for rows.Next() {
		var candidate releaseCandidate
		if err := rows.Scan(&candidate.ID, &candidate.SearchName, &candidate.Size); err != nil {
			return nil, nil, err
		}

		mutation, matched, err := planCRCFix(ctx, db, candidate)
		if err != nil {
			return nil, nil, err
		}
		if matched {
			mutations = append(mutations, mutation)
			continue
		}

		statusOnly = append(statusOnly, StatusUpdate{
			ReleaseID: candidate.ID,
			Column:    "proc_crc32",
			Value:     procDone,
			Reason:    "crc-miss",
		})
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}

	return mutations, statusOnly, nil
}

func planCRCFix(ctx context.Context, db *sql.DB, candidate releaseCandidate) (ReleaseNameMutation, bool, error) {
	files, err := crcFileCandidates(ctx, db, candidate.ID)
	if err != nil {
		return ReleaseNameMutation{}, false, err
	}

	for _, file := range files {
		crc := strings.ToUpper(strings.TrimSpace(file.CRC))
		if crc == "" {
			continue
		}

		predbMatch, ok, err := predbCRCMatch(ctx, db, crc, file.Size)
		if err != nil {
			return ReleaseNameMutation{}, false, err
		}
		if ok {
			return ReleaseNameMutation{
				ReleaseID:     candidate.ID,
				OldSearchName: candidate.SearchName,
				NewSearchName: predbMatch.SearchName,
				PreDBID:       predbMatch.PreDBID,
				Method:        "crcCheck: PreDB CRC",
				StatusColumn:  "proc_crc32",
				MatchSource:   "predb-crc",
			}, true, nil
		}

		releaseMatch, ok, err := releaseCRCMatch(ctx, db, crc, candidate.Size)
		if err != nil {
			return ReleaseNameMutation{}, false, err
		}
		if ok {
			return ReleaseNameMutation{
				ReleaseID:     candidate.ID,
				OldSearchName: candidate.SearchName,
				NewSearchName: releaseMatch.SearchName,
				PreDBID:       releaseMatch.PreDBID,
				Method:        "crcCheck: CRC32",
				StatusColumn:  "proc_crc32",
				MatchSource:   "release-crc",
			}, true, nil
		}
	}

	return ReleaseNameMutation{}, false, nil
}

func crcFileCandidates(ctx context.Context, db *sql.DB, releaseID int64) ([]crcFileCandidate, error) {
	rows, err := db.QueryContext(ctx, `
		SELECT name, size, crc32
		FROM release_files
		WHERE releases_id = ?
		  AND crc32 != ''
		  AND crc32 IS NOT NULL`, releaseID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	files := []crcFileCandidate{}
	for rows.Next() {
		var file crcFileCandidate
		if err := rows.Scan(&file.Name, &file.Size, &file.CRC); err != nil {
			return nil, err
		}
		files = append(files, file)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	sort.SliceStable(files, func(i, j int) bool {
		leftPriority := CRCPriority(files[i].Name)
		rightPriority := CRCPriority(files[j].Name)
		if leftPriority != rightPriority {
			return leftPriority < rightPriority
		}

		return files[i].Name < files[j].Name
	})

	return files, nil
}

func predbCRCMatch(ctx context.Context, db *sql.DB, crc string, size int64) (releaseMatch, bool, error) {
	var match releaseMatch
	err := db.QueryRowContext(ctx, `
		SELECT p.id, p.title
		FROM predb_crcs pc
		INNER JOIN predb p ON p.id = pc.predb_id
		WHERE pc.crchash = ?
		  AND (pc.filesize = 0 OR pc.filesize = ?)
		ORDER BY pc.filesize DESC, p.predate DESC
		LIMIT 1`, crc, size).Scan(&match.PreDBID, &match.SearchName)
	if err == sql.ErrNoRows {
		return releaseMatch{}, false, nil
	}
	if err != nil {
		return releaseMatch{}, false, err
	}

	return match, true, nil
}

func releaseCRCMatch(ctx context.Context, db *sql.DB, crc string, targetSize int64) (releaseMatch, bool, error) {
	rows, err := db.QueryContext(ctx, `
		SELECT rel.searchname, rel.size, rel.predb_id
		FROM releases rel
		INNER JOIN release_files rf ON rf.releases_id = rel.id
		WHERE rel.predb_id > 0
		  AND UPPER(rf.crc32) = ?
		ORDER BY rel.adddate DESC, rel.id DESC`, crc)
	if err != nil {
		return releaseMatch{}, false, err
	}
	defer rows.Close()

	for rows.Next() {
		var match releaseMatch
		if err := rows.Scan(&match.SearchName, &match.Size, &match.PreDBID); err != nil {
			return releaseMatch{}, false, err
		}
		if withinPercent(match.Size, targetSize, 5) {
			return match, true, nil
		}
	}
	if err := rows.Err(); err != nil {
		return releaseMatch{}, false, err
	}

	return releaseMatch{}, false, nil
}

func buildParHashDryRunPlan(ctx context.Context, db *sql.DB, options parHashDryRunOptions) ([]ReleaseNameMutation, []StatusUpdate, error) {
	if len(options.Categories) == 0 {
		return nil, nil, fmt.Errorf("par-hash dry-run requires at least one category")
	}

	rows, err := db.QueryContext(ctx, `
		SELECT rel.id, rel.searchname, rel.size, IFNULL(ph.hash, '') AS hash
		FROM releases rel
		INNER JOIN par_hashes ph ON ph.releases_id = rel.id
		WHERE (rel.isrenamed = 0 OR rel.categories_id IN (`+placeholders(2)+`))
		  AND rel.predb_id = 0
		  AND ph.hash != ''
		  AND rel.proc_hash16k = 0
		  AND rel.categories_id IN (`+placeholders(len(options.Categories))+`)`+recentSQL(options.RecentOnly)+`
		GROUP BY rel.id
		ORDER BY rel.adddate DESC, rel.id DESC`+limitSQL(options.Limit), append(append([]any{otherMiscCategory, otherHashedCategory}, intsToAny(options.Categories)...), limitArg(options.Limit)...)...)
	if err != nil {
		return nil, nil, err
	}
	defer rows.Close()

	mutations := []ReleaseNameMutation{}
	statusOnly := []StatusUpdate{}
	for rows.Next() {
		var candidate parHashCandidate
		if err := rows.Scan(&candidate.ID, &candidate.SearchName, &candidate.Size, &candidate.Hash); err != nil {
			return nil, nil, err
		}

		match, ok, err := parHashMatch(ctx, db, candidate.Hash, candidate.ID, candidate.Size)
		if err != nil {
			return nil, nil, err
		}
		if ok {
			mutations = append(mutations, ReleaseNameMutation{
				ReleaseID:     candidate.ID,
				OldSearchName: candidate.SearchName,
				NewSearchName: match.SearchName,
				PreDBID:       match.PreDBID,
				Method:        "hashCheck: PAR2 hash_16K",
				StatusColumn:  "proc_hash16k",
				MatchSource:   "par-hash",
			})
			continue
		}

		statusOnly = append(statusOnly, StatusUpdate{
			ReleaseID: candidate.ID,
			Column:    "proc_hash16k",
			Value:     procDone,
			Reason:    "par-hash-miss",
		})
	}
	if err := rows.Err(); err != nil {
		return nil, nil, err
	}

	return mutations, statusOnly, nil
}

func regularFixCategories(category string) ([]int, error) {
	switch category {
	case "other":
		return []int{booksUnknown, gameOtherCategory, movieOther, musicOther, pcPhoneOther, tvOther, xxxOther, otherMiscCategory}, nil
	case "movies":
		return []int{movieForeign, movieOther, movieSD, movieHD, movieUHD, movie3D, movieBluRay, movieDVD, movieWEBDL, movieX265}, nil
	default:
		return nil, fmt.Errorf("unsupported regular fix-name category %q", category)
	}
}

func regularFixStatusCommitCategories() []int {
	return []int{
		booksUnknown,
		gameOtherCategory,
		movieOther,
		musicOther,
		pcPhoneOther,
		tvOther,
		xxxOther,
		otherMiscCategory,
		movieForeign,
		movieSD,
		movieHD,
		movieUHD,
		movie3D,
		movieBluRay,
		movieDVD,
		movieWEBDL,
		movieX265,
	}
}

func placeholders(count int) string {
	if count < 1 {
		return ""
	}

	return strings.TrimRight(strings.Repeat("?,", count), ",")
}

func recentSQL(recentOnly bool) string {
	if !recentOnly {
		return ""
	}

	return "\n\t\t  AND rel.adddate > (NOW() - INTERVAL 6 HOUR)"
}

func limitSQL(limit int) string {
	if limit < 1 {
		return ""
	}

	return "\n\t\tLIMIT ?"
}

func limitArg(limit int) []any {
	if limit < 1 {
		return nil
	}

	return []any{limit}
}

func intsToAny(values []int) []any {
	args := make([]any, 0, len(values))
	for _, value := range values {
		args = append(args, value)
	}

	return args
}

func parHashMatch(ctx context.Context, db *sql.DB, hash string, releaseID int64, targetSize int64) (releaseMatch, bool, error) {
	rows, err := db.QueryContext(ctx, `
		SELECT rel.searchname, rel.size, rel.predb_id
		FROM releases rel
		INNER JOIN par_hashes ph ON ph.releases_id = rel.id
		WHERE ph.hash = ?
		  AND ph.releases_id != ?
		  AND (rel.predb_id > 0 OR rel.anidbid > 0)
		ORDER BY rel.adddate DESC, rel.id DESC`, hash, releaseID)
	if err != nil {
		return releaseMatch{}, false, err
	}
	defer rows.Close()

	for rows.Next() {
		var match releaseMatch
		if err := rows.Scan(&match.SearchName, &match.Size, &match.PreDBID); err != nil {
			return releaseMatch{}, false, err
		}
		if withinPercent(match.Size, targetSize, 5) {
			return match, true, nil
		}
	}
	if err := rows.Err(); err != nil {
		return releaseMatch{}, false, err
	}

	return releaseMatch{}, false, nil
}

func withinPercent(referenceSize int64, targetSize int64, percent float64) bool {
	if referenceSize <= 0 {
		return false
	}

	delta := float64(referenceSize-targetSize) / float64(referenceSize) * 100

	return delta >= -percent && delta <= percent
}

func isVideoFile(lowerFilename string) bool {
	switch strings.TrimPrefix(filepath.Ext(lowerFilename), ".") {
	case "mkv", "avi", "mp4", "m4v", "wmv", "divx", "ts", "m2ts":
		return true
	default:
		return false
	}
}

func isMainRAR(lowerFilename string) bool {
	if !strings.HasSuffix(lowerFilename, ".rar") {
		return false
	}

	matched, _ := regexp.MatchString(`(?i)\.part\d+\.rar$`, lowerFilename)

	return !matched
}

func isFirstSplitRAR(lowerFilename string) bool {
	matched, _ := regexp.MatchString(`(?i)\.part0*1\.rar$`, lowerFilename)

	return matched
}
