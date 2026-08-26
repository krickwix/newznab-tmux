package safety

import (
	"context"
	"database/sql"
	"fmt"
	"os"
	"strings"

	"github.com/go-sql-driver/mysql"
)

const AllowDestructiveTestDBEnv = "NNTMUX_NATIVE_ALLOW_DESTRUCTIVE_TEST_DB"
const AllowCommittedTestDBEnv = "NNTMUX_NATIVE_ALLOW_COMMITTED_TEST_DB"
const AllowEvalFixtureDBEnv = "NNTMUX_NATIVE_ALLOW_EVAL_FIXTURE_DB"
const AllowProductionCommitEnv = "NNTMUX_NATIVE_ALLOW_PRODUCTION_COMMIT"

func ValidateNativeTestMySQL(ctx context.Context, db *sql.DB, dsn string) error {
	return validateNativeTestMySQL(ctx, db, dsn, IsAllowedNativeTestDatabase)
}

func validateNativeTestMySQL(ctx context.Context, db *sql.DB, dsn string, allowedDatabase func(string) bool) error {
	if os.Getenv(AllowDestructiveTestDBEnv) != "1" {
		return fmt.Errorf("%s=1 is required before native tests can mutate MariaDB tables", AllowDestructiveTestDBEnv)
	}

	config, err := mysql.ParseDSN(dsn)
	if err != nil {
		return fmt.Errorf("parse mysql DSN for safety check: %w", err)
	}
	if !allowedDatabase(config.DBName) {
		return fmt.Errorf("refusing native test mutation against database %q", config.DBName)
	}

	var currentDatabase string
	if err := db.QueryRowContext(ctx, "SELECT DATABASE()").Scan(&currentDatabase); err != nil {
		return fmt.Errorf("read current mysql database for safety check: %w", err)
	}
	if currentDatabase != config.DBName {
		return fmt.Errorf("mysql DSN database %q does not match current database %q", config.DBName, currentDatabase)
	}

	return nil
}

func ValidateNativeTestMySQLCommit(ctx context.Context, db *sql.DB, dsn string) error {
	if err := validateNativeTestMySQL(ctx, db, dsn, IsAllowedNativeCommitDatabase); err != nil {
		return err
	}
	if os.Getenv(AllowCommittedTestDBEnv) != "1" {
		return fmt.Errorf("%s=1 is required before native tests can commit MariaDB table changes", AllowCommittedTestDBEnv)
	}

	return nil
}

func ValidateProductionMySQL(ctx context.Context, db *sql.DB, dsn string) error {
	config, err := mysql.ParseDSN(dsn)
	if err != nil {
		return fmt.Errorf("parse mysql DSN for safety check: %w", err)
	}
	if config.DBName == "" {
		return fmt.Errorf("mysql DSN must include a database name")
	}

	var currentDatabase string
	if err := db.QueryRowContext(ctx, "SELECT DATABASE()").Scan(&currentDatabase); err != nil {
		return fmt.Errorf("read current mysql database for safety check: %w", err)
	}
	if currentDatabase != config.DBName {
		return fmt.Errorf("mysql DSN database %q does not match current database %q", config.DBName, currentDatabase)
	}

	return nil
}

func AllowsProductionCommit(job string) bool {
	return job == "removecrap" && os.Getenv(AllowProductionCommitEnv) == "removecrap"
}

func ValidateNativeFixtureMySQL(ctx context.Context, db *sql.DB, dsn string) error {
	if os.Getenv(AllowDestructiveTestDBEnv) != "1" {
		return fmt.Errorf("%s=1 is required before native tests can mutate MariaDB tables", AllowDestructiveTestDBEnv)
	}

	config, err := mysql.ParseDSN(dsn)
	if err != nil {
		return fmt.Errorf("parse mysql DSN for safety check: %w", err)
	}
	if !IsAllowedNativeFixtureDatabase(config.DBName, os.Getenv(AllowEvalFixtureDBEnv) == "1") {
		return fmt.Errorf("refusing native fixture mutation against database %q", config.DBName)
	}

	var currentDatabase string
	if err := db.QueryRowContext(ctx, "SELECT DATABASE()").Scan(&currentDatabase); err != nil {
		return fmt.Errorf("read current mysql database for safety check: %w", err)
	}
	if currentDatabase != config.DBName {
		return fmt.Errorf("mysql DSN database %q does not match current database %q", config.DBName, currentDatabase)
	}

	return nil
}

func IsAllowedNativeTestDatabase(name string) bool {
	return name == "nntmux_native_test" ||
		strings.HasPrefix(name, "nntmux_native_test_") ||
		strings.HasSuffix(name, "_native_test")
}

func IsAllowedNativeCommitDatabase(name string) bool {
	return IsAllowedNativeTestDatabase(name) ||
		name == "nntmux_native_eval"
}

func IsAllowedNativeFixtureDatabase(name string, allowEval bool) bool {
	return IsAllowedNativeTestDatabase(name) ||
		(allowEval && name == "nntmux_native_eval")
}
