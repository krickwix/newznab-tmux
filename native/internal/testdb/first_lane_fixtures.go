package testdb

import (
	"context"
	"database/sql"
	"fmt"
)

func ResetBinariesTables(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		"DROP TABLE IF EXISTS parts",
		"DROP TABLE IF EXISTS binaries",
		"DROP TABLE IF EXISTS collections",
		"DROP TABLE IF EXISTS short_groups",
		"DROP TABLE IF EXISTS usenet_groups",
		`CREATE TABLE usenet_groups (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			first_record BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_record BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_updated DATETIME NULL,
			active TINYINT NOT NULL DEFAULT 0,
			backfill TINYINT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE short_groups (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			first_record BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_record BIGINT UNSIGNED NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE collections (
			id INT AUTO_INCREMENT PRIMARY KEY,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			fromname VARCHAR(255) NOT NULL DEFAULT '',
			date DATETIME NULL,
			xref VARCHAR(2000) NOT NULL DEFAULT '',
			totalfiles INT UNSIGNED NOT NULL DEFAULT 0,
			groups_id INT UNSIGNED NOT NULL DEFAULT 0,
			collectionhash VARCHAR(255) NOT NULL DEFAULT '0',
			collection_regexes_id INT NOT NULL DEFAULT 0,
			dateadded DATETIME NULL,
			added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			filecheck TINYINT NOT NULL DEFAULT 0,
			filesize BIGINT UNSIGNED NOT NULL DEFAULT 0,
			releases_id INT NULL,
			noise CHAR(32) NOT NULL DEFAULT '',
			UNIQUE KEY ix_collection_collectionhash (collectionhash)
		)`,
		`CREATE TABLE binaries (
			id INT AUTO_INCREMENT PRIMARY KEY,
			binaryhash BLOB NOT NULL DEFAULT '0',
			name VARCHAR(1000) NOT NULL DEFAULT '',
			collections_id INT UNSIGNED NOT NULL DEFAULT 0,
			filenumber INT UNSIGNED NOT NULL DEFAULT 0,
			totalparts INT UNSIGNED NOT NULL DEFAULT 0,
			currentparts INT UNSIGNED NOT NULL DEFAULT 0,
			partcheck TINYINT NOT NULL DEFAULT 0,
			partsize BIGINT UNSIGNED NOT NULL DEFAULT 0,
			UNIQUE KEY ux_collection_id_filenumber (collections_id, filenumber)
		)`,
		`CREATE TABLE parts (
			binaries_id INT UNSIGNED NOT NULL DEFAULT 0,
			messageid VARCHAR(255) NOT NULL DEFAULT '',
			number BIGINT UNSIGNED NOT NULL DEFAULT 0,
			partnumber INT UNSIGNED NOT NULL DEFAULT 0,
			size INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (binaries_id, number)
		)`,
	})
}

func SeedBinariesRows(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		`INSERT INTO usenet_groups (id, name, first_record, last_record, last_updated, active, backfill) VALUES
			(1, 'alt.binaries.movies', 0, 1000, '2026-06-15 10:00:00', 1, 1),
			(2, 'alt.binaries.small', 10, 10000, '2026-06-15 10:00:00', 1, 1),
			(3, 'alt.binaries.new', 0, 0, NULL, 1, 0),
			(4, 'alt.binaries.inactive', 0, 0, NULL, 0, 0),
			(5, 'alt.binaries.no-short-row', 0, 0, NULL, 1, 0)`,
		`INSERT INTO short_groups (name, first_record, last_record) VALUES
			('alt.binaries.movies', 1, 100000),
			('alt.binaries.small', 1, 50000),
			('alt.binaries.new', 1, 1000),
			('alt.binaries.inactive', 1, 999999)`,
	})
}

