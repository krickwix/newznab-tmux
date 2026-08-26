package metadata

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestPreviewSourceClientCountsNzbIndexHits(t *testing.T) {
	t.Parallel()

	var rawQuery string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		rawQuery = r.URL.RawQuery
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{
			"data": {
				"content": [
					{"name": "Preview.Movie.2026.1080p-GRP"},
					{"name": ""},
					{"name": "Preview.Movie.2026.2160p-GRP"}
				]
			}
		}`))
	}))
	defer server.Close()

	hits, err := (PreviewSourceClient{
		NzbIndexBaseURL: server.URL,
		NzbIndexAPIKey:  "nzbindex-secret",
	}).SearchNzbIndex(context.Background(), "Movie 2026", 7)
	if err != nil {
		t.Fatalf("SearchNzbIndex error = %v", err)
	}
	if rawQuery != "key=nzbindex-secret&max=7&q=Movie+2026" {
		t.Fatalf("raw query = %q", rawQuery)
	}
	if hits != 2 {
		t.Fatalf("hits = %d, want 2", hits)
	}
}

func TestFetchPreviewSourcesSkipsDisabledOrUnconfiguredSources(t *testing.T) {
	t.Parallel()

	summary, err := FetchPreviewSources(context.Background(), []string{"Movie 2026"}, PreviewSourceClient{}, []string{"nzbindex", "internet-archive-predb"}, 10, 0)
	if err != nil {
		t.Fatalf("FetchPreviewSources error = %v", err)
	}
	if summary.Queries != 1 || summary.Queried != 0 || summary.Skipped != 2 || summary.BulkSkipped != 0 {
		t.Fatalf("summary = %#v", summary)
	}
}

func TestFetchPreviewSourcesCountsInternetArchiveBulkHandoff(t *testing.T) {
	t.Parallel()

	summary, err := FetchPreviewSources(context.Background(), nil, PreviewSourceClient{InternetArchivePredbEnabled: true}, []string{"internet-archive-predb"}, 10, 0)
	if err != nil {
		t.Fatalf("FetchPreviewSources error = %v", err)
	}
	if summary.Queries != 0 || summary.Queried != 0 || summary.Skipped != 1 || summary.BulkSkipped != 1 {
		t.Fatalf("summary = %#v", summary)
	}
}

func TestFetchPreviewSourcesQueriesOriginalPreviewCandidates(t *testing.T) {
	t.Parallel()

	queried := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		queried++
		if r.URL.Query().Get("max") != "3" {
			t.Fatalf("max = %q, want 3", r.URL.Query().Get("max"))
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data": {"content": [{"name": "Preview.Hit-GRP"}]}}`))
	}))
	defer server.Close()

	summary, err := FetchPreviewSources(context.Background(), []string{"one", "two", "three", "four"}, PreviewSourceClient{
		NzbIndexBaseURL: server.URL,
		NzbIndexAPIKey:  "nzbindex-secret",
		NzbIndexEnabled: true,
	}, []string{"nzbindex"}, 3, 0)
	if err != nil {
		t.Fatalf("FetchPreviewSources error = %v", err)
	}
	if queried != 3 {
		t.Fatalf("queried server = %d, want 3", queried)
	}
	if summary.Queries != 4 || summary.Queried != 3 || summary.Found != 3 || summary.Hits != 3 || summary.Skipped != 0 {
		t.Fatalf("summary = %#v", summary)
	}
}

func TestSelectedPreviewSourcesHonorsAllAndExplicitSources(t *testing.T) {
	t.Parallel()

	if got := selectedPreviewSources([]string{"all"}); len(got) != 2 || got[0] != "internet-archive-predb" || got[1] != "nzbindex" {
		t.Fatalf("all selected preview sources = %#v", got)
	}
	if got := selectedPreviewSources([]string{"srrdb", "nzbindex"}); len(got) != 1 || got[0] != "nzbindex" {
		t.Fatalf("explicit selected preview sources = %#v", got)
	}
	if got := selectedPreviewSources([]string{"predb-net"}); len(got) != 0 {
		t.Fatalf("rename-authoritative selected preview sources = %#v", got)
	}
}
