package searchdoc

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"time"
)

const (
	reportMode = "native-search-document-parity"
	sourceJob  = "hashed-fixnames"
	effectName = "release-search-sync"
)

type Options struct {
	Limit int
	Now   time.Time
}

type ReleaseRow struct {
	ID             int64
	Name           string
	SearchName     string
	FromName       string
	CategoryID     int64
	FileName       string
	IMDBID         string
	TMDBID         int64
	TraktID        int64
	TVDB           int64
	TVMaze         int64
	TVRage         int64
	VideosID       int64
	MovieInfoID    int64
	Size           int64
	PostDate       time.Time
	AddDate        time.Time
	TotalPart      int64
	Grabs          int64
	PasswordStatus int64
	GroupID        int64
	NZBStatus      int64
	HasPreview     int64
}

type ReleaseDocument map[string]any

type ReleaseDocumentFingerprint struct {
	ReleaseID   int64  `json:"release_id"`
	Fingerprint string `json:"fingerprint"`
}

type ParityReport struct {
	SchemaVersion       int                          `json:"schema_version"`
	Mode                string                       `json:"mode"`
	DryRun              bool                         `json:"dry_run"`
	SourceJob           string                       `json:"source_job"`
	SearchDocumentsSeen int                          `json:"search_documents_seen"`
	ReleaseDocuments    []ReleaseDocumentFingerprint `json:"release_documents"`
	Writes              int                          `json:"writes"`
}

func BuildPendingOutboxParityReport(ctx context.Context, db *sql.DB, options Options) (ParityReport, error) {
	limit := options.Limit
	if limit <= 0 {
		limit = 100
	}
	if limit > 10000 {
		return ParityReport{}, fmt.Errorf("search document parity limit must be between 1 and 10000")
	}

	now := options.Now
	if now.IsZero() {
		now = time.Now().UTC()
	}

	releaseIDs, err := pendingOutboxReleaseIDs(ctx, db, limit, now)
	if err != nil {
		return ParityReport{}, err
	}

	fingerprints := make([]ReleaseDocumentFingerprint, 0, len(releaseIDs))
	for _, releaseID := range releaseIDs {
		row, err := HydrateReleaseRow(ctx, db, releaseID)
		if err != nil {
			return ParityReport{}, err
		}

		fingerprint, err := Fingerprint(NormalizeReleaseDocument(row))
		if err != nil {
			return ParityReport{}, err
		}

		fingerprints = append(fingerprints, ReleaseDocumentFingerprint{
			ReleaseID:   releaseID,
			Fingerprint: fingerprint,
		})
	}

	return ParityReport{
		SchemaVersion:       1,
		Mode:                reportMode,
		DryRun:              true,
		SourceJob:           sourceJob,
		SearchDocumentsSeen: len(fingerprints),
		ReleaseDocuments:    fingerprints,
		Writes:              0,
	}, nil
}

