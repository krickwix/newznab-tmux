package main

import (
	"bytes"
	"strings"
	"testing"
)

func TestRunRejectsUnsupportedActionBeforeOpeningDB(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--fixture", "hashed-fixnames",
		"--action", "drop",
		"--mysql-dsn", "nntmux:nntmux@tcp(localhost:3306)/nntmux_native_test?parseTime=true",
	}, &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want validation failure", code)
	}
	if !strings.Contains(stderr.String(), `unsupported action "drop"`) {
		t.Fatalf("stderr = %q, want unsupported action error", stderr.String())
	}
	if stdout.Len() != 0 {
		t.Fatalf("stdout = %q, want empty output", stdout.String())
	}
}

func TestRunAcceptsFirstLaneFixturesDuringValidation(t *testing.T) {
	t.Parallel()

	for _, fixture := range []string{
		"binaries",
		"backfill",
		"releases",
		"metadata-refresh",
		"removecrap",
		"post-tv",
		"post-movies",
		"post-amazon",
		"post-additional",
	} {
		t.Run(fixture, func(t *testing.T) {
			t.Parallel()

			var stdout bytes.Buffer
			var stderr bytes.Buffer

			code := run([]string{
				"--fixture", fixture,
				"--action", "seed",
				"--mysql-dsn", "",
			}, &stdout, &stderr)

			if code != 2 {
				t.Fatalf("run exit = %d, want validation failure after fixture acceptance", code)
			}
			if strings.Contains(stderr.String(), "unsupported fixture") {
				t.Fatalf("stderr = %q, want fixture accepted before DSN validation", stderr.String())
			}
			if !strings.Contains(stderr.String(), "--mysql-dsn or NNTMUX_NATIVE_MYSQL_DSN is required") {
				t.Fatalf("stderr = %q, want missing DSN error", stderr.String())
			}
			if stdout.Len() != 0 {
				t.Fatalf("stdout = %q, want empty output", stdout.String())
			}
		})
	}
}

func TestRunRejectsMissingDSN(t *testing.T) {
	t.Parallel()

	var stdout bytes.Buffer
	var stderr bytes.Buffer

	code := run([]string{
		"--fixture", "hashed-fixnames",
		"--action", "fingerprint",
		"--mysql-dsn", "",
	}, &stdout, &stderr)

	if code != 2 {
		t.Fatalf("run exit = %d, want validation failure", code)
	}
	if !strings.Contains(stderr.String(), "--mysql-dsn or NNTMUX_NATIVE_MYSQL_DSN is required") {
		t.Fatalf("stderr = %q, want missing DSN error", stderr.String())
	}
	if stdout.Len() != 0 {
		t.Fatalf("stdout = %q, want empty output", stdout.String())
	}
}
