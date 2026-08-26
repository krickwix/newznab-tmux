package removecrap

import (
	"bytes"
	"compress/gzip"
	"context"
	"database/sql"
	"encoding/xml"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

const (
	categoryOtherMisc      = 10
	categoryOtherHashed    = 20
	categoryMovieForeign   = 2010
	categoryMovieSD        = 2030
	categoryMovieHD        = 2040
	categoryMovie3D        = 2050
	categoryMovieBluray    = 2060
	categoryMovieDVD       = 2070
	categoryMovieOther     = 2999
	categoryMusicMP3       = 3010
	categoryPC0Day         = 4010
	categoryPCISO          = 4020
	categoryPCMac          = 4030
	categoryPCPhoneOther   = 4040
	categoryPCGames        = 4050
	categoryPCPhoneIOS     = 4060
	categoryPCPhoneAndroid = 4070
	categoryTVRoot         = 5000
	categoryTVWebDL        = 5010
	categoryTVForeign      = 5020
	categoryTVSD           = 5030
	categoryTVHD           = 5040
	categoryTVOther        = 5999
	categoryTVSport        = 5060
	categoryTVAnime        = 5070
	categoryTVDocu         = 5080
	categoryXXXRoot        = 6000
	categoryXXXWMV         = 6020
	categoryXXXXVID        = 6030
	categoryXXXX264        = 6040
	categoryXXXOther       = 6999
	categoryBooksMagazines = 7010
	categoryBooksEbook     = 7020
	categoryBooksComics    = 7030
	categoryBooksTechnical = 7040
	categoryBooksForeign   = 7060
	categoryBooksUnknown   = 7999
	blacklistEnabled       = 1
	blacklistOptype        = 1
	blacklistFieldSubject  = 1
	blacklistFieldFrom     = 2
	passwordRAR            = 1
	defaultMinimumSize     = 2097152
)

type Request struct {
	Type            string
	Time            string
	BlacklistID     string
	DeleteRequested bool
}

type Candidate struct {
	ID         int64
	GUID       string
	SearchName string
}

type TypeResult struct {
	Type              string      `json:"type"`
	CandidateReleases int         `json:"candidate_releases"`
	CandidateRows     int         `json:"candidate_rows"`
	Candidates        []Candidate `json:"-"`
}

type Plan struct {
	Commands            int          `json:"commands"`
	DestructiveCommands int          `json:"destructive_commands"`
	CandidateReleases   int          `json:"candidate_releases"`
	CandidateRows       int          `json:"candidate_rows"`
	Results             []TypeResult `json:"results"`
	Writes              int          `json:"writes"`
}

func BuildDryRunPlan(ctx context.Context, db *sql.DB, requests []Request) (Plan, error) {
	plan := Plan{
		Commands: len(requests),
		Results:  []TypeResult{},
		Writes:   0,
	}

	for _, request := range requests {
		normalizedType := strings.ToLower(strings.TrimSpace(request.Type))
		if request.DeleteRequested {
			plan.DestructiveCommands++
		}

		for _, expandedType := range expandRemoveCrapTypes(normalizedType) {
			if _, ok := supportedTypes[expandedType]; !ok {
				return Plan{}, fmt.Errorf("unsupported removecrap type %q in native dry-run planner", expandedType)
			}

			candidates, err := removeCrapCandidates(ctx, db, Request{
				Type:            expandedType,
				Time:            request.Time,
				BlacklistID:     request.BlacklistID,
				DeleteRequested: request.DeleteRequested,
			})
			if err != nil {
				return Plan{}, err
			}

			candidateReleases := uniqueCandidateCount(candidates)
			plan.CandidateReleases += candidateReleases
			plan.CandidateRows += len(candidates)
			plan.Results = append(plan.Results, TypeResult{
				Type:              expandedType,
				CandidateReleases: candidateReleases,
				CandidateRows:     len(candidates),
				Candidates:        candidates,
			})
		}
	}

	return plan, nil
}

func expandRemoveCrapTypes(normalizedType string) []string {
	if normalizedType == "" {
		return phpAllRemovalTypes
	}

	return []string{normalizedType}
}

func DryRunSummary(plan Plan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "removecrap mysql dry-run")
	fmt.Fprintf(&buffer, "commands=%d\n", plan.Commands)
	fmt.Fprintf(&buffer, "destructive-commands=%d\n", plan.DestructiveCommands)
	fmt.Fprintf(&buffer, "candidate-releases=%d\n", plan.CandidateReleases)
	fmt.Fprintf(&buffer, "candidate-rows=%d\n", plan.CandidateRows)
	for _, result := range plan.Results {
		fmt.Fprintf(&buffer, "%s-candidates=%d\n", result.Type, result.CandidateReleases)
		fmt.Fprintf(&buffer, "%s-rows=%d\n", result.Type, result.CandidateRows)
	}
	fmt.Fprintf(&buffer, "writes=%d\n", plan.Writes)

	return buffer.String()
}

