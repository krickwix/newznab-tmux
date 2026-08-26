package metadata

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

const (
	defaultSrrdbBaseURL = "https://api.srrdb.com/v1"
	defaultHTTPTimeout  = 20 * time.Second
)

type SrrdbClient struct {
	BaseURL    string
	Timeout    time.Duration
	HTTPClient *http.Client
	UserAgent  string
	Disabled   bool
}

type SrrdbFetchSummary struct {
	Candidates        int  `json:"candidates"`
	Queried           int  `json:"queried"`
	Found             int  `json:"found"`
	Failed            int  `json:"failed"`
	Files             int  `json:"files"`
	ArchiveCandidates int  `json:"archive_candidates"`
	ArchiveQueried    int  `json:"archive_queried"`
	ArchiveFound      int  `json:"archive_found"`
	ArchiveFailed     int  `json:"archive_failed"`
	ArchiveHits       int  `json:"archive_hits"`
	Skipped           bool `json:"skipped"`
}

func SrrdbClientFromEnv() SrrdbClient {
	timeout := defaultHTTPTimeout
	if raw := strings.TrimSpace(os.Getenv("NNTMUX_METADATA_REFRESH_TIMEOUT")); raw != "" {
		if seconds, err := strconv.Atoi(raw); err == nil && seconds > 0 {
			timeout = time.Duration(seconds) * time.Second
		}
	}

	baseURL := strings.TrimSpace(os.Getenv("NNTMUX_SRRDB_BASE_URL"))
	if baseURL == "" {
		baseURL = defaultSrrdbBaseURL
	}

	return SrrdbClient{
		BaseURL:   baseURL,
		Timeout:   timeout,
		UserAgent: "nntmux-external-metadata/1.0",
		Disabled:  !boolEnvDefault("NNTMUX_METADATA_SOURCE_SRRDB", true),
	}
}

func EnrichSrrdbTitleDetails(ctx context.Context, plan RefreshDryRunPlan, client SrrdbClient, sleep time.Duration) (RefreshDryRunPlan, SrrdbFetchSummary, error) {
	summary := SrrdbFetchSummary{Candidates: len(plan.SrrdbTitleCandidates)}
	if client.Disabled {
		summary.Skipped = true
		plan.SrrdbTitleCandidates = nil
		return plan, summary, nil
	}
	if len(plan.SrrdbTitleCandidates) == 0 {
		return plan, summary, nil
	}
	if plan.SrrdbTitleDetails == nil {
		plan.SrrdbTitleDetails = map[int64]SrrdbTitleDetails{}
	}

	for index, candidate := range plan.SrrdbTitleCandidates {
		summary.Queried++
		details, ok, err := client.Details(ctx, candidate.Title)
		if err != nil {
			return plan, summary, err
		}
		if !ok {
			summary.Failed++
		} else {
			summary.Found++
			summary.Files += len(details.Files)
			plan.SrrdbTitleDetails[candidate.ID] = details
		}

		if sleep > 0 && index < len(plan.SrrdbTitleCandidates)-1 {
			timer := time.NewTimer(sleep)
			select {
			case <-ctx.Done():
				timer.Stop()
				return plan, summary, ctx.Err()
			case <-timer.C:
			}
		}
	}

	return plan, summary, nil
}

func EnrichSrrdbArchiveCRCSearch(ctx context.Context, plan RefreshDryRunPlan, client SrrdbClient, sleep time.Duration) (RefreshDryRunPlan, SrrdbFetchSummary, error) {
	summary := SrrdbFetchSummary{ArchiveCandidates: len(plan.ArchiveCRCCandidates)}
	if client.Disabled {
		summary.Skipped = true
		plan.ArchiveCRCCandidates = nil
		return plan, summary, nil
	}
	if len(plan.ArchiveCRCCandidates) == 0 {
		return plan, summary, nil
	}
	if plan.ArchiveCRCHits == nil {
		plan.ArchiveCRCHits = map[string][]SrrdbArchiveHit{}
	}

	for index, candidate := range plan.ArchiveCRCCandidates {
		summary.ArchiveQueried++
		hits, err := client.SearchByArchiveCRC(ctx, candidate.CRC, candidate.Size, 10)
		if err != nil {
			return plan, summary, err
		}
		if len(hits) == 0 {
			summary.ArchiveFailed++
		} else {
			summary.ArchiveFound++
			summary.ArchiveHits += len(hits)
			plan.ArchiveCRCHits[candidate.Key()] = hits
		}

		if sleep > 0 && index < len(plan.ArchiveCRCCandidates)-1 {
			timer := time.NewTimer(sleep)
			select {
			case <-ctx.Done():
				timer.Stop()
				return plan, summary, ctx.Err()
			case <-timer.C:
			}
		}
	}

	return plan, summary, nil
}

func (summary *SrrdbFetchSummary) Merge(other SrrdbFetchSummary) {
	summary.Candidates += other.Candidates
	summary.Queried += other.Queried
	summary.Found += other.Found
	summary.Failed += other.Failed
	summary.Files += other.Files
	summary.ArchiveCandidates += other.ArchiveCandidates
	summary.ArchiveQueried += other.ArchiveQueried
	summary.ArchiveFound += other.ArchiveFound
	summary.ArchiveFailed += other.ArchiveFailed
	summary.ArchiveHits += other.ArchiveHits
	summary.Skipped = summary.Skipped || other.Skipped
}

