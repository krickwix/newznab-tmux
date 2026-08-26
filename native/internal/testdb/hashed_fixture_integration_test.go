package testdb

import (
	"context"
	"database/sql"
	"reflect"
	"testing"

	_ "github.com/go-sql-driver/mysql"
)

func TestHashedFixTableFingerprintCoversAllReleaseColumns(t *testing.T) {
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
	if err := ResetHashedFixTables(ctx, db); err != nil {
		t.Fatal(err)
	}
	if err := SeedHashedFixRows(ctx, db); err != nil {
		t.Fatal(err)
	}

	before, err := HashedFixTableFingerprint(ctx, db)
	if err != nil {
		t.Fatal(err)
	}

	if _, err := db.ExecContext(ctx, "UPDATE releases SET videos_id = 99, tv_episodes_id = 7, imdbid = 'tt1234567', iscategorized = 1 WHERE id = 100"); err != nil {
		t.Fatalf("update previously omitted release columns: %v", err)
	}

	after, err := HashedFixTableFingerprint(ctx, db)
	if err != nil {
		t.Fatal(err)
	}
	if reflect.DeepEqual(after, before) {
		t.Fatalf("fingerprint did not change after mutating release columns outside old narrow fingerprint")
	}
}