var supportedTypes = map[string]struct{}{
	"blacklist":   {},
	"codec":       {},
	"blfiles":     {},
	"executable":  {},
	"gibberish":   {},
	"hashed":      {},
	"installbin":  {},
	"nzb":         {},
	"par2only":    {},
	"passwordurl": {},
	"passworded":  {},
	"sample":      {},
	"scr":         {},
	"size":        {},
	"short":       {},
	"wmv_all":     {},
}

var phpAllRemovalTypes = []string{
	"blacklist",
	"blfiles",
	"executable",
	"gibberish",
	"hashed",
	"installbin",
	"passworded",
	"sample",
	"scr",
	"short",
	"size",
	"nzb",
	"codec",
	"par2only",
}

func removeCrapCandidates(ctx context.Context, db *sql.DB, request Request) ([]Candidate, error) {
	var (
		query string
		args  []any
	)

	timeClause, timeArgs, err := crapTimeClause(request.Time)
	if err != nil {
		return nil, err
	}

	switch request.Type {
	case "blacklist":
		if strings.EqualFold(strings.TrimSpace(request.Time), "full") {
			return nil, fmt.Errorf("removecrap blacklist full-history planning requires search integration")
		}
		blacklistIDClause, blacklistIDArgs, err := blacklistIDClause(request.BlacklistID)
		if err != nil {
			return nil, err
		}
		query = `
			SELECT DISTINCT r.id, r.guid, r.searchname
			FROM releases r
			JOIN binaryblacklist bb ON (
				(bb.msgcol = ? AND r.searchname REGEXP bb.regex)
				OR (bb.msgcol = ? AND r.fromname REGEXP bb.regex)
			)
			LEFT JOIN usenet_groups ug ON ug.id = r.groups_id
			WHERE bb.status = ?
				AND bb.optype = ?
				AND bb.msgcol IN (?, ?)
				AND (LOWER(bb.groupname) = ? OR COALESCE(ug.name, '') REGEXP bb.groupname)` + blacklistIDClause + timeClause + `
			ORDER BY r.id`
		args = append(args,
			blacklistFieldSubject,
			blacklistFieldFrom,
			blacklistEnabled,
			blacklistOptype,
			blacklistFieldSubject,
			blacklistFieldFrom,
			"alt.binaries.*",
		)
		args = append(args, blacklistIDArgs...)
	case "blfiles":
		query = `
			SELECT DISTINCT r.id, r.guid, r.searchname
			FROM releases r
			JOIN release_files rf ON r.id = rf.releases_id
			JOIN binaryblacklist bb ON rf.name REGEXP bb.regex
			LEFT JOIN usenet_groups ug ON ug.id = r.groups_id
			WHERE bb.status = ?
				AND bb.optype = ?
				AND bb.msgcol = ?
				AND (LOWER(bb.groupname) = ? OR COALESCE(ug.name, '') REGEXP bb.groupname)` + timeClause + `
			ORDER BY r.id`
		args = append(args, blacklistEnabled, blacklistOptype, blacklistFieldSubject, "alt.binaries.*")
	case "gibberish":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			WHERE r.nfostatus = 0
				AND r.iscategorized = 1
				AND r.rarinnerfilecount = 0
				AND r.categories_id NOT IN (?)
				AND r.searchname REGEXP '^[a-zA-Z0-9]{15,}$'` + timeClause + `
			ORDER BY r.id`
		args = append(args, categoryOtherHashed)
	case "hashed":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			WHERE r.nfostatus = 0
				AND r.iscategorized = 1
				AND r.rarinnerfilecount = 0
				AND r.categories_id NOT IN (?, ?)
				AND r.searchname REGEXP '[a-zA-Z0-9]{25,}'
				AND r.adddate < (NOW() - INTERVAL 30 MINUTE)` + timeClause + `
			ORDER BY r.id`
		args = append(args, categoryOtherMisc, categoryOtherHashed)
	case "short":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			WHERE r.nfostatus = 0
				AND r.iscategorized = 1
				AND r.rarinnerfilecount = 0
				AND r.categories_id NOT IN (?)
				AND r.searchname REGEXP '^[a-zA-Z0-9]{0,5}$'` + timeClause + `
			ORDER BY r.id`
		args = append(args, categoryOtherMisc)
	case "executable":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			JOIN release_files rf ON r.id = rf.releases_id
			WHERE rf.name LIKE ?
				AND r.categories_id NOT IN (?, ?, ?, ?)` + timeClause + `
			ORDER BY r.id`
		args = append(args, "%.exe", categoryPC0Day, categoryPCGames, categoryOtherMisc, categoryOtherHashed)
	case "installbin":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			JOIN release_files rf ON r.id = rf.releases_id
			WHERE rf.name LIKE ?` + timeClause + `
			ORDER BY r.id`
		args = append(args, "%install.bin%")
	case "passwordurl":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			JOIN release_files rf ON r.id = rf.releases_id
			WHERE rf.name LIKE ?` + timeClause + `
			ORDER BY r.id`
		args = append(args, "%password.url%")
	case "passworded":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			WHERE (
				r.passwordstatus = ?
				OR EXISTS (
					SELECT 1
					FROM release_files rf
					WHERE rf.releases_id = r.id
						AND rf.passworded = ?
				)
				OR (
					r.searchname LIKE ?
					AND r.searchname NOT LIKE ?
					AND r.searchname NOT LIKE ?
					AND r.searchname NOT LIKE ?
					AND r.searchname NOT LIKE ?
					AND r.searchname NOT LIKE ?
					AND r.searchname NOT LIKE ?
					AND r.categories_id NOT IN (?, ?, ?, ?, ?, ?, ?, ?, ?)
				)
			)` + timeClause + `
			ORDER BY r.id`
		args = append(args,
			passwordRAR,
			passwordRAR,
			"%passwor%",
			"%advanced%",
			"%no password%",
			"%not password%",
			"%recovery%",
			"%reset%",
			"%unlocker%",
			categoryPCGames,
			categoryPC0Day,
			categoryPCISO,
			categoryPCMac,
			categoryPCPhoneAndroid,
			categoryPCPhoneIOS,
			categoryPCPhoneOther,
			categoryOtherMisc,
			categoryOtherHashed,
		)
	case "sample":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			WHERE r.totalpart > 1
				AND r.size < 40000000
				AND r.name LIKE ?
				AND r.categories_id IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)` + timeClause + `
			ORDER BY r.id`
		args = append(args,
			"%sample%",
			categoryTVAnime,
			categoryTVDocu,
			categoryTVForeign,
			categoryTVHD,
			categoryTVOther,
			categoryTVSD,
			categoryTVSport,
			categoryTVWebDL,
			categoryMovie3D,
			categoryMovieBluray,
			categoryMovieDVD,
			categoryMovieForeign,
			categoryMovieHD,
			categoryMovieOther,
			categoryMovieSD,
		)
	case "codec":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			LEFT JOIN release_files rf ON r.id = rf.releases_id
			WHERE r.categories_id IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
				AND ((r.imdbid IS NOT NULL AND r.imdbid NOT IN ('', '0')) OR r.categories_id BETWEEN ? AND ?)
				AND r.nfostatus = 1
				AND r.haspreview = 0
				AND r.jpgstatus = 0
				AND r.predb_id = 0
				AND r.videostatus = 0
				AND (
					rf.name REGEXP 'XviD-[a-z]{3}[.](avi|mkv|wmv)$'
					OR rf.name REGEXP 'x264.*[.](wmv|avi)$'
					OR rf.name REGEXP '[.]*((DVDrip|BRRip)[. ].*[. ](R[56]|HQ)|720p[ .](DVDrip|HQ)|Webrip.*[. ](R[56]|Xvid|AC3|US)|720p.*[. ]WEB-DL[. ]Xvid[. ]AC3[. ]US|HDRip.*[. ]Xvid[. ]DD5).*[. ]avi$'
					OR rf.name LIKE '%\\\\Codec%Setup.exe%'
					OR rf.name LIKE '%\\\\Codec%Installer.exe%'
					OR rf.name LIKE '%\\\\Codec.exe%'
					OR rf.name LIKE '%If_you_get_error.txt%'
					OR rf.name LIKE '%read me if the movie not playing.txt%'
					OR rf.name LIKE '%Lisez moi si le film ne demarre pas.txt%'
					OR rf.name LIKE '%lees me als de film niet spelen.txt%'
					OR rf.name LIKE '%Lesen Sie mir wenn der Film nicht abgespielt.txt%'
					OR rf.name LIKE '%Lesen Sie mir, wenn der Film nicht starten.txt%'
				)` + timeClause + `
			GROUP BY r.id, r.guid, r.searchname
			ORDER BY r.id`
		args = append(args,
			categoryMovie3D,
			categoryMovieBluray,
			categoryMovieDVD,
			categoryMovieForeign,
			categoryMovieHD,
			categoryMovieOther,
			categoryMovieSD,
			categoryXXXWMV,
			categoryXXXX264,
			categoryXXXXVID,
			categoryXXXOther,
			categoryXXXRoot,
			categoryXXXOther,
		)
	case "wmv_all":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			LEFT JOIN release_files rf ON r.id = rf.releases_id
			WHERE r.categories_id BETWEEN ? AND ?
				AND rf.name REGEXP 'x264.*[.]wmv$'` + timeClause + `
			GROUP BY r.id, r.guid, r.searchname
			ORDER BY r.id`
		args = append(args, categoryTVRoot, categoryTVOther)
	case "size":
		minSize, err := minimumReleaseSize(ctx, db)
		if err != nil {
			return nil, err
		}
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			WHERE r.totalpart = 1
				AND r.size < ?
				AND r.categories_id NOT IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)` + timeClause + `
			ORDER BY r.id`
		args = append(args,
			minSize,
			categoryMusicMP3,
			categoryBooksComics,
			categoryBooksEbook,
			categoryBooksForeign,
			categoryBooksMagazines,
			categoryBooksTechnical,
			categoryBooksUnknown,
			categoryPC0Day,
			categoryPCGames,
			categoryOtherMisc,
			categoryOtherHashed,
		)
	case "nzb":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			JOIN release_files rf ON r.id = rf.releases_id
			WHERE r.totalpart = 1
				AND rf.name LIKE ?` + timeClause + `
			ORDER BY r.id`
		args = append(args, "%.nzb%")
	case "par2only":
		return par2OnlyCandidates(ctx, db, timeClause, timeArgs)
	case "scr":
		query = `
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			JOIN release_files rf ON r.id = rf.releases_id
			WHERE (rf.name REGEXP '[.]scr[$ "]' OR r.name REGEXP '[.]scr[$ "]')` + timeClause + `
			ORDER BY r.id`
	default:
		return nil, fmt.Errorf("unsupported removecrap type %q in native dry-run planner", request.Type)
	}
	args = append(args, timeArgs...)

	rows, err := db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, fmt.Errorf("select removecrap %s candidates: %w", request.Type, err)
	}
	defer rows.Close()

	candidates := []Candidate{}
	for rows.Next() {
		var candidate Candidate
		if err := rows.Scan(&candidate.ID, &candidate.GUID, &candidate.SearchName); err != nil {
			return nil, fmt.Errorf("scan removecrap %s candidate: %w", request.Type, err)
		}
		candidates = append(candidates, candidate)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read removecrap %s candidates: %w", request.Type, err)
	}

	return candidates, nil
}

