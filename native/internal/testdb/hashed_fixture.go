package testdb

import (
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"strings"
)

func ResetHashedFixTables(ctx context.Context, db *sql.DB) error {
	statements := []string{
		"DROP TABLE IF EXISTS native_worker_side_effects",
		"DROP TABLE IF EXISTS release_files",
		"DROP TABLE IF EXISTS par_hashes",
		"DROP TABLE IF EXISTS predb_crcs",
		"DROP TABLE IF EXISTS predb",
		"DROP TABLE IF EXISTS releases",
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			groups_id INT NOT NULL DEFAULT 0,
			size BIGINT NOT NULL DEFAULT 0,
			postdate DATETIME NULL,
			adddate DATETIME NULL,
			fromname VARCHAR(255) NULL,
			categories_id INT NOT NULL DEFAULT 10,
			videos_id INT NOT NULL DEFAULT 0,
			tv_episodes_id INT NOT NULL DEFAULT 0,
			imdbid VARCHAR(16) NULL,
			musicinfo_id VARCHAR(32) NULL,
			consoleinfo_id VARCHAR(32) NULL,
			bookinfo_id VARCHAR(32) NULL,
			predb_id INT NOT NULL DEFAULT 0,
			anidbid VARCHAR(32) NULL,
			isrenamed TINYINT NOT NULL DEFAULT 0,
			iscategorized TINYINT NOT NULL DEFAULT 0,
			proc_crc32 TINYINT NOT NULL DEFAULT 0,
			proc_hash16k TINYINT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE release_files (
			id INT AUTO_INCREMENT PRIMARY KEY,
			releases_id INT NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			size BIGINT NOT NULL DEFAULT 0,
			crc32 VARCHAR(32) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL
		)`,
		`CREATE TABLE predb (
			id INT AUTO_INCREMENT PRIMARY KEY,
			title VARCHAR(255) NOT NULL DEFAULT '',
			predate DATETIME NULL,
			source VARCHAR(64) NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE predb_crcs (
			id INT AUTO_INCREMENT PRIMARY KEY,
			predb_id INT NOT NULL,
			crchash VARCHAR(32) NOT NULL DEFAULT '',
			filesize BIGINT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE par_hashes (
			releases_id INT NOT NULL,
			hash VARCHAR(32) NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE native_worker_side_effects (
			id BIGINT AUTO_INCREMENT PRIMARY KEY,
			operation_key VARCHAR(191) NOT NULL,
			job VARCHAR(64) NOT NULL,
			effect VARCHAR(64) NOT NULL,
			release_id BIGINT UNSIGNED NOT NULL,
			status_column VARCHAR(32) NOT NULL,
			status_reason VARCHAR(64) NOT NULL,
			status_value TINYINT UNSIGNED NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			available_at TIMESTAMP(6) NULL,
			processed_at TIMESTAMP(6) NULL,
			last_error_code VARCHAR(64) NULL,
			created_at TIMESTAMP(6) NULL,
			updated_at TIMESTAMP(6) NULL,
			UNIQUE KEY ux_native_worker_side_effects_operation_key (operation_key),
			KEY ix_native_worker_side_effects_status_available (status, available_at, id),
			KEY ix_native_worker_side_effects_release_status (release_id, status)
		)`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			return fmt.Errorf("exec %q: %w", statement, err)
		}
	}

	return nil
}

func SeedHashedFixRows(ctx context.Context, db *sql.DB) error {
	statements := []string{
		`INSERT INTO predb (id, title, predate, source) VALUES
			(10, 'Predb.Match.2026.1080p.BluRay.x264-GRP', '2026-06-14 00:00:00', 'srrdb')`,
		`INSERT INTO predb_crcs (predb_id, crchash, filesize) VALUES
			(10, 'AABBCCDD', 15000000)`,
		`INSERT INTO releases (id, name, searchname, groups_id, size, postdate, adddate, fromname, categories_id, predb_id, anidbid, isrenamed, proc_crc32, proc_hash16k) VALUES
			(100, 'hash-target-crc-predb', 'Hash.Target.CRC.PreDB', 1, 15000000, '2026-06-15 12:05:00', '2026-06-15 12:05:00', 'poster@example', 20, 0, 0, 0, 0, 0),
			(102, 'hash-target-crc-miss', 'Hash.Target.CRC.Miss', 1, 10000000, '2026-06-15 12:03:00', '2026-06-15 12:03:00', 'poster@example', 20, 0, 0, 0, 0, 0),
			(300, 'hash-target-par-match', 'Hash.Target.Par.Match', 1, 40000000, '2026-06-15 12:02:00', '2026-06-15 12:02:00', 'poster@example', 20, 0, 0, 0, 0, 0),
			(301, 'hash-target-par-miss', 'Hash.Target.Par.Miss', 1, 50000000, '2026-06-15 12:01:00', '2026-06-15 12:01:00', 'poster@example', 20, 0, 0, 0, 0, 0),
			(400, 'known-par-release', 'Known.Par.Release.2026.2160p.WEB.x265-GRP', 1, 41000000, '2026-06-14 11:00:00', '2026-06-14 11:00:00', 'poster@example', 5040, 88, 0, 1, 1, 1)`,
		`INSERT INTO release_files (releases_id, name, size, crc32, created_at) VALUES
			(100, 'Predb.Match.2026.1080p.BluRay.x264-GRP.rar', 15000000, 'AABBCCDD', '2026-06-15 12:05:01'),
			(102, 'No.Match.2026.1080p.BluRay.x264-GRP.r00', 10000000, 'EEFF0011', '2026-06-15 12:03:00')`,
		`INSERT INTO par_hashes (releases_id, hash) VALUES
			(300, 'parhash-match'),
			(301, 'parhash-miss'),
			(400, 'parhash-match')`,
	}

	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			return fmt.Errorf("exec seed %q: %w", statement, err)
		}
	}

	return nil
}

func HashedFixTableFingerprint(ctx context.Context, db *sql.DB) (map[string]string, error) {
	tables := []tableFingerprintSpec{
		{
			Name: "releases",
			Columns: []string{
				"id",
				"name",
				"searchname",
				"groups_id",
				"size",
				"postdate",
				"adddate",
				"fromname",
				"categories_id",
				"videos_id",
				"tv_episodes_id",
				"imdbid",
				"musicinfo_id",
				"consoleinfo_id",
				"bookinfo_id",
				"predb_id",
				"anidbid",
				"isrenamed",
				"iscategorized",
				"proc_crc32",
				"proc_hash16k",
			},
			OrderBy: "`id`",
		},
		{
			Name:    "release_files",
			Columns: []string{"id", "releases_id", "name", "size", "crc32", "created_at"},
			OrderBy: "`id`",
		},
		{
			Name:    "predb",
			Columns: []string{"id", "title", "predate", "source"},
			OrderBy: "`id`",
		},
		{
			Name:    "predb_crcs",
			Columns: []string{"id", "predb_id", "crchash", "filesize"},
			OrderBy: "`id`",
		},
		{
			Name:    "par_hashes",
			Columns: []string{"releases_id", "hash"},
			OrderBy: "`releases_id`, `hash`",
		},
		{
			Name: "native_worker_side_effects",
			Columns: []string{
				"id",
				"operation_key",
				"job",
				"effect",
				"release_id",
				"status_column",
				"status_reason",
				"status_value",
				"status",
				"attempts",
				"available_at",
				"processed_at",
				"last_error_code",
			},
			OrderBy: "`id`",
		},
	}

	fingerprint := map[string]string{}
	for _, table := range tables {
		value, err := tableFingerprint(ctx, db, table)
		if err != nil {
			return nil, err
		}
		fingerprint[table.Name] = value
	}

	return fingerprint, nil
}

type tableFingerprintSpec struct {
	Name    string
	Columns []string
	OrderBy string
}

type tableFingerprintPayload struct {
	Columns []string `json:"columns"`
	Rows    [][]any  `json:"rows"`
}

func tableFingerprint(ctx context.Context, db *sql.DB, spec tableFingerprintSpec) (string, error) {
	query := fmt.Sprintf("SELECT %s FROM `%s` ORDER BY %s", quotedColumns(spec.Columns), spec.Name, spec.OrderBy)
	rows, err := db.QueryContext(ctx, query)
	if err != nil {
		return "", fmt.Errorf("fingerprint %s: %w", spec.Name, err)
	}
	defer rows.Close()

	payload := tableFingerprintPayload{
		Columns: spec.Columns,
		Rows:    [][]any{},
	}
	for rows.Next() {
		values := make([]sql.NullString, len(spec.Columns))
		destinations := make([]any, len(spec.Columns))
		for i := range values {
			destinations[i] = &values[i]
		}
		if err := rows.Scan(destinations...); err != nil {
			return "", fmt.Errorf("scan fingerprint %s: %w", spec.Name, err)
		}

		row := make([]any, len(values))
		for i, value := range values {
			if value.Valid {
				row[i] = value.String
			}
		}
		payload.Rows = append(payload.Rows, row)
	}
	if err := rows.Err(); err != nil {
		return "", fmt.Errorf("read fingerprint %s: %w", spec.Name, err)
	}

	encoded, err := json.Marshal(payload)
	if err != nil {
		return "", fmt.Errorf("encode fingerprint %s: %w", spec.Name, err)
	}

	return string(encoded), nil
}

func quotedColumns(columns []string) string {
	quoted := make([]string, 0, len(columns))
	for _, column := range columns {
		quoted = append(quoted, "`"+column+"`")
	}

	return strings.Join(quoted, ", ")
}