func pendingOutboxReleaseIDs(ctx context.Context, db *sql.DB, limit int, now time.Time) ([]int64, error) {
	rows, err := db.QueryContext(ctx, `
		SELECT release_id, status_column, status_reason, status_value
		FROM native_worker_side_effects
		WHERE job = ?
		  AND effect = ?
		  AND status IN ('pending', 'processing')
		  AND (available_at IS NULL OR available_at <= ?)
		ORDER BY id
		LIMIT ?`,
		sourceJob,
		effectName,
		now,
		limit,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	releaseIDs := []int64{}
	seen := map[int64]bool{}
	for rows.Next() {
		var releaseID int64
		var statusColumn string
		var statusReason string
		var statusValue int
		if err := rows.Scan(&releaseID, &statusColumn, &statusReason, &statusValue); err != nil {
			return nil, err
		}
		if err := assertSupportedOutboxRow(releaseID, statusColumn, statusReason, statusValue); err != nil {
			return nil, err
		}
		if !seen[releaseID] {
			seen[releaseID] = true
			releaseIDs = append(releaseIDs, releaseID)
		}
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}

	sort.Slice(releaseIDs, func(i, j int) bool {
		return releaseIDs[i] < releaseIDs[j]
	})

	return releaseIDs, nil
}

func assertSupportedOutboxRow(releaseID int64, statusColumn string, statusReason string, statusValue int) error {
	if releaseID <= 0 {
		return fmt.Errorf("native search document outbox release_id must be positive")
	}
	switch statusColumn {
	case "proc_crc32", "proc_hash16k":
	default:
		return fmt.Errorf("native search document outbox status_column is not supported")
	}
	switch statusReason {
	case "crc-miss", "par-hash-miss":
	default:
		return fmt.Errorf("native search document outbox status_reason is not supported")
	}
	if statusValue != 1 {
		return fmt.Errorf("native search document outbox status_value is not supported")
	}

	return nil
}

func HydrateReleaseRow(ctx context.Context, db *sql.DB, releaseID int64) (ReleaseRow, error) {
	var row ReleaseRow
	var fromName sql.NullString
	var imdbID sql.NullString
	var filename sql.NullString
	var postdate sql.NullTime
	var adddate sql.NullTime

	err := db.QueryRowContext(ctx, `
		SELECT
			r.id,
			r.name,
			r.searchname,
			r.fromname,
			r.categories_id,
			r.size,
			r.postdate,
			r.adddate,
			r.totalpart,
			r.grabs,
			r.passwordstatus,
			r.groups_id,
			r.nzbstatus,
			r.haspreview,
			r.imdbid,
			r.videos_id,
			r.movieinfo_id,
			IFNULL(mi.tmdbid, 0) AS tmdbid,
			IFNULL(mi.traktid, 0) AS traktid,
			IFNULL(v.tvdb, 0) AS tvdb,
			IFNULL(v.tvmaze, 0) AS tvmaze,
			IFNULL(v.tvrage, 0) AS tvrage,
			IFNULL(GROUP_CONCAT(rf.name SEPARATOR ' '), '') AS filename
		FROM releases r
		LEFT JOIN release_files rf ON r.id = rf.releases_id
		LEFT JOIN movieinfo mi ON r.movieinfo_id = mi.id
		LEFT JOIN videos v ON r.videos_id = v.id
		WHERE r.id = ?
		GROUP BY
			r.id,
			r.name,
			r.searchname,
			r.fromname,
			r.categories_id,
			r.size,
			r.postdate,
			r.adddate,
			r.totalpart,
			r.grabs,
			r.passwordstatus,
			r.groups_id,
			r.nzbstatus,
			r.haspreview,
			r.imdbid,
			r.videos_id,
			r.movieinfo_id,
			mi.tmdbid,
			mi.traktid,
			v.tvdb,
			v.tvmaze,
			v.tvrage`,
		releaseID,
	).Scan(
		&row.ID,
		&row.Name,
		&row.SearchName,
		&fromName,
		&row.CategoryID,
		&row.Size,
		&postdate,
		&adddate,
		&row.TotalPart,
		&row.Grabs,
		&row.PasswordStatus,
		&row.GroupID,
		&row.NZBStatus,
		&row.HasPreview,
		&imdbID,
		&row.VideosID,
		&row.MovieInfoID,
		&row.TMDBID,
		&row.TraktID,
		&row.TVDB,
		&row.TVMaze,
		&row.TVRage,
		&filename,
	)
	if err != nil {
		if err == sql.ErrNoRows {
			return ReleaseRow{}, fmt.Errorf("release %d not found for search document parity", releaseID)
		}

		return ReleaseRow{}, err
	}

	if fromName.Valid {
		row.FromName = fromName.String
	}
	if imdbID.Valid {
		row.IMDBID = imdbID.String
	}
	if filename.Valid {
		row.FileName = filename.String
	}
	if postdate.Valid {
		row.PostDate = postdate.Time
	}
	if adddate.Valid {
		row.AddDate = adddate.Time
	}

	return row, nil
}

func NormalizeReleaseDocument(row ReleaseRow) ReleaseDocument {
	return ReleaseDocument{
		"name":           row.Name,
		"searchname":     row.SearchName,
		"fromname":       row.FromName,
		"categories_id":  strconv.FormatInt(row.CategoryID, 10),
		"filename":       row.FileName,
		"imdbid":         row.IMDBID,
		"tmdbid":         row.TMDBID,
		"traktid":        row.TraktID,
		"tvdb":           row.TVDB,
		"tvmaze":         row.TVMaze,
		"tvrage":         row.TVRage,
		"videos_id":      row.VideosID,
		"movieinfo_id":   row.MovieInfoID,
		"size":           row.Size,
		"postdate_ts":    unixTimestamp(row.PostDate),
		"adddate_ts":     unixTimestamp(row.AddDate),
		"totalpart":      row.TotalPart,
		"grabs":          row.Grabs,
		"passwordstatus": row.PasswordStatus,
		"groups_id":      row.GroupID,
		"nzbstatus":      row.NZBStatus,
		"haspreview":     row.HasPreview,
	}
}

func Fingerprint(document ReleaseDocument) (string, error) {
	encoded, err := json.Marshal(document)
	if err != nil {
		return "", err
	}

	sum := sha256.Sum256(encoded)

	return hex.EncodeToString(sum[:]), nil
}

func unixTimestamp(value time.Time) int64 {
	if value.IsZero() {
		return 0
	}

	return value.Unix()
}
