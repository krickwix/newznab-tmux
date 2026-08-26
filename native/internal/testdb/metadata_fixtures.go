package testdb

import (
	"context"
	"database/sql"
)

func ResetMetadataRefreshTables(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		"DROP TABLE IF EXISTS native_worker_side_effects",
		"DROP TABLE IF EXISTS predb_crcs",
		"DROP TABLE IF EXISTS predb",
		"DROP TABLE IF EXISTS release_files",
		"DROP TABLE IF EXISTS par_hashes",
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
		`CREATE TABLE par_hashes (
			releases_id INT NOT NULL,
			hash VARCHAR(32) NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE predb (
			id INT AUTO_INCREMENT PRIMARY KEY,
			title VARCHAR(255) NOT NULL UNIQUE,
			source VARCHAR(64) NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE predb_crcs (
			id INT AUTO_INCREMENT PRIMARY KEY,
			predb_id INT NOT NULL,
			crchash VARCHAR(8) NOT NULL,
			filesize BIGINT NOT NULL DEFAULT 0,
			UNIQUE KEY predb_crc_unique (predb_id, crchash, filesize)
		)`,
		`CREATE TABLE release_files (
			id INT AUTO_INCREMENT PRIMARY KEY,
			releases_id INT NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			size BIGINT NOT NULL DEFAULT 0,
			crc32 VARCHAR(8) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL
		)`,
		`CREATE TABLE native_worker_side_effects (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			operation_key VARCHAR(191) NOT NULL UNIQUE,
			job VARCHAR(64) NOT NULL,
			effect VARCHAR(64) NOT NULL,
			release_id BIGINT UNSIGNED NOT NULL,
			status_column VARCHAR(32) NOT NULL,
			status_reason VARCHAR(64) NOT NULL,
			status_value TINYINT UNSIGNED NOT NULL,
			payload_text VARCHAR(255) NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'pending',
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			available_at TIMESTAMP NULL,
			processed_at TIMESTAMP NULL,
			last_error_code VARCHAR(64) NULL,
			created_at TIMESTAMP NULL,
			updated_at TIMESTAMP NULL,
			INDEX ix_native_worker_side_effects_status_available (status, available_at, id),
			INDEX ix_native_worker_side_effects_release_status (release_id, status),
			INDEX ix_native_worker_side_effects_job_effect_status (job, effect, status)
		)`,
	})
}

func SeedMetadataRefreshRows(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		`INSERT INTO predb (id, title, source) VALUES
			(1, 'Movie.Name.2026.1080p.BluRay.x264-GRP', 'srrdb'),
			(2, 'Other.Source.2026.1080p.WEB.x264-GRP', 'predb-net')`,
		`INSERT INTO predb_crcs (predb_id, crchash, filesize) VALUES
			(2, 'DDCCBBAA', 15000000)`,
		`INSERT INTO release_files (releases_id, name, size, crc32, created_at) VALUES
			(10, 'Movie.Name.2026.1080p.BluRay.x264-GRP.r00', 15000000, 'aabbccdd', '2026-06-15 12:00:00'),
			(11, 'Existing.CRC.No.Signal-GRP.r00', 15000000, 'DDCCBBAA', '2026-06-15 12:00:01')`,
	})
}