func blacklistIDClause(raw string) (string, []any, error) {
	trimmed := strings.TrimSpace(raw)
	if trimmed == "" {
		return "", nil, nil
	}

	id, err := strconv.Atoi(trimmed)
	if err != nil || id <= 0 {
		return "", nil, fmt.Errorf("invalid removecrap blacklist id %q", raw)
	}

	return " AND bb.id = ?", []any{id}, nil
}

func par2OnlyCandidates(ctx context.Context, db *sql.DB, timeClause string, timeArgs []any) ([]Candidate, error) {
	query := `
		SELECT candidates.id, candidates.guid, candidates.searchname
		FROM (
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			WHERE r.searchname REGEXP '[.](vol[0-9]+[+][0-9]+[.]par2|par2)([]" _[)(>]|$)'
				AND EXISTS (
					SELECT 1
					FROM release_files rf_any
					WHERE rf_any.releases_id = r.id
				)
				AND r.id NOT IN (
					SELECT rf.releases_id
					FROM release_files rf
					WHERE rf.name NOT REGEXP '[.]par2'
				)` + timeClause + `
			UNION ALL
			SELECT r.id, r.guid, r.searchname
			FROM releases r
			INNER JOIN release_files rf ON r.id = rf.releases_id
			WHERE r.searchname NOT REGEXP '[.](vol[0-9]+[+][0-9]+[.]par2|par2)([]" _[)(>]|$)'` + timeClause + `
			GROUP BY r.id, r.guid, r.searchname
			HAVING COUNT(*) = SUM(CASE WHEN rf.name REGEXP '[.]par2' THEN 1 ELSE 0 END)
		) candidates
		ORDER BY candidates.id`

	args := append([]any{}, timeArgs...)
	args = append(args, timeArgs...)
	candidates, err := selectCandidates(ctx, db, "par2only", query, args)
	if err != nil {
		return nil, err
	}

	hashedCandidates, err := hashedPar2OnlyNZBCandidates(ctx, db, timeClause, timeArgs)
	if err != nil {
		return nil, err
	}
	candidates = append(candidates, hashedCandidates...)

	return candidates, nil
}