func ResetBackfillTables(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		"DROP TABLE IF EXISTS parts",
		"DROP TABLE IF EXISTS binaries",
		"DROP TABLE IF EXISTS collections",
		"DROP TABLE IF EXISTS short_groups",
		"DROP TABLE IF EXISTS usenet_groups",
		`CREATE TABLE usenet_groups (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			first_record BIGINT UNSIGNED NOT NULL DEFAULT 0,
			first_record_postdate DATETIME NULL,
			backfill TINYINT NOT NULL DEFAULT 0,
			backfill_target INT NOT NULL DEFAULT 1
		)`,
		`CREATE TABLE short_groups (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			first_record BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_record BIGINT UNSIGNED NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE collections (
			id INT AUTO_INCREMENT PRIMARY KEY,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			fromname VARCHAR(255) NOT NULL DEFAULT '',
			date DATETIME NULL,
			xref VARCHAR(2000) NOT NULL DEFAULT '',
			totalfiles INT UNSIGNED NOT NULL DEFAULT 0,
			groups_id INT UNSIGNED NOT NULL DEFAULT 0,
			collectionhash VARCHAR(255) NOT NULL DEFAULT '0',
			collection_regexes_id INT NOT NULL DEFAULT 0,
			dateadded DATETIME NULL,
			added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			filecheck TINYINT NOT NULL DEFAULT 0,
			filesize BIGINT UNSIGNED NOT NULL DEFAULT 0,
			releases_id INT NULL,
			noise CHAR(32) NOT NULL DEFAULT '',
			UNIQUE KEY ix_collection_collectionhash (collectionhash)
		)`,
		`CREATE TABLE binaries (
			id INT AUTO_INCREMENT PRIMARY KEY,
			binaryhash BLOB NOT NULL DEFAULT '0',
			name VARCHAR(1000) NOT NULL DEFAULT '',
			collections_id INT UNSIGNED NOT NULL DEFAULT 0,
			filenumber INT UNSIGNED NOT NULL DEFAULT 0,
			totalparts INT UNSIGNED NOT NULL DEFAULT 0,
			currentparts INT UNSIGNED NOT NULL DEFAULT 0,
			partcheck TINYINT NOT NULL DEFAULT 0,
			partsize BIGINT UNSIGNED NOT NULL DEFAULT 0,
			UNIQUE KEY ux_collection_id_filenumber (collections_id, filenumber)
		)`,
		`CREATE TABLE parts (
			binaries_id INT UNSIGNED NOT NULL DEFAULT 0,
			messageid VARCHAR(255) NOT NULL DEFAULT '',
			number BIGINT UNSIGNED NOT NULL DEFAULT 0,
			partnumber INT UNSIGNED NOT NULL DEFAULT 0,
			size INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (binaries_id, number)
		)`,
	})
}

func SeedBackfillRows(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		`INSERT INTO usenet_groups (id, name, first_record, first_record_postdate, backfill, backfill_target) VALUES
			(1, 'a.b.multimedia.movies', 50000, '2099-06-15 11:00:00', 1, 10),
			(2, 'a.b.multimedia.vintage-film', 105, '2099-06-15 10:00:00', 1, 10),
			(3, 'a.b.near-provider-floor', 50, '2099-06-15 09:00:00', 1, 10),
			(4, 'a.b.at-provider-floor', 1, '2099-06-15 08:00:00', 1, 10),
			(5, 'a.b.bad-provider-row', 1000, '2099-06-15 07:00:00', 1, 10),
			(6, 'a.b.target-reached', 1000, '2000-01-01 00:00:00', 1, 10),
			(7, 'a.b.no-short-row', 1000, '2099-06-15 06:00:00', 1, 10),
			(8, 'a.b.backfill-disabled', 1000, '2099-06-15 05:00:00', 0, 10)`,
		`INSERT INTO short_groups (name, first_record, last_record) VALUES
			('a.b.multimedia.movies', 1, 200000),
			('a.b.multimedia.vintage-film', 2, 200000),
			('a.b.near-provider-floor', 2, 200000),
			('a.b.at-provider-floor', 1, 200000),
			('a.b.bad-provider-row', 2000, 1000),
			('a.b.target-reached', 1, 200000),
			('a.b.backfill-disabled', 1, 200000)`,
	})
}

