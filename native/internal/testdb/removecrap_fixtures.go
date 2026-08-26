package testdb

import (
	"context"
	"database/sql"
)

func ResetRemoveCrapTables(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		"DROP TABLE IF EXISTS binaryblacklist",
		"DROP TABLE IF EXISTS native_worker_side_effects",
		"DROP TABLE IF EXISTS collections",
		"DROP TABLE IF EXISTS release_files",
		"DROP TABLE IF EXISTS releases",
		"DROP TABLE IF EXISTS settings",
		"DROP TABLE IF EXISTS usenet_groups",
		`CREATE TABLE settings (
			name VARCHAR(255) PRIMARY KEY,
			value TEXT NULL
		)`,
		`CREATE TABLE usenet_groups (
			id INT PRIMARY KEY,
			name VARCHAR(255) NOT NULL DEFAULT ''
		)`,
		`CREATE TABLE binaryblacklist (
			id INT PRIMARY KEY,
			groupname VARCHAR(255) NOT NULL DEFAULT '',
			regex VARCHAR(255) NOT NULL DEFAULT '',
			msgcol INT NOT NULL DEFAULT 1,
			optype INT NOT NULL DEFAULT 1,
			status INT NOT NULL DEFAULT 1
		)`,
		`CREATE TABLE native_worker_side_effects (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			guid VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			fromname VARCHAR(255) NOT NULL DEFAULT '',
			groups_id INT NOT NULL DEFAULT 0,
			categories_id INT NOT NULL DEFAULT 0,
			passwordstatus INT NOT NULL DEFAULT 0,
			nfostatus TINYINT NOT NULL DEFAULT 0,
			haspreview INT NOT NULL DEFAULT 0,
			jpgstatus INT NOT NULL DEFAULT 0,
			predb_id INT NOT NULL DEFAULT 0,
			videostatus INT NOT NULL DEFAULT 0,
			imdbid VARCHAR(32) NULL,
			isrenamed TINYINT NOT NULL DEFAULT 0,
			iscategorized TINYINT NOT NULL DEFAULT 0,
			rarinnerfilecount INT NOT NULL DEFAULT 0,
			totalpart INT NOT NULL DEFAULT 0,
			size BIGINT NOT NULL DEFAULT 0,
			adddate DATETIME NOT NULL
		)`,
		`CREATE TABLE release_files (
			id INT AUTO_INCREMENT PRIMARY KEY,
			releases_id INT NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			passworded INT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE collections (
			id INT AUTO_INCREMENT PRIMARY KEY,
			groups_id INT NOT NULL DEFAULT 0,
			releases_id INT NULL
		)`,
	})
}

