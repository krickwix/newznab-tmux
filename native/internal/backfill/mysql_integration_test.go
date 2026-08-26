package backfill

import (
	"context"
	"database/sql"
	"os"
	"testing"
)

func nativeTestDSN(t testing.TB) string {
	t.Helper()

	dsn := os.Getenv("NNTMUX_NATIVE_MYSQL_DSN")
	if dsn == "" {
		t.Skip("NNTMUX_NATIVE_MYSQL_DSN is required for native backfill integration tests")
	}

	return dsn
}

func acquireIntegrationLock(t testing.TB, ctx context.Context, db *sql.DB) func() {
	t.Helper()

	conn, err := db.Conn(ctx)
	if err != nil {
		t.Fatalf("open mysql lock connection: %v", err)
	}

	var acquired int
	if err := conn.QueryRowContext(ctx, "SELECT GET_LOCK('nntmux_native_integration_schema_test', 30)").Scan(&acquired); err != nil {
		_ = conn.Close()
		t.Fatalf("get mysql lock: %v", err)
	}
	if acquired != 1 {
		_ = conn.Close()
		t.Fatalf("get mysql lock returned %d", acquired)
	}

	return func() {
		defer conn.Close()
		if _, err := conn.ExecContext(ctx, "SELECT RELEASE_LOCK('nntmux_native_integration_schema_test')"); err != nil {
			t.Fatalf("release mysql lock: %v", err)
		}
	}
}
