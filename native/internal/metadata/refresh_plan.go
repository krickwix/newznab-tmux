package metadata

import (
	"bytes"
	"context"
	"database/sql"
	"fmt"
	"path"
	"regexp"
	"strconv"
	"strings"
)

var (
	trailingArchivePartPattern = regexp.MustCompile(`(?i)\.(?:part\d+|vol\d+\+\d+)$`)
	separatorPattern           = regexp.MustCompile(`[._-]+`)
	obfuscatedNamePattern      = regexp.MustCompile(`^[A-Za-z0-9]{24,}$`)
	searchableNamePattern      = regexp.MustCompile(`(?i)(?:19|20)\d{2}|bluray|web|hdtv|x264|x265|h264|h265|remux`)
	validCRC32Pattern          = regexp.MustCompile(`^[A-F0-9]{8}$`)
)

type RefreshDryRunPlan struct {
	SrrdbTitleCandidates []PredbTitleCandidate
	SrrdbTitleDetails    map[int64]SrrdbTitleDetails
	ArchiveCRCCandidates []ArchiveCRCCandidate
	ArchiveCRCHits       map[string][]SrrdbArchiveHit
	SearchQueries        []string
	SearchProviderHits   map[string][]SearchProviderHit
}

type PredbTitleCandidate struct {
	ID    int64
	Title string
}

type SrrdbTitleDetails struct {
	Files []SrrdbFile
}

type SrrdbFile struct {
	Name string
	CRC  string
	Size int64
}

type ArchiveCRCCandidate struct {
	Title string
	CRC   string
	Size  int64
}

func (c ArchiveCRCCandidate) Key() string {
	return strings.ToUpper(strings.TrimSpace(c.CRC)) + "#" + strconv.FormatInt(c.Size, 10)
}

type SrrdbArchiveHit struct {
	Title string
}

type SearchProviderHit struct {
	Source string
	Title  string
}

func SearchProviderKey(source string, query string) string {
	return strings.ToLower(strings.TrimSpace(source)) + "#" + strings.TrimSpace(query)
}

func DryRunSummary(plan RefreshDryRunPlan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "metadata-refresh mysql dry-run")
	fmt.Fprintf(&buffer, "srrdb-title-candidates=%d\n", len(plan.SrrdbTitleCandidates))
	fmt.Fprintf(&buffer, "archive-crc-candidates=%d\n", len(plan.ArchiveCRCCandidates))
	fmt.Fprintf(&buffer, "search-queries=%d\n", len(plan.SearchQueries))

	return buffer.String()
}

func BuildRefreshDryRunPlan(ctx context.Context, db *sql.DB, limit int) (RefreshDryRunPlan, error) {
	limit = max(1, limit)

	srrdbTitleCandidates, err := srrdbTitleCandidates(ctx, db, limit)
	if err != nil {
		return RefreshDryRunPlan{}, err
	}

	archiveCRCCandidates, err := archiveCRCCandidates(ctx, db, limit)
	if err != nil {
		return RefreshDryRunPlan{}, err
	}

	searchQueries, err := searchQueries(ctx, db, limit)
	if err != nil {
		return RefreshDryRunPlan{}, err
	}

	return RefreshDryRunPlan{
		SrrdbTitleCandidates: srrdbTitleCandidates,
		ArchiveCRCCandidates: archiveCRCCandidates,
		SearchQueries:        searchQueries,
	}, nil
}

func QueryFromFileName(name string) string {
	name = strings.ReplaceAll(name, `\`, `/`)
	base := path.Base(name)
	extension := path.Ext(base)
	if extension != "" {
		base = strings.TrimSuffix(base, extension)
	}

	base = trailingArchivePartPattern.ReplaceAllString(base, "")
	base = separatorPattern.ReplaceAllString(base, " ")
	base = strings.TrimSpace(base)

	if base == "" || len(base) < 8 {
		return ""
	}

	if obfuscatedNamePattern.MatchString(base) {
		return ""
	}

	if !searchableNamePattern.MatchString(base) {
		return ""
	}

	return base
}

func srrdbTitleCandidates(ctx context.Context, db *sql.DB, limit int) ([]PredbTitleCandidate, error) {
	rows, err := db.QueryContext(ctx, `
		SELECT p.id, p.title
		FROM predb p
		WHERE p.source = 'srrdb'
		  AND NOT EXISTS (
		      SELECT 1
		      FROM predb_crcs c
		      WHERE c.predb_id = p.id
		  )
		ORDER BY p.id DESC
		LIMIT ?`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	candidates := []PredbTitleCandidate{}
	for rows.Next() {
		var candidate PredbTitleCandidate
		if err := rows.Scan(&candidate.ID, &candidate.Title); err != nil {
			return nil, err
		}
		candidates = append(candidates, candidate)
	}

	return candidates, rows.Err()
}

func archiveCRCCandidates(ctx context.Context, db *sql.DB, limit int) ([]ArchiveCRCCandidate, error) {
	rows, err := db.QueryContext(ctx, `
		SELECT rf.name, rf.crc32, rf.size
		FROM release_files rf
		WHERE rf.crc32 != ''
		  AND rf.size > 0
		ORDER BY rf.created_at DESC
		LIMIT ?`, max(limit*5, limit))
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	candidates := []ArchiveCRCCandidate{}
	seen := map[string]struct{}{}
	for rows.Next() {
		if len(candidates) >= limit {
			break
		}

		var name string
		var crc string
		var size int64
		if err := rows.Scan(&name, &crc, &size); err != nil {
			return nil, err
		}

		crc = strings.ToUpper(strings.TrimSpace(crc))
		title := QueryFromFileName(name)
		if title == "" {
			continue
		}
		key := crc + "#" + strconv.FormatInt(size, 10)
		if _, ok := seen[key]; ok || !validCRC32Pattern.MatchString(crc) {
			continue
		}
		seen[key] = struct{}{}

		exists, err := predbCRCExists(ctx, db, crc, size)
		if err != nil {
			return nil, err
		}
		if exists {
			continue
		}

		candidates = append(candidates, ArchiveCRCCandidate{Title: title, CRC: crc, Size: size})
	}

	if err := rows.Err(); err != nil {
		return nil, err
	}

	return candidates, nil
}

func searchQueries(ctx context.Context, db *sql.DB, limit int) ([]string, error) {
	rows, err := db.QueryContext(ctx, `
		SELECT rf.name
		FROM release_files rf
		WHERE rf.name != ''
		ORDER BY rf.created_at DESC
		LIMIT ?`, max(limit*10, 50))
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	queries := []string{}
	seen := map[string]struct{}{}
	for rows.Next() {
		if len(queries) >= limit {
			break
		}

		var name string
		if err := rows.Scan(&name); err != nil {
			return nil, err
		}

		query := QueryFromFileName(name)
		if query == "" {
			continue
		}

		if _, ok := seen[query]; ok {
			continue
		}
		seen[query] = struct{}{}
		queries = append(queries, query)
	}

	if err := rows.Err(); err != nil {
		return nil, err
	}

	return queries, nil
}

func predbCRCExists(ctx context.Context, db *sql.DB, crc string, size int64) (bool, error) {
	var exists int
	err := db.QueryRowContext(ctx, `
		SELECT EXISTS (
			SELECT 1
			FROM predb_crcs
			WHERE crchash = ?
			  AND filesize = ?
		)`, crc, size).Scan(&exists)
	if err != nil {
		return false, err
	}

	return exists == 1, nil
}
