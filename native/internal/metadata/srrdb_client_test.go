package metadata

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestSrrdbClientDetailsParsesValidFilesOnly(t *testing.T) {
	t.Parallel()

	var requestedPath string
	var userAgent string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requestedPath = r.URL.Path
		userAgent = r.Header.Get("User-Agent")
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{
			"name": "Movie.Name.2026.1080p.BluRay.x264-GRP",
			"files": [
				{"name": "movie.r00", "size": 101, "crc": "1111aaaa"},
				{"name": "", "size": 102, "crc": "2222BBBB"},
				{"name": "movie.bad", "size": 0, "crc": "3333CCCC"},
				{"name": "movie.invalid", "size": 103, "crc": "not-crc"}
			]
		}`))
	}))
	defer server.Close()

	details, ok, err := (SrrdbClient{BaseURL: server.URL, UserAgent: "nntmux-external-metadata/1.0"}).Details(
		context.Background(),
		"Movie.Name.2026.1080p.BluRay.x264-GRP",
	)
	if err != nil {
		t.Fatalf("Details error = %v", err)
	}
	if !ok {
		t.Fatal("Details ok = false, want true")
	}
	if requestedPath != "/details/Movie.Name.2026.1080p.BluRay.x264-GRP" {
		t.Fatalf("requested path = %q", requestedPath)
	}
	if userAgent != "nntmux-external-metadata/1.0" {
		t.Fatalf("user-agent = %q", userAgent)
	}
	if len(details.Files) != 1 {
		t.Fatalf("files = %#v, want one valid file", details.Files)
	}
	file := details.Files[0]
	if file.Name != "movie.r00" || file.CRC != "1111AAAA" || file.Size != 101 {
		t.Fatalf("file = %#v", file)
	}
}

func TestSrrdbClientDetailsTreatsNonSuccessAsMissing(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "missing", http.StatusNotFound)
	}))
	defer server.Close()

	_, ok, err := (SrrdbClient{BaseURL: server.URL}).Details(context.Background(), "Missing.Release")
	if err != nil {
		t.Fatalf("Details error = %v", err)
	}
	if ok {
		t.Fatal("Details ok = true, want false")
	}
}

func TestSrrdbClientSearchByArchiveCRCParsesReleaseHits(t *testing.T) {
	t.Parallel()

	var requestedPath string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requestedPath = r.URL.Path
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{
			"results": [
				{"release": "Provider.Movie.2026.1080p.BluRay.x264-GRP", "category": "movies"},
				{"name": "Fallback.Name.2026.1080p-GRP"},
				{"dirname": "Dirname.Release.2026.1080p-GRP"},
				{"release": ""},
				{"release": "Provider.Movie.2026.1080p.BluRay.x264-GRP"}
			]
		}`))
	}))
	defer server.Close()

	hits, err := (SrrdbClient{BaseURL: server.URL}).SearchByArchiveCRC(context.Background(), "aabbccdd", 15000000, 10)
	if err != nil {
		t.Fatalf("SearchByArchiveCRC error = %v", err)
	}
	if requestedPath != "/search/archive-crc:AABBCCDD/archive-size:15000000" {
		t.Fatalf("requested path = %q", requestedPath)
	}
	if len(hits) != 3 {
		t.Fatalf("hits = %#v, want three unique titled hits", hits)
	}
	if hits[0].Title != "Provider.Movie.2026.1080p.BluRay.x264-GRP" || hits[1].Title != "Fallback.Name.2026.1080p-GRP" || hits[2].Title != "Dirname.Release.2026.1080p-GRP" {
		t.Fatalf("hits = %#v", hits)
	}
}

func TestSrrdbClientFromEnvHonorsDisabledSource(t *testing.T) {
	t.Setenv("NNTMUX_METADATA_SOURCE_SRRDB", "false")

	client := SrrdbClientFromEnv()

	if !client.Disabled {
		t.Fatal("Disabled = false, want true")
	}
}
