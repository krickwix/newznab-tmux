package testdb

import (
	"context"
	"database/sql"
	"testing"

	_ "github.com/go-sql-driver/mysql"
)

func TestFirstLaneFixturesSeedQueueRows(t *testing.T) {
	dsn := nativeTestDSN(t)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		t.Fatalf("open mysql: %v", err)
	}
	defer db.Close()

	ctx := context.Background()
	unlock := acquireIntegrationLock(t, ctx, db)
	defer unlock()

	RequireSafeMySQL(t, ctx, db, dsn)

	tests := []struct {
		name  string
		reset func(context.Context, *sql.DB) error
		seed  func(context.Context, *sql.DB) error
		query string
		want  int
	}{
		{
			name:  "binaries",
			reset: ResetBinariesTables,
			seed:  SeedBinariesRows,
			query: "SELECT COUNT(*) FROM usenet_groups WHERE active = 1",
			want:  4,
		},
		{
			name:  "backfill",
			reset: ResetBackfillTables,
			seed:  SeedBackfillRows,
			query: "SELECT COUNT(*) FROM usenet_groups WHERE backfill = 1",
			want:  7,
		},
		{
			name:  "releases",
			reset: ResetReleaseQueueTables,
			seed:  SeedReleaseQueueRows,
			query: "SELECT COUNT(*) FROM collections",
			want:  4,
		},
		{
			name:  "metadata-refresh",
			reset: ResetMetadataRefreshTables,
			seed:  SeedMetadataRefreshRows,
			query: "SELECT COUNT(*) FROM release_files",
			want:  2,
		},
		{
			name:  "per-group",
			reset: ResetPerGroupQueueTables,
			seed:  SeedPerGroupQueueRows,
			query: "SELECT COUNT(*) FROM usenet_groups WHERE active = 1 OR backfill = 1",
			want:  5,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if err := tt.reset(ctx, db); err != nil {
				t.Fatal(err)
			}
			if err := tt.seed(ctx, db); err != nil {
				t.Fatal(err)
			}

			var got int
			if err := db.QueryRowContext(ctx, tt.query).Scan(&got); err != nil {
				t.Fatalf("count seeded rows: %v", err)
			}
			if got != tt.want {
				t.Fatalf("seeded rows = %d, want %d", got, tt.want)
			}
		})
	}
}
