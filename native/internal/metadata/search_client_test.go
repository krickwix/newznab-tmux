package metadata

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestSearchProviderClientParsesPredbNetHits(t *testing.T) {
	t.Parallel()

	var rawQuery string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		rawQuery = r.URL.RawQuery
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{
			"data": [
				{"release": "PredbNet.Movie.2026.1080p-GRP"},
				{"release": ""},
				{"release": "PredbNet.Movie.2026.1080p-GRP"}
			]
		}`))
	}))
	defer server.Close()

	hits, err := (SearchProviderClient{}).Search(context.Background(), SearchProviderConfig{
		Source:  "predb-net",
		BaseURL: server.URL,
		Enabled: true,
	}, "Movie 2026", 7)
	if err != nil {
		t.Fatalf("Search error = %v", err)
	}
	if rawQuery != "limit=7&q=Movie+2026" {
		t.Fatalf("raw query = %q", rawQuery)
	}
	if len(hits) != 1 || hits[0].Source != "predb-net" || hits[0].Title != "PredbNet.Movie.2026.1080p-GRP" {
		t.Fatalf("hits = %#v", hits)
	}
}

func TestSearchProviderClientParsesPredbOvhRowsEnvelope(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"data": {"rows": [{"name": "PredbOvh.Movie.2026-GRP"}]}}`))
	}))
	defer server.Close()

	hits, err := (SearchProviderClient{}).Search(context.Background(), SearchProviderConfig{
		Source:  "predb-ovh",
		BaseURL: server.URL,
		Enabled: true,
	}, "Movie 2026", 3)
	if err != nil {
		t.Fatalf("Search error = %v", err)
	}
	if len(hits) != 1 || hits[0].Source != "predb-ovh" || hits[0].Title != "PredbOvh.Movie.2026-GRP" {
		t.Fatalf("hits = %#v", hits)
	}
}

func TestSearchProviderClientParsesXrelHits(t *testing.T) {
	t.Parallel()

	var rawQuery string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		rawQuery = r.URL.RawQuery
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"list": [{"dirname": "Xrel.Movie.2026-GRP"}, {"release_name": "Fallback.Release.2026-GRP"}]}`))
	}))
	defer server.Close()

	hits, err := (SearchProviderClient{}).Search(context.Background(), SearchProviderConfig{
		Source:  "xrel-p2p",
		BaseURL: server.URL,
		Enabled: true,
	}, "Movie 2026", 5)
	if err != nil {
		t.Fatalf("Search error = %v", err)
	}
	if rawQuery != "limit=5&p2p=1&q=Movie+2026&scene=0" {
		t.Fatalf("raw query = %q", rawQuery)
	}
	if len(hits) != 2 || hits[0].Source != "xrel-p2p" || hits[0].Title != "Xrel.Movie.2026-GRP" || hits[1].Title != "Fallback.Release.2026-GRP" {
		t.Fatalf("hits = %#v", hits)
	}
}

func TestSelectedSearchProvidersHonorsAllAndExplicitSources(t *testing.T) {
	t.Parallel()

	if got := selectedSearchProviders([]string{"all"}); len(got) != 4 {
		t.Fatalf("all selected providers = %#v", got)
	}
	if got := selectedSearchProviders([]string{"srrdb", "predb-net", "xrel"}); len(got) != 2 || got[0] != "predb-net" || got[1] != "xrel" {
		t.Fatalf("explicit selected providers = %#v", got)
	}
	if got := selectedSearchProviders([]string{"nzbindex"}); len(got) != 0 {
		t.Fatalf("preview-only selected providers = %#v", got)
	}
}
