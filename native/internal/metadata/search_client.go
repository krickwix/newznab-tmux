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

type SearchProviderClient struct {
	Timeout    time.Duration
	HTTPClient *http.Client
	Sources    map[string]SearchProviderConfig
}

type SearchProviderConfig struct {
	Source  string
	BaseURL string
	Enabled bool
}

type SearchProviderFetchSummary struct {
	Queries int `json:"queries"`
	Queried int `json:"queried"`
	Found   int `json:"found"`
	Failed  int `json:"failed"`
	Hits    int `json:"hits"`
	Skipped int `json:"skipped"`
}

func SearchProviderClientFromEnv() SearchProviderClient {
	timeout := defaultHTTPTimeout
	if raw := strings.TrimSpace(os.Getenv("NNTMUX_METADATA_REFRESH_TIMEOUT")); raw != "" {
		if seconds, err := strconv.Atoi(raw); err == nil && seconds > 0 {
			timeout = time.Duration(seconds) * time.Second
		}
	}

	return SearchProviderClient{
		Timeout: timeout,
		Sources: map[string]SearchProviderConfig{
			"predb-net": {
				Source:  "predb-net",
				BaseURL: envDefault("NNTMUX_PREDB_NET_BASE_URL", "https://api.predb.net"),
				Enabled: boolEnvDefault("NNTMUX_METADATA_SOURCE_PREDB_NET", true),
			},
			"predb-ovh": {
				Source:  "predb-ovh",
				BaseURL: envDefault("NNTMUX_PREDB_OVH_BASE_URL", "https://predb.ovh/api/v1"),
				Enabled: boolEnvDefault("NNTMUX_METADATA_SOURCE_PREDB_OVH", true),
			},
			"xrel": {
				Source:  "xrel",
				BaseURL: envDefault("NNTMUX_XREL_BASE_URL", "https://api.xrel.to/v2"),
				Enabled: boolEnvDefault("NNTMUX_METADATA_SOURCE_XREL", true),
			},
			"xrel-p2p": {
				Source:  "xrel-p2p",
				BaseURL: envDefault("NNTMUX_XREL_BASE_URL", "https://api.xrel.to/v2"),
				Enabled: boolEnvDefault("NNTMUX_METADATA_SOURCE_XREL_P2P", true),
			},
		},
	}
}

func EnrichSearchProviderHits(ctx context.Context, plan RefreshDryRunPlan, client SearchProviderClient, sources []string, limit int, sleep time.Duration) (RefreshDryRunPlan, SearchProviderFetchSummary, error) {
	summary := SearchProviderFetchSummary{Queries: len(plan.SearchQueries)}
	selected := selectedSearchProviders(sources)
	if len(selected) == 0 || len(plan.SearchQueries) == 0 {
		summary.Skipped = len(plan.SearchQueries)
		plan.SearchQueries = nil
		return plan, summary, nil
	}
	if plan.SearchProviderHits == nil {
		plan.SearchProviderHits = map[string][]SearchProviderHit{}
	}

	hitQueries := map[string]struct{}{}
	for queryIndex, query := range plan.SearchQueries {
		for _, source := range selected {
			config, ok := client.Sources[source]
			if !ok || !config.Enabled {
				summary.Skipped++
				continue
			}

			summary.Queried++
			hits, err := client.Search(ctx, config, query, min(10, max(1, limit)))
			if err != nil {
				return plan, summary, err
			}
			if len(hits) == 0 {
				summary.Failed++
			} else {
				summary.Found++
				summary.Hits += len(hits)
				plan.SearchProviderHits[SearchProviderKey(source, query)] = hits
				hitQueries[query] = struct{}{}
			}
		}

		if sleep > 0 && queryIndex < len(plan.SearchQueries)-1 {
			timer := time.NewTimer(sleep)
			select {
			case <-ctx.Done():
				timer.Stop()
				return plan, summary, ctx.Err()
			case <-timer.C:
			}
		}
	}

	filtered := plan.SearchQueries[:0]
	for _, query := range plan.SearchQueries {
		if _, ok := hitQueries[query]; ok {
			filtered = append(filtered, query)
		}
	}
	plan.SearchQueries = filtered

	return plan, summary, nil
}

