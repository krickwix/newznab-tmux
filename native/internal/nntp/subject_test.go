package nntp

import "testing"

func TestParseOverviewSubjectExtractsCommonYEncPartMetadata(t *testing.T) {
	tests := []struct {
		name       string
		subject    string
		binaryName string
		partNumber int
		totalParts int
	}{
		{
			name:       "quoted yenc parens",
			subject:    `"Movie.Release.2026.mkv" yEnc (001/123)`,
			binaryName: "Movie.Release.2026.mkv",
			partNumber: 1,
			totalParts: 123,
		},
		{
			name:       "brackets",
			subject:    `Movie.Release.2026.vol001+02.par2 [7/42]`,
			binaryName: "Movie.Release.2026.vol001+02.par2",
			partNumber: 7,
			totalParts: 42,
		},
		{
			name:       "unknown falls back to single part",
			subject:    "Unstructured Subject",
			binaryName: "Unstructured Subject",
			partNumber: 1,
			totalParts: 1,
		},
		{
			name:       "invalid denominator falls back",
			subject:    "Broken.Part yEnc (3/2)",
			binaryName: "Broken.Part yEnc (3/2)",
			partNumber: 1,
			totalParts: 1,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			binaryName, partNumber, totalParts := ParseOverviewSubject(tt.subject)
			if binaryName != tt.binaryName || partNumber != tt.partNumber || totalParts != tt.totalParts {
				t.Fatalf(
					"ParseOverviewSubject(%q) = (%q, %d, %d), want (%q, %d, %d)",
					tt.subject,
					binaryName,
					partNumber,
					totalParts,
					tt.binaryName,
					tt.partNumber,
					tt.totalParts,
				)
			}
		})
	}
}
