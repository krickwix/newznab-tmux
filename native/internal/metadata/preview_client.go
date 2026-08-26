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

type PreviewSourceClient struct {
	Timeout                      time.Duration
	HTTPClient                   *http.Client
	NzbIndexBaseURL              string
	NzbIndexAPIKey               string
	NzbIndexEnabled              bool
	InternetArchivePredbEnabled  bool
	InternetArchivePredbDumpPath string
}

type PreviewSourceFetchSummary struct {
	Queries     int `json:"queries"`
	Queried     int `json:"queried"`
	Found       int `json:"found"`
	Failed      int `json:"failed"`
	Hits        int `json:"hits"`
	Skipped     int `json:"skipped"`
	BulkSkipped int `json:"bulk_skipped"`
}

func PreviewSourceClientFromEnv() PreviewSourceClient {
	timeout := defaultHTTPTimeout
	if raw := strings.TrimSpace(os.Getenv("NNTMUX_METADATA_REFRESH_TIMEOUT")); raw != "" {
		if seconds, err := strconv.Atoi(raw); err == nil && seconds > 0 {
			timeout = time.Duration(seconds) * time.Second
		}
	}

	return PreviewSourceClient{
		Timeout:                      timeout,
		NzbIndexBaseURL:              envDefault("NNTMUX_NZBINDEX_BASE_URL", "https://www.nzbindex.com/api"),
		NzbIndexAPIKey:               strings.TrimSpace(os.Getenv("NNTMUX_NZBINDEX_API_KEY")),
		NzbIndexEnabled:              boolEnvDefault("NNTMUX_METADATA_SOURCE_NZBINDEX", false),
		InternetArchivePredbEnabled:  boolEnvDefault("NNTMUX_METADATA_SOURCE_IA_PREDB", false),
		InternetArchivePredbDumpPath: strings.TrimSpace(os.Getenv("NNTMUX_IA_PREDB_DUMP_PATH")),
	}
}

func FetchPreviewSources(ctx context.Context, queries []string, client PreviewSourceClient, sources []string, limit int, sleep time.Duration) (PreviewSourceFetchSummary, error) {
	summary := PreviewSourceFetchSummary{Queries: len(queries)}
	selected := selectedPreviewSources(sources)
	if len(selected) == 0 {
		return summary, nil
	}

	for _, source := range selected {
		switch source {
		case "nzbindex":
			if !client.NzbIndexEnabled || strings.TrimSpace(client.NzbIndexAPIKey) == "" {
				summary.Skipped++
				continue
			}
			if len(queries) == 0 {
				summary.Skipped++
				continue
			}

			maxQueries := min(max(1, limit), len(queries))
			for queryIndex, query := range queries[:maxQueries] {
				summary.Queried++
				hits, err := client.SearchNzbIndex(ctx, query, min(10, max(1, limit)))
				if err != nil {
					return summary, err
				}
				if hits > 0 {
					summary.Found++
					summary.Hits += hits
				}

				if sleep > 0 && queryIndex < maxQueries-1 {
					timer := time.NewTimer(sleep)
					select {
					case <-ctx.Done():
						timer.Stop()
						return summary, ctx.Err()
					case <-timer.C:
					}
				}
			}
		case "internet-archive-predb":
			if !client.InternetArchivePredbEnabled {
				summary.Skipped++
				continue
			}
			summary.BulkSkipped++
			summary.Skipped++
		}
	}

	return summary, nil
}

func (c PreviewSourceClient) SearchNzbIndex(ctx context.Context, query string, limit int) (int, error) {
	endpoint, err := nzbIndexURL(c.NzbIndexBaseURL, query, strings.TrimSpace(c.NzbIndexAPIKey), limit)
	if err != nil {
		return 0, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return 0, err
	}
	req.Header.Set("Accept", "application/json")

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
			return 0, ctx.Err()
		}

		return 0, nil
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return 0, nil
	}

	var payload map[string]any
	if err := json.NewDecoder(resp.Body).Decode(&payload); err != nil {
		return 0, nil
	}

	return countNzbIndexHits(payload), nil
}

func selectedPreviewSources(sources []string) []string {
	if len(sources) == 0 {
		return []string{"internet-archive-predb", "nzbindex"}
	}

	selected := []string{}
	seen := map[string]struct{}{}
	for _, source := range sources {
		source = strings.ToLower(strings.TrimSpace(source))
		if source == "all" {
			return []string{"internet-archive-predb", "nzbindex"}
		}
		if _, ok := map[string]struct{}{"internet-archive-predb": {}, "nzbindex": {}}[source]; !ok {
			continue
		}
		if _, ok := seen[source]; ok {
			continue
		}
		seen[source] = struct{}{}
		selected = append(selected, source)
	}

	return selected
}

func nzbIndexURL(baseURL string, query string, apiKey string, limit int) (string, error) {
	baseURL = strings.TrimRight(strings.TrimSpace(baseURL), "/")
	if baseURL == "" {
		return "", fmt.Errorf("metadata provider nzbindex base URL is required")
	}
	if strings.TrimSpace(apiKey) == "" {
		return "", fmt.Errorf("metadata provider nzbindex API key is required")
	}

	values := url.Values{}
	values.Set("q", query)
	values.Set("max", strconv.Itoa(limit))
	values.Set("key", apiKey)

	return baseURL + "/search?" + values.Encode(), nil
}

func countNzbIndexHits(payload map[string]any) int {
	if errorValue, ok := payload["error"].(bool); ok && errorValue {
		return 0
	}

	rows := anySlice(pathValue(payload, "data", "content"))
	if rows == nil {
		return 0
	}

	hits := 0
	for _, item := range rows {
		row, ok := item.(map[string]any)
		if !ok {
			continue
		}
		if stringField(row, "name") == "" {
			continue
		}
		hits++
	}

	return hits
}

func PreviewSourceFetchSummaryText(summary PreviewSourceFetchSummary) string {
	return fmt.Sprintf(
		"metadata-preview-sources: queries=%d queried=%d found=%d failed=%d hits=%d skipped=%d bulk-skipped=%d\n",
		summary.Queries,
		summary.Queried,
		summary.Found,
		summary.Failed,
		summary.Hits,
		summary.Skipped,
		summary.BulkSkipped,
	)
}