func (c SearchProviderClient) Search(ctx context.Context, config SearchProviderConfig, query string, limit int) ([]SearchProviderHit, error) {
	endpoint, err := searchProviderURL(config, query, limit)
	if err != nil {
		return nil, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return nil, err
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
			return nil, ctx.Err()
		}

		return nil, nil
	}
	defer resp.Body.Close()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, nil
	}

	var payload map[string]any
	if err := json.NewDecoder(resp.Body).Decode(&payload); err != nil {
		return nil, nil
	}

	return parseSearchProviderHits(config.Source, payload), nil
}

func selectedSearchProviders(sources []string) []string {
	if len(sources) == 0 {
		return []string{"predb-net", "predb-ovh", "xrel", "xrel-p2p"}
	}

	selected := []string{}
	seen := map[string]struct{}{}
	for _, source := range sources {
		source = strings.ToLower(strings.TrimSpace(source))
		if source == "all" {
			return []string{"predb-net", "predb-ovh", "xrel", "xrel-p2p"}
		}
		if _, ok := map[string]struct{}{"predb-net": {}, "predb-ovh": {}, "xrel": {}, "xrel-p2p": {}}[source]; !ok {
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

func searchProviderURL(config SearchProviderConfig, query string, limit int) (string, error) {
	baseURL := strings.TrimRight(strings.TrimSpace(config.BaseURL), "/")
	if baseURL == "" {
		return "", fmt.Errorf("metadata provider %s base URL is required", config.Source)
	}

	values := url.Values{}
	values.Set("q", query)
	switch config.Source {
	case "predb-net":
		values.Set("limit", strconv.Itoa(limit))
		return baseURL + "/?" + values.Encode(), nil
	case "predb-ovh":
		values.Set("count", strconv.Itoa(limit))
		return baseURL + "/?" + values.Encode(), nil
	case "xrel", "xrel-p2p":
		values.Set("limit", strconv.Itoa(limit))
		if config.Source == "xrel-p2p" {
			values.Set("scene", "0")
			values.Set("p2p", "1")
		} else {
			values.Set("scene", "1")
			values.Set("p2p", "0")
		}
		return baseURL + "/search/releases.json?" + values.Encode(), nil
	default:
		return "", fmt.Errorf("unsupported metadata search provider %q", config.Source)
	}
}

func parseSearchProviderHits(source string, payload map[string]any) []SearchProviderHit {
	var rows []any
	switch source {
	case "predb-net":
		rows = anySlice(payload["data"])
	case "predb-ovh":
		rows = anySlice(pathValue(payload, "data", "rows"))
		if rows == nil {
			rows = anySlice(payload["data"])
		}
	case "xrel", "xrel-p2p":
		rows = anySlice(payload["list"])
		if rows == nil {
			rows = anySlice(payload["results"])
		}
	}

	hits := []SearchProviderHit{}
	seen := map[string]struct{}{}
	for _, item := range rows {
		row, ok := item.(map[string]any)
		if !ok {
			continue
		}

		title := searchProviderTitle(source, row)
		if title == "" {
			continue
		}
		if _, ok := seen[title]; ok {
			continue
		}
		seen[title] = struct{}{}
		hits = append(hits, SearchProviderHit{Source: source, Title: title})
	}

	return hits
}

func searchProviderTitle(source string, row map[string]any) string {
	switch source {
	case "predb-net":
		return stringField(row, "release")
	case "predb-ovh":
		title := stringField(row, "name")
		if title == "" {
			title = stringField(row, "release")
		}
		return title
	case "xrel", "xrel-p2p":
		title := stringField(row, "dirname")
		if title == "" {
			title = stringField(row, "release_name")
		}
		return title
	default:
		return ""
	}
}

func pathValue(row map[string]any, keys ...string) any {
	var current any = row
	for _, key := range keys {
		currentMap, ok := current.(map[string]any)
		if !ok {
			return nil
		}
		current = currentMap[key]
	}

	return current
}

func anySlice(value any) []any {
	rows, ok := value.([]any)
	if !ok {
		return nil
	}

	return rows
}

func envDefault(key string, fallback string) string {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}

	return value
}

func SearchProviderFetchSummaryText(summary SearchProviderFetchSummary) string {
	return fmt.Sprintf(
		"metadata-search-providers: queries=%d queried=%d found=%d failed=%d hits=%d skipped=%d\n",
		summary.Queries,
		summary.Queried,
		summary.Found,
		summary.Failed,
		summary.Hits,
		summary.Skipped,
	)
}