func (c SrrdbClient) Details(ctx context.Context, releaseTitle string) (SrrdbTitleDetails, bool, error) {
	baseURL := strings.TrimRight(strings.TrimSpace(c.BaseURL), "/")
	if baseURL == "" {
		baseURL = defaultSrrdbBaseURL
	}

	endpoint := baseURL + "/details/" + url.PathEscape(releaseTitle)
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return SrrdbTitleDetails{}, false, err
	}
	req.Header.Set("Accept", "application/json")
	if strings.TrimSpace(c.UserAgent) != "" {
		req.Header.Set("User-Agent", c.UserAgent)
	}

	httpClient := c.HTTPClient
	if httpClient == nil {
		timeout := c.Timeout
		if timeout <= 0 {
			timeout = defaultHTTPTimeout
		}
		httpClient = &http.Client{Timeout: timeout}
	}

	resp, err := httpClient.Do(req)
	if err != nil {
		if ctx.Err() != nil {
			return SrrdbTitleDetails{}, false, ctx.Err()
		}

		return SrrdbTitleDetails{}, false, nil
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return SrrdbTitleDetails{}, false, nil
	}

	var payload struct {
		Files []struct {
			Name string `json:"name"`
			Size int64  `json:"size"`
			CRC  string `json:"crc"`
		} `json:"files"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&payload); err != nil {
		return SrrdbTitleDetails{}, false, nil
	}
	if payload.Files == nil {
		return SrrdbTitleDetails{}, false, nil
	}

	files := make([]SrrdbFile, 0, len(payload.Files))
	for _, file := range payload.Files {
		name := strings.TrimSpace(file.Name)
		crc := strings.ToUpper(strings.TrimSpace(file.CRC))
		if name == "" || file.Size <= 0 || !validCRC32Pattern.MatchString(crc) {
			continue
		}

		files = append(files, SrrdbFile{Name: name, CRC: crc, Size: file.Size})
	}

	return SrrdbTitleDetails{Files: files}, true, nil
}

func (c SrrdbClient) SearchByArchiveCRC(ctx context.Context, crc string, size int64, limit int) ([]SrrdbArchiveHit, error) {
	crc = strings.ToUpper(strings.TrimSpace(crc))
	if !validCRC32Pattern.MatchString(crc) {
		return nil, nil
	}

	baseURL := strings.TrimRight(strings.TrimSpace(c.BaseURL), "/")
	if baseURL == "" {
		baseURL = defaultSrrdbBaseURL
	}

	endpoint := baseURL + "/search/archive-crc:" + url.PathEscape(crc)
	if size > 0 {
		endpoint += "/archive-size:" + strconv.FormatInt(size, 10)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")
	if strings.TrimSpace(c.UserAgent) != "" {
		req.Header.Set("User-Agent", c.UserAgent)
	}

	httpClient := c.HTTPClient
	if httpClient == nil {
		timeout := c.Timeout
		if timeout <= 0 {
			timeout = defaultHTTPTimeout
		}
		httpClient = &http.Client{Timeout: timeout}
	}

	resp, err := httpClient.Do(req)
	if err != nil {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}

		return nil, nil
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, nil
	}

	var payload struct {
		Results  []map[string]any `json:"results"`
		Releases []map[string]any `json:"releases"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&payload); err != nil {
		return nil, nil
	}

	rows := payload.Results
	if rows == nil {
		rows = payload.Releases
	}
	if limit < 1 || limit > len(rows) {
		limit = len(rows)
	}

	hits := []SrrdbArchiveHit{}
	seen := map[string]struct{}{}
	for _, row := range rows[:limit] {
		title := stringField(row, "release")
		if title == "" {
			title = stringField(row, "name")
		}
		if title == "" {
			title = stringField(row, "dirname")
		}
		if title == "" {
			continue
		}
		if _, ok := seen[title]; ok {
			continue
		}
		seen[title] = struct{}{}
		hits = append(hits, SrrdbArchiveHit{Title: title})
	}

	return hits, nil
}

func boolEnvDefault(key string, fallback bool) bool {
	raw := strings.ToLower(strings.TrimSpace(os.Getenv(key)))
	if raw == "" {
		return fallback
	}

	switch raw {
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	default:
		return fallback
	}
}

func stringField(row map[string]any, key string) string {
	value, ok := row[key]
	if !ok {
		return ""
	}

	return strings.TrimSpace(fmt.Sprint(value))
}

func SrrdbFetchSummaryText(summary SrrdbFetchSummary) string {
	return fmt.Sprintf(
		"srrdb-details: candidates=%d queried=%d found=%d failed=%d files=%d archive-candidates=%d archive-queried=%d archive-found=%d archive-failed=%d archive-hits=%d skipped=%t\n",
		summary.Candidates,
		summary.Queried,
		summary.Found,
		summary.Failed,
		summary.Files,
		summary.ArchiveCandidates,
		summary.ArchiveQueried,
		summary.ArchiveFound,
		summary.ArchiveFailed,
		summary.ArchiveHits,
		summary.Skipped,
	)
}
