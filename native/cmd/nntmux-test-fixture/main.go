package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"

	"nntmux-native/internal/safety"
	"nntmux-native/internal/testdb"

	_ "github.com/go-sql-driver/mysql"
)

const (
	backfillFixture        = "backfill"
	binariesFixture        = "binaries"
	hashedFixnamesFixture  = "hashed-fixnames"
	metadataRefreshFixture = "metadata-refresh"
	perGroupFixture        = "per-group"
	postAdditionalFixture  = "post-additional"
	postAmazonFixture      = "post-amazon"
	postMoviesFixture      = "post-movies"
	postTVFixture          = "post-tv"
	releasesFixture        = "releases"
	removeCrapFixture      = "removecrap"
)

func main() {
	os.Exit(run(os.Args[1:], os.Stdout, os.Stderr))
}

func run(args []string, stdout io.Writer, stderr io.Writer) int {
	flags := flag.NewFlagSet("nntmux-test-fixture", flag.ContinueOnError)
	flags.SetOutput(stderr)

	fixture := flags.String("fixture", hashedFixnamesFixture, "fixture to manage")
	action := flags.String("action", "seed", "fixture action: seed or fingerprint")
	dsn := flags.String("mysql-dsn", os.Getenv("NNTMUX_NATIVE_MYSQL_DSN"), "MariaDB DSN for the native test database")

	if err := flags.Parse(args); err != nil {
		return 2
	}

	if !isSupportedFixture(*fixture) {
		fmt.Fprintf(stderr, "unsupported fixture %q\n", *fixture)
		return 2
	}
	if *action != "seed" && *action != "fingerprint" {
		fmt.Fprintf(stderr, "unsupported action %q\n", *action)
		return 2
	}
	if *dsn == "" {
		fmt.Fprintln(stderr, "--mysql-dsn or NNTMUX_NATIVE_MYSQL_DSN is required")
		return 2
	}

	ctx := context.Background()
	db, err := sql.Open("mysql", *dsn)
	if err != nil {
		fmt.Fprintf(stderr, "open mysql: %v\n", err)
		return 1
	}
	defer db.Close()

	if err := safety.ValidateNativeFixtureMySQL(ctx, db, *dsn); err != nil {
		fmt.Fprintf(stderr, "validate native test mysql: %v\n", err)
		return 1
	}

	unlock, err := acquireFixtureLock(ctx, db)
	if err != nil {
		fmt.Fprintf(stderr, "acquire fixture lock: %v\n", err)
		return 1
	}
	defer unlock()

	if *action == "fingerprint" {
		if *fixture != hashedFixnamesFixture {
			fmt.Fprintf(stderr, "unsupported action %q for fixture %q\n", *action, *fixture)
			return 2
		}
		fingerprint, err := testdb.HashedFixTableFingerprint(ctx, db)
		if err != nil {
			fmt.Fprintf(stderr, "fingerprint hashed-fixnames fixture: %v\n", err)
			return 1
		}
		if err := json.NewEncoder(stdout).Encode(fingerprint); err != nil {
			fmt.Fprintf(stderr, "encode hashed-fixnames fingerprint: %v\n", err)
			return 1
		}
		return 0
	}

	if _, err := db.ExecContext(ctx, "SET FOREIGN_KEY_CHECKS=0"); err != nil {
		fmt.Fprintf(stderr, "disable foreign key checks: %v\n", err)
		return 1
	}
	defer db.ExecContext(ctx, "SET FOREIGN_KEY_CHECKS=1")

	if err := resetFixture(ctx, db, *fixture); err != nil {
		fmt.Fprintf(stderr, "reset %s fixture: %v\n", *fixture, err)
		return 1
	}
	if err := seedFixture(ctx, db, *fixture); err != nil {
		fmt.Fprintf(stderr, "seed %s fixture: %v\n", *fixture, err)
		return 1
	}

	fmt.Fprintf(stdout, "seeded fixture=%s\n", *fixture)
	return 0
}

func isSupportedFixture(fixture string) bool {
	switch fixture {
	case backfillFixture, binariesFixture, hashedFixnamesFixture, metadataRefreshFixture, perGroupFixture, postAdditionalFixture, postAmazonFixture, postMoviesFixture, postTVFixture, releasesFixture, removeCrapFixture:
		return true
	default:
		return false
	}
}

func resetFixture(ctx context.Context, db *sql.DB, fixture string) error {
	switch fixture {
	case backfillFixture:
		return testdb.ResetBackfillTables(ctx, db)
	case binariesFixture:
		return testdb.ResetBinariesTables(ctx, db)
	case hashedFixnamesFixture:
		return testdb.ResetHashedFixTables(ctx, db)
	case metadataRefreshFixture:
		return testdb.ResetMetadataRefreshTables(ctx, db)
	case perGroupFixture:
		return testdb.ResetPerGroupQueueTables(ctx, db)
	case postAdditionalFixture, postAmazonFixture, postMoviesFixture, postTVFixture:
		return testdb.ResetPostprocessTables(ctx, db)
	case releasesFixture:
		return testdb.ResetReleaseQueueTables(ctx, db)
	case removeCrapFixture:
		return testdb.ResetRemoveCrapTables(ctx, db)
	default:
		return fmt.Errorf("unsupported fixture %q", fixture)
	}
}

func seedFixture(ctx context.Context, db *sql.DB, fixture string) error {
	switch fixture {
	case backfillFixture:
		return testdb.SeedBackfillRows(ctx, db)
	case binariesFixture:
		return testdb.SeedBinariesRows(ctx, db)
	case hashedFixnamesFixture:
		return testdb.SeedHashedFixRows(ctx, db)
	case metadataRefreshFixture:
		return testdb.SeedMetadataRefreshRows(ctx, db)
	case perGroupFixture:
		return testdb.SeedPerGroupQueueRows(ctx, db)
	case postAdditionalFixture:
		return testdb.SeedPostAdditionalRows(ctx, db)
	case postAmazonFixture:
		return testdb.SeedPostAmazonRows(ctx, db)
	case postMoviesFixture:
		return testdb.SeedPostMovieRows(ctx, db)
	case postTVFixture:
		return testdb.SeedPostTVRows(ctx, db)
	case releasesFixture:
		return testdb.SeedReleaseQueueRows(ctx, db)
	case removeCrapFixture:
		return testdb.SeedRemoveCrapRows(ctx, db)
	default:
		return fmt.Errorf("unsupported fixture %q", fixture)
	}
}

func acquireFixtureLock(ctx context.Context, db *sql.DB) (func(), error) {
	conn, err := db.Conn(ctx)
	if err != nil {
		return nil, fmt.Errorf("open mysql lock connection: %w", err)
	}

	var acquired int
	if err := conn.QueryRowContext(ctx, "SELECT GET_LOCK('nntmux_native_integration_schema_test', 30)").Scan(&acquired); err != nil {
		_ = conn.Close()
		return nil, fmt.Errorf("get mysql lock: %w", err)
	}
	if acquired != 1 {
		_ = conn.Close()
		return nil, fmt.Errorf("get mysql lock returned %d", acquired)
	}

	return func() {
		defer conn.Close()
		_, _ = conn.ExecContext(ctx, "SELECT RELEASE_LOCK('nntmux_native_integration_schema_test')")
	}, nil
}