func hashedPar2OnlyNZBCandidates(ctx context.Context, db *sql.DB, timeClause string, timeArgs []any) ([]Candidate, error) {
	basePaths := nzbBasePathsFromEnv()
	if len(basePaths) == 0 {
		return nil, nil
	}

	splitLevel, err := nzbSplitLevel(ctx, db)
	if err != nil {
		return nil, err
	}

	query := `
		SELECT r.id, r.guid, r.searchname
		FROM releases r
		WHERE r.categories_id = ?
			AND r.isrenamed = 0` + timeClause + `
		ORDER BY r.id`
	args := append([]any{categoryOtherHashed}, timeArgs...)

	candidates, err := selectCandidates(ctx, db, "par2only hashed NZB", query, args)
	if err != nil {
		return nil, err
	}

	matches := []Candidate{}
	for _, candidate := range candidates {
		subjects, ok := hashedNZBSubjects(basePaths, candidate.GUID, splitLevel)
		if !ok || !isPar2OnlySubjects(subjects) {
			continue
		}
		matches = append(matches, candidate)
	}

	return matches, nil
}

func selectCandidates(ctx context.Context, db *sql.DB, removeCrapType string, query string, args []any) ([]Candidate, error) {
	rows, err := db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, fmt.Errorf("select removecrap %s candidates: %w", removeCrapType, err)
	}
	defer rows.Close()

	candidates := []Candidate{}
	for rows.Next() {
		var candidate Candidate
		if err := rows.Scan(&candidate.ID, &candidate.GUID, &candidate.SearchName); err != nil {
			return nil, fmt.Errorf("scan removecrap %s candidate: %w", removeCrapType, err)
		}
		candidates = append(candidates, candidate)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read removecrap %s candidates: %w", removeCrapType, err)
	}

	return candidates, nil
}