func SeedRemoveCrapRows(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		`INSERT INTO settings (name, value) VALUES
			('minsizetoformrelease', '2097152')`,
		`INSERT INTO usenet_groups (id, name) VALUES
			(88, 'alt.binaries.movies'),
			(89, 'alt.binaries.tv')`,
		`INSERT INTO binaryblacklist (id, groupname, regex, msgcol, optype, status) VALUES
			(1, 'alt[.]binaries[.]movies', 'badcodec[.]dat', 1, 1, 1),
			(2, 'alt.binaries.*', 'disabledbad[.]dat', 1, 1, 0),
			(3, 'alt.binaries.*', 'whitelistbad[.]dat', 1, 2, 1),
			(4, 'alt.binaries.*', 'frombad[.]dat', 2, 1, 1),
			(5, 'alt[.]binaries[.]movies', 'Bad[.]Subject', 1, 1, 1),
			(6, 'alt.binaries.*', 'BadPoster', 2, 1, 1),
			(7, 'alt.binaries.*', 'Disabled[.]Subject', 1, 1, 0),
			(8, 'alt.binaries.*', 'Whitelist[.]Subject', 1, 2, 1)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, iscategorized, rarinnerfilecount, adddate) VALUES
			(100, 'guid-gibberish', 'ABCDEFGHIJKLMNOP', 'ABCDEFGHIJKLMNOP', 2000, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(101, 'guid-old-gibberish', 'QRSTUVWXYZABCDE', 'QRSTUVWXYZABCDE', 2000, 0, 0, 1, 0, NOW() - INTERVAL 12 HOUR),
			(102, 'guid-hashed-category', 'ABCDEFGHIJKLMNOPQ', 'ABCDEFGHIJKLMNOPQ', 20, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(103, 'guid-not-categorized', 'ABCDEFGHIJKLMNOPR', 'ABCDEFGHIJKLMNOPR', 2000, 0, 0, 0, 0, NOW() - INTERVAL 1 HOUR),
			(200, 'guid-executable', 'Movie.Release.2026', 'Movie.Release.2026', 2040, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(201, 'guid-pc-game-exe', 'Game.Release.2026', 'Game.Release.2026', 4050, 0, 0, 1, 0, NOW() - INTERVAL 1 HOUR),
			(202, 'guid-old-exe', 'Old.Release.2026', 'Old.Release.2026', 2040, 0, 0, 1, 0, NOW() - INTERVAL 12 HOUR)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, iscategorized, rarinnerfilecount, totalpart, size, adddate) VALUES
			(300, 'guid-hashed', 'ABCDEFGHIJKLMNOPQRSTUVWXY', 'ABCDEFGHIJKLMNOPQRSTUVWXY', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(301, 'guid-hashed-misc', 'ABCDEFGHIJKLMNOPQRSTUVWXY1', 'ABCDEFGHIJKLMNOPQRSTUVWXY1', 10, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(310, 'guid-short', 'AB12', 'AB12', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(311, 'guid-short-misc', 'AB13', 'AB13', 10, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(320, 'guid-installbin', 'Install.Bin.Release', 'Install.Bin.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(321, 'guid-passwordurl', 'URL.File.Release', 'URL.File.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(322, 'guid-nzb', 'Single.Nzb.Release', 'Single.Nzb.Release', 2000, 0, 0, 1, 0, 1, 3000000, NOW() - INTERVAL 1 HOUR),
			(323, 'guid-scr', 'Screen.Saver.Release', 'Screen.Saver.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(330, 'guid-passwordstatus', 'Password.Status.Release', 'Password.Status.Release', 2000, 1, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(331, 'guid-password-file', 'Password.File.Release', 'Password.File.Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(332, 'guid-password-false-positive', 'No password Release', 'No password Release', 2000, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(340, 'guid-sample', 'Movie.sample.avi', 'Movie.Sample.Release', 2040, 0, 0, 1, 0, 2, 20000000, NOW() - INTERVAL 1 HOUR),
			(341, 'guid-sample-large', 'Movie.sample.large.avi', 'Movie.Sample.Large.Release', 2040, 0, 0, 1, 0, 2, 50000000, NOW() - INTERVAL 1 HOUR),
			(350, 'guid-size', 'Tiny.Release', 'Tiny.Release', 2000, 0, 0, 1, 0, 1, 1000000, NOW() - INTERVAL 1 HOUR),
			(351, 'guid-size-music', 'Tiny.Music.Release', 'Tiny.Music.Release', 3010, 0, 0, 1, 0, 1, 1000000, NOW() - INTERVAL 1 HOUR)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, haspreview, jpgstatus, predb_id, videostatus, imdbid, iscategorized, rarinnerfilecount, totalpart, size, adddate) VALUES
			(360, 'guid-codec', 'Movie.Codec.Release', 'Movie.Codec.Release', 2040, 0, 1, 0, 0, 0, 0, 'tt1234567', 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(361, 'guid-codec-no-imdb', 'Movie.Codec.No.Imdb', 'Movie.Codec.No.Imdb', 2040, 0, 1, 0, 0, 0, 0, '', 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(362, 'guid-codec-preview', 'Movie.Codec.Preview', 'Movie.Codec.Preview', 2040, 0, 1, 1, 0, 0, 0, 'tt7654321', 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR)`,
		`INSERT INTO releases (id, guid, name, searchname, categories_id, passwordstatus, nfostatus, iscategorized, rarinnerfilecount, totalpart, size, adddate) VALUES
			(380, 'guid-blfiles', 'Blacklist.Files.Release', 'Blacklist.Files.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(381, 'guid-blfiles-wrong-group', 'Blacklist.Files.Wrong.Group', 'Blacklist.Files.Wrong.Group', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(382, 'guid-blfiles-disabled', 'Blacklist.Files.Disabled', 'Blacklist.Files.Disabled', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(383, 'guid-blfiles-whitelist', 'Blacklist.Files.Whitelist', 'Blacklist.Files.Whitelist', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(384, 'guid-blfiles-from', 'Blacklist.Files.From', 'Blacklist.Files.From', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(385, 'guid-blfiles-old', 'Blacklist.Files.Old', 'Blacklist.Files.Old', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(390, 'guid-blacklist-subject', 'Bad.Subject.Release', 'Bad.Subject.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(391, 'guid-blacklist-wrong-group', 'Bad.Subject.Wrong.Group', 'Bad.Subject.Wrong.Group', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(392, 'guid-blacklist-poster', 'Poster.Blacklist.Release', 'Poster.Blacklist.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(393, 'guid-blacklist-disabled', 'Disabled.Subject.Release', 'Disabled.Subject.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(394, 'guid-blacklist-whitelist', 'Whitelist.Subject.Release', 'Whitelist.Subject.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(395, 'guid-blacklist-old', 'Bad.Subject.Old', 'Bad.Subject.Old', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(400, 'guid-par2-searchname', 'Only.Par2.par2_', 'Only.Par2.par2_', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(401, 'guid-par2-searchname-mixed', 'Mixed.Par2.par2_', 'Mixed.Par2.par2_', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(402, 'guid-par2-files-only', 'All.Files.Are.Repair', 'All.Files.Are.Repair', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(403, 'guid-par2-files-mixed', 'Mixed.Files.Release', 'Mixed.Files.Release', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(404, 'guid-par2-old', 'Old.Par2.par2_', 'Old.Par2.par2_', 2040, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 12 HOUR),
			(405, 'guid-par2-hashed-nzb', 'Hashed.Par2.NZB', 'Hashed.Par2.NZB', 20, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR),
			(406, 'guid-par2-hashed-content', 'Hashed.Mixed.NZB', 'Hashed.Mixed.NZB', 20, 0, 0, 1, 0, 2, 100000000, NOW() - INTERVAL 1 HOUR)`,
		`UPDATE releases SET groups_id = 88 WHERE id IN (380, 382, 383, 384, 385, 390, 392, 393, 394, 395)`,
		`UPDATE releases SET groups_id = 89 WHERE id IN (381, 391)`,
		`UPDATE releases SET fromname = 'BadPoster' WHERE id = 392`,
		`INSERT INTO release_files (releases_id, name, passworded) VALUES
			(200, 'setup.exe', 0),
			(200, 'bonus.exe', 0),
			(201, 'game.exe', 0),
			(202, 'old.exe', 0),
			(320, 'install.bin', 0),
			(321, 'password.url', 0),
			(322, 'release.nzb', 0),
			(323, 'danger.scr ', 0),
			(331, 'archive.rar', 1),
			(360, 'XviD-abc.avi', 0),
			(361, 'XviD-def.avi', 0),
			(362, 'XviD-ghi.avi', 0),
			(380, 'badcodec.dat', 0),
			(381, 'badcodec.dat', 0),
			(382, 'disabledbad.dat', 0),
			(383, 'whitelistbad.dat', 0),
			(384, 'frombad.dat', 0),
			(385, 'badcodec.dat', 0),
			(400, 'volume.par2', 0),
			(400, 'volume.vol000+001.par2', 0),
			(401, 'volume.par2', 0),
			(401, 'movie.mkv', 0),
			(402, 'repair.par2', 0),
			(402, 'repair.vol000+001.par2', 0),
			(403, 'repair.par2', 0),
			(403, 'archive.rar', 0),
			(404, 'old.par2', 0)`,
		`INSERT INTO collections (groups_id, releases_id) VALUES
			(88, 100),
			(88, 200),
			(88, 300),
			(88, 320),
			(88, 400)`,
	})
}
