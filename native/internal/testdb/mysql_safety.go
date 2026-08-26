package testdb

import (
	"context"
	"database/sql"
	"testing"

	"nntmux-native/internal/safety"
)

func RequireSafeMySQL(t testing.TB, ctx context.Context, db *sql.DB, dsn string) {
	t.Helper()

	if err := safety.ValidateNativeTestMySQL(ctx, db, dsn); err != nil {
		t.Fatal(err)
	}
}
