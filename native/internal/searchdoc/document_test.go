package searchdoc

import (
	"encoding/json"
	"strings"
	"testing"
	"time"
)

func TestNormalizeReleaseDocumentMatchesPHPSearchIndexShape(t *testing.T) {
	postdate := time.Date(2026, 6, 15, 9, 0, 0, 0, time.UTC)
	adddate := time.Date(2026, 6, 15, 10, 0, 0, 0, time.UTC)

	document := NormalizeReleaseDocument(ReleaseRow{
		ID:             91001,
		Name:           "Resolved.Release.2026.1080p-GRP",
		SearchName:     "Resolved.Release.2026.1080p-GRP",
		FromName:       "native-worker@example.invalid",
		CategoryID:     5040,
		FileName:       "resolved.sample.mkv resolved.sample.nfo",
		IMDBID:         "tt7654321",
		TMDBID:         7001,
		TraktID:        8001,
		TVDB:           9001,
		TVMaze:         9002,
		TVRage:         9003,
		VideosID:       6001,
		MovieInfoID:    5001,
		Size:           12345678,
		PostDate:       postdate,
		AddDate:        adddate,
		TotalPart:      42,
		Grabs:          3,
		PasswordStatus: 0,
		GroupID:        101,
		NZBStatus:      1,
		HasPreview:     0,
	})

	if document["id"] != nil {
		t.Fatalf("document contains id = %#v; fingerprint document must match Manticore replace body", document["id"])
	}
	if got := document["categories_id"]; got != "5040" {
		t.Fatalf("categories_id = %#v, want PHP-compatible numeric string", got)
	}
	if got := document["filename"]; got != "resolved.sample.mkv resolved.sample.nfo" {
		t.Fatalf("filename = %#v", got)
	}
	if got := document["postdate_ts"]; got != int64(1781514000) {
		t.Fatalf("postdate_ts = %#v", got)
	}
	if got := document["adddate_ts"]; got != int64(1781517600) {
		t.Fatalf("adddate_ts = %#v", got)
	}
	if got := document["imdbid"]; got != "tt7654321" {
		t.Fatalf("imdbid = %#v", got)
	}
}

func TestFingerprintIsStableForCanonicalDocumentJSON(t *testing.T) {
	left := ReleaseDocument{
		"searchname":    "Resolved.Release.2026.1080p-GRP",
		"categories_id": "5040",
		"filename":      "resolved.sample.mkv",
	}
	right := ReleaseDocument{
		"filename":      "resolved.sample.mkv",
		"categories_id": "5040",
		"searchname":    "Resolved.Release.2026.1080p-GRP",
	}

	leftFingerprint, err := Fingerprint(left)
	if err != nil {
		t.Fatalf("left fingerprint: %v", err)
	}
	rightFingerprint, err := Fingerprint(right)
	if err != nil {
		t.Fatalf("right fingerprint: %v", err)
	}

	if leftFingerprint != rightFingerprint {
		t.Fatalf("fingerprints differ for equivalent documents: %s vs %s", leftFingerprint, rightFingerprint)
	}
	if len(leftFingerprint) != 64 {
		t.Fatalf("fingerprint length = %d, want sha256 hex", len(leftFingerprint))
	}
}

func TestParityReportDoesNotExposeRawReleaseDocumentFields(t *testing.T) {
	report := ParityReport{
		SchemaVersion:       1,
		Mode:                "native-search-document-parity",
		DryRun:              true,
		SourceJob:           "hashed-fixnames",
		SearchDocumentsSeen: 1,
		ReleaseDocuments: []ReleaseDocumentFingerprint{
			{ReleaseID: 91001, Fingerprint: "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"},
		},
		Writes: 0,
	}

	encoded, err := json.Marshal(report)
	if err != nil {
		t.Fatalf("marshal report: %v", err)
	}

	for _, leaked := range []string{
		"Resolved.Release.2026.1080p-GRP",
		"native-worker@example.invalid",
		"resolved.sample.mkv",
		"nntmux:nntmux",
		"redis_key",
	} {
		if strings.Contains(string(encoded), leaked) {
			t.Fatalf("report leaked %q: %s", leaked, encoded)
		}
	}
}