func ResetReleaseQueueTables(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		"DROP TABLE IF EXISTS parts",
		"DROP TABLE IF EXISTS binaries",
		"DROP TABLE IF EXISTS releases",
		"DROP TABLE IF EXISTS collections",
		"DROP TABLE IF EXISTS usenet_groups",
		"DROP TABLE IF EXISTS settings",
		`CREATE TABLE settings (
			name VARCHAR(255) PRIMARY KEY,
			value TEXT NULL
		)`,
		`CREATE TABLE usenet_groups (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			active TINYINT NOT NULL DEFAULT 0,
			backfill TINYINT NOT NULL DEFAULT 0,
			last_updated DATETIME(6) NULL
		)`,
		`CREATE TABLE collections (
			id INT AUTO_INCREMENT PRIMARY KEY,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			fromname VARCHAR(255) NOT NULL DEFAULT '',
			date DATETIME NULL,
			xref VARCHAR(2000) NOT NULL DEFAULT '',
			totalfiles INT UNSIGNED NOT NULL DEFAULT 0,
			groups_id INT UNSIGNED NOT NULL DEFAULT 0,
			collectionhash VARCHAR(255) NOT NULL DEFAULT '0',
			collection_regexes_id INT NOT NULL DEFAULT 0,
			dateadded DATETIME NULL,
			added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			filecheck TINYINT NOT NULL DEFAULT 0,
			filesize BIGINT UNSIGNED NOT NULL DEFAULT 0,
			releases_id INT NULL,
			noise CHAR(32) NOT NULL DEFAULT '',
			UNIQUE KEY ix_collection_collectionhash (collectionhash)
		)`,
		`CREATE TABLE binaries (
			id INT AUTO_INCREMENT PRIMARY KEY,
			binaryhash BLOB NOT NULL DEFAULT '0',
			name VARCHAR(1000) NOT NULL DEFAULT '',
			collections_id INT UNSIGNED NOT NULL DEFAULT 0,
			filenumber INT UNSIGNED NOT NULL DEFAULT 0,
			totalparts INT UNSIGNED NOT NULL DEFAULT 0,
			currentparts INT UNSIGNED NOT NULL DEFAULT 0,
			partcheck TINYINT NOT NULL DEFAULT 0,
			partsize BIGINT UNSIGNED NOT NULL DEFAULT 0,
			UNIQUE KEY ux_collection_id_filenumber (collections_id, filenumber)
		)`,
		`CREATE TABLE parts (
			binaries_id INT UNSIGNED NOT NULL DEFAULT 0,
			messageid VARCHAR(255) NOT NULL DEFAULT '',
			number BIGINT UNSIGNED NOT NULL DEFAULT 0,
			partnumber INT UNSIGNED NOT NULL DEFAULT 0,
			size INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (binaries_id, number)
		)`,
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			totalpart INT DEFAULT 0,
			groups_id INT NOT NULL DEFAULT 0,
			size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			postdate DATETIME NULL,
			categories_id INT NOT NULL DEFAULT 0,
			adddate DATETIME NULL,
			updatetime TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
			gid VARCHAR(32) NULL,
			guid VARCHAR(40) NOT NULL,
			leftguid CHAR(1) NOT NULL DEFAULT '',
			fromname VARCHAR(255) NULL,
			completion DOUBLE NOT NULL DEFAULT 0,
			nzbstatus TINYINT NOT NULL DEFAULT 0,
			nzb_guid BLOB NOT NULL
		)`,
	})
}

func SeedReleaseQueueRows(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		`INSERT INTO settings (name, value) VALUES ('releasethreads', '3')`,
		`INSERT INTO usenet_groups (id, name, active, backfill) VALUES
			(1, 'alt.binaries.movies', 1, 0),
			(2, 'alt.binaries.backfill', 0, 1),
			(3, 'alt.binaries.no-collections', 1, 0),
			(4, 'alt.binaries.inactive', 0, 0)`,
		`INSERT INTO collections (id, subject, fromname, date, totalfiles, groups_id, collectionhash, dateadded, filesize) VALUES
			(100, 'Movie.Release.Native.2026', 'poster@example.invalid', '2026-06-15 10:00:00', 2, 1, 'release-collection-100', '2026-06-15 10:05:00', 3000),
			(101, 'Movie.Release.Native.Extra.2026', 'poster@example.invalid', '2026-06-15 10:10:00', 1, 1, 'release-collection-101', '2026-06-15 10:15:00', 1000),
			(200, 'Backfill.Release.Native.2026', 'backfill@example.invalid', '2026-06-14 09:00:00', 1, 2, 'release-collection-200', '2026-06-14 09:05:00', 1500),
			(400, 'Inactive.Release.Native.2026', 'inactive@example.invalid', '2026-06-13 08:00:00', 1, 4, 'release-collection-400', '2026-06-13 08:05:00', 900)`,
		`INSERT INTO binaries (id, binaryhash, name, collections_id, filenumber, totalparts, currentparts, partsize) VALUES
			(1000, UNHEX(MD5('Movie.Release.Native.2026.part1')), 'Movie.Release.Native.2026.part1', 100, 1, 2, 2, 3000),
			(2000, UNHEX(MD5('Backfill.Release.Native.2026.part1')), 'Backfill.Release.Native.2026.part1', 200, 1, 1, 1, 1500)`,
	})
}

func ResetPerGroupQueueTables(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		"DROP TABLE IF EXISTS collections",
		"DROP TABLE IF EXISTS usenet_groups",
		"DROP TABLE IF EXISTS settings",
		`CREATE TABLE settings (
			name VARCHAR(255) PRIMARY KEY,
			value TEXT NULL
		)`,
		`CREATE TABLE usenet_groups (
			id INT AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT '',
			active TINYINT NOT NULL DEFAULT 0,
			backfill TINYINT NOT NULL DEFAULT 0,
			last_updated DATETIME(6) NULL
		)`,
		`CREATE TABLE collections (
			id INT AUTO_INCREMENT PRIMARY KEY,
			groups_id INT NOT NULL
		)`,
	})
}

func SeedPerGroupQueueRows(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		`INSERT INTO settings (name, value) VALUES ('releasethreads', '2')`,
		`INSERT INTO usenet_groups (id, name, active, backfill) VALUES
			(1, 'alt.binaries.active', 1, 0),
			(2, 'alt.binaries.backfill', 0, 1),
			(3, 'alt.binaries.both', 1, 1),
			(4, 'alt.binaries.no-collections', 1, 0),
			(5, 'alt.binaries.backfill-empty', 0, 1),
			(6, 'alt.binaries.inactive-with-collection', 0, 0)`,
		`INSERT INTO collections (id, groups_id) VALUES
			(100, 1),
			(600, 6)`,
	})
}

func execStatements(ctx context.Context, db *sql.DB, statements []string) error {
	for _, statement := range statements {
		if _, err := db.ExecContext(ctx, statement); err != nil {
			return fmt.Errorf("exec %q: %w", statement, err)
		}
	}

	return nil
}