func nzbBasePathsFromEnv() []string {
	raw := strings.TrimSpace(os.Getenv("PATH_TO_NZBS"))
	if raw == "" {
		return nil
	}

	paths := []string{}
	for _, part := range filepath.SplitList(raw) {
		part = strings.TrimSpace(part)
		if part == "" {
			continue
		}
		paths = append(paths, part)
	}
	if len(paths) == 0 {
		paths = append(paths, raw)
	}

	return paths
}

func nzbSplitLevel(ctx context.Context, db *sql.DB) (int, error) {
	var raw sql.NullString
	err := db.QueryRowContext(ctx, `
		SELECT value
		FROM settings
		WHERE name = ?`, "nzbsplitlevel").Scan(&raw)
	if err != nil {
		if err == sql.ErrNoRows {
			return 1, nil
		}
		return 0, fmt.Errorf("read NZB split level setting: %w", err)
	}
	if !raw.Valid {
		return 1, nil
	}

	splitLevel, err := strconv.Atoi(strings.TrimSpace(raw.String))
	if err != nil || splitLevel < 1 {
		return 1, nil
	}
	if splitLevel > 32 {
		return 32, nil
	}

	return splitLevel, nil
}

func hashedNZBSubjects(basePaths []string, guid string, splitLevel int) ([]string, bool) {
	for _, basePath := range basePaths {
		contents, ok := readGzippedNZB(nzbPath(basePath, guid, splitLevel))
		if !ok {
			continue
		}
		subjects := parseNZBSubjects(contents)
		if len(subjects) == 0 {
			return nil, false
		}
		return subjects, true
	}

	return nil, false
}

func nzbPath(basePath string, guid string, splitLevel int) string {
	parts := []string{basePath}
	limit := splitLevel
	if limit > len(guid) {
		limit = len(guid)
	}
	for i := 0; i < limit; i++ {
		parts = append(parts, guid[i:i+1])
	}
	parts = append(parts, guid+".nzb.gz")

	return filepath.Join(parts...)
}

func readGzippedNZB(path string) ([]byte, bool) {
	file, err := os.Open(path)
	if err != nil {
		return nil, false
	}
	defer file.Close()

	reader, err := gzip.NewReader(file)
	if err != nil {
		return nil, false
	}
	defer reader.Close()

	contents, err := io.ReadAll(reader)
	if err != nil || len(contents) == 0 {
		return nil, false
	}

	return contents, true
}

func parseNZBSubjects(contents []byte) []string {
	decoder := xml.NewDecoder(bytes.NewReader(contents))
	subjects := []string{}

	for {
		token, err := decoder.Token()
		if err != nil {
			if err == io.EOF {
				return subjects
			}
			return nil
		}

		startElement, ok := token.(xml.StartElement)
		if !ok || startElement.Name.Local != "file" {
			continue
		}
		for _, attr := range startElement.Attr {
			if attr.Name.Local == "subject" {
				subjects = append(subjects, attr.Value)
				break
			}
		}
	}
}

var (
	nzbSubjectExtensionPattern = regexp.MustCompile(`(?i)\.([a-z0-9]{2,10})(?:"|\s|$|[)\]])`)
	rarVolumeExtensionPattern  = regexp.MustCompile(`^r[0-9]{2,3}$`)
)

func isPar2OnlySubjects(subjects []string) bool {
	if len(subjects) == 0 {
		return false
	}

	for _, subject := range subjects {
		if nzbSubjectExtension(subject) != "par2" {
			return false
		}
	}

	return true
}

func nzbSubjectExtension(subject string) string {
	matches := nzbSubjectExtensionPattern.FindAllStringSubmatch(subject, -1)
	if len(matches) == 0 {
		return ""
	}

	extension := strings.ToLower(matches[len(matches)-1][1])
	if rarVolumeExtensionPattern.MatchString(extension) {
		return "rar"
	}

	return extension
}

func minimumReleaseSize(ctx context.Context, db *sql.DB) (int, error) {
	var raw sql.NullString
	err := db.QueryRowContext(ctx, `
		SELECT value
		FROM settings
		WHERE name = ?`, "minsizetoformrelease").Scan(&raw)
	if err != nil {
		if err == sql.ErrNoRows {
			return defaultMinimumSize, nil
		}
		return 0, fmt.Errorf("read removecrap minimum release size setting: %w", err)
	}
	if !raw.Valid || strings.TrimSpace(raw.String) == "" {
		return defaultMinimumSize, nil
	}

	minSize, err := strconv.Atoi(strings.TrimSpace(raw.String))
	if err != nil {
		return 0, fmt.Errorf("parse removecrap minimum release size setting: %w", err)
	}
	if minSize == 0 {
		return defaultMinimumSize, nil
	}

	return minSize, nil
}

func uniqueCandidateCount(candidates []Candidate) int {
	seen := map[int64]struct{}{}
	for _, candidate := range candidates {
		seen[candidate.ID] = struct{}{}
	}

	return len(seen)
}

func crapTimeClause(value string) (string, []any, error) {
	value = strings.TrimSpace(value)
	if value == "" || value == "full" {
		return "", nil, nil
	}

	hours, err := strconv.Atoi(value)
	if err != nil {
		return "", nil, fmt.Errorf("removecrap time must be a number or full")
	}

	return " AND r.adddate > (NOW() - INTERVAL ? HOUR)", []any{hours}, nil
}
