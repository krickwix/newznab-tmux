package testdb

import (
	"context"
	"database/sql"
)

func ResetPostprocessTables(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		"DROP TABLE IF EXISTS categories",
		"DROP TABLE IF EXISTS releases",
		"DROP TABLE IF EXISTS settings",
		`CREATE TABLE settings (
			name VARCHAR(255) PRIMARY KEY,
			value TEXT NULL
		)`,
		`CREATE TABLE categories (
			id INT PRIMARY KEY,
			disablepreview TINYINT NOT NULL DEFAULT 0
		)`,
		`CREATE TABLE releases (
			id INT AUTO_INCREMENT PRIMARY KEY,
			guid VARCHAR(64) NOT NULL DEFAULT '',
			leftguid VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			searchname VARCHAR(255) NOT NULL DEFAULT '',
			groups_id INT NOT NULL DEFAULT 0,
			categories_id INT NOT NULL DEFAULT 0,
			passwordstatus INT NOT NULL DEFAULT 0,
			haspreview INT NOT NULL DEFAULT 0,
			nzbstatus INT NOT NULL DEFAULT 1,
			nfostatus INT NOT NULL DEFAULT 0,
			videos_id INT NOT NULL DEFAULT 0,
			tv_episodes_id INT NOT NULL DEFAULT 0,
			size BIGINT NOT NULL DEFAULT 0,
			isrenamed TINYINT NOT NULL DEFAULT 0,
			anidbid INT NULL,
			imdbid VARCHAR(16) NULL,
			movieinfo_id INT NULL,
			bookinfo_id INT NULL,
			musicinfo_id INT NULL,
			consoleinfo_id INT NULL,
			gamesinfo_id INT NULL DEFAULT 0
		)`,
	})
}

func SeedPostTVRows(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		`INSERT INTO settings (name, value) VALUES
			('lookuptv', '1'),
			('lookupanidb', '1'),
			('postthreadsnon', '3')`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, categories_id, videos_id, tv_episodes_id, size, isrenamed, anidbid) VALUES
			(100, 'guid-tv-a', 'A-tv-eligible-1', 'TV.Release.A', 'TV.Release.A', 5000, 0, -1, 2097152, 0, NULL),
			(101, 'guid-tv-b', 'b-tv-eligible-2', 'TV.Release.B', 'TV.Release.B', 5020, 0, 0, 3145728, 1, NULL),
			(102, 'guid-tv-a-duplicate', 'A-tv-eligible-duplicate', 'TV.Release.A2', 'TV.Release.A2', 5030, 0, -2, 4194304, 0, NULL),
			(103, 'guid-tv-anime-category', 'x-tv-anime-category', 'TV.Anime.Category', 'TV.Anime.Category', 5070, 0, -1, 2097152, 1, 99999),
			(104, 'guid-tv-has-video', 'y-tv-has-video', 'TV.Has.Video', 'TV.Has.Video', 5000, 7, -1, 2097152, 1, NULL),
			(105, 'guid-tv-too-small', 'z-tv-too-small', 'TV.Too.Small', 'TV.Too.Small', 5000, 0, -1, 1048576, 1, NULL),
			(106, 'guid-tv-episode-linked', 'w-tv-episode-linked', 'TV.Episode.Linked', 'TV.Episode.Linked', 5000, 0, 1, 2097152, 1, NULL),
			(107, 'guid-tv-wrong-category', 'v-tv-wrong-category', 'TV.Wrong.Category', 'TV.Wrong.Category', 6000, 0, -1, 2097152, 1, NULL),
			(200, 'guid-anime-c', 'c-anime-eligible', 'Anime.Release.C', 'Anime.Release.C', 5070, 0, 0, 2097152, 0, NULL),
			(201, 'guid-anime-known', 'd-anime-known', 'Anime.Release.D', 'Anime.Release.D', 5070, 0, 0, 2097152, 0, 12345)`,
	})
}

func SeedPostMovieRows(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		`INSERT INTO settings (name, value) VALUES
			('lookupimdb', '1'),
			('postthreadsnon', '3')`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, categories_id, imdbid, movieinfo_id, isrenamed) VALUES
			(300, 'guid-movie-m', 'm-movie-pending', 'Movie.Release.M', 'Movie.Release.M', 2040, NULL, NULL, 0),
			(301, 'guid-movie-n', 'n-movie-repair', 'Movie.Release.N', 'Movie.Release.N', 2080, '1234567', 0, 1),
			(302, 'guid-movie-duplicate', 'm-movie-duplicate', 'Movie.Release.M2', 'Movie.Release.M2', 2010, '00000000', NULL, 0),
			(303, 'guid-movie-empty-imdb', 'x-movie-empty-imdb', 'Movie.Release.Empty', 'Movie.Release.Empty', 2040, '', NULL, 1),
			(304, 'guid-movie-linked', 'y-movie-linked', 'Movie.Release.Linked', 'Movie.Release.Linked', 2040, '7654321', 55, 1),
			(305, 'guid-movie-wrong-category', 'z-movie-wrong-category', 'Movie.Release.Wrong', 'Movie.Release.Wrong', 3000, NULL, NULL, 1)`,
	})
}

func SeedPostAmazonRows(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		`INSERT INTO settings (name, value) VALUES
			('lookupbooks', '1'),
			('lookupmusic', '1'),
			('lookupgames', '1'),
			('postthreadsamazon', '4')`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, categories_id, bookinfo_id, musicinfo_id, consoleinfo_id, gamesinfo_id, isrenamed) VALUES
			(400, 'guid-book-b', 'B-book-eligible', 'Book.Release.B', 'Book.Release.B', 7010, NULL, NULL, NULL, 0, 0),
			(401, 'guid-book-q', 'q-book-nzb', 'N:/NZB/book-q', 'N:/NZB/book-q', 3030, 77, NULL, NULL, 0, 0),
			(402, 'guid-book-linked', 'r-book-linked', 'Book.Release.Linked', 'Book.Release.Linked', 7020, 77, NULL, NULL, 0, 0),
			(410, 'guid-music-m', 'M-music-eligible', 'Music.Release.M', 'Music.Release.M', 3010, NULL, NULL, NULL, 0, 0),
			(411, 'guid-music-n', 'N-music-eligible', 'Music.Release.N', 'Music.Release.N', 3040, NULL, NULL, NULL, 0, 0),
			(412, 'guid-music-video', 'o-music-video', 'Music.Release.Video', 'Music.Release.Video', 3020, NULL, NULL, NULL, 0, 0),
			(413, 'guid-music-linked', 'p-music-linked', 'Music.Release.Linked', 'Music.Release.Linked', 3010, NULL, 9001, NULL, 0, 0),
			(420, 'guid-console-c', 'C-console-eligible', 'Console.Release.C', 'Console.Release.C', 1010, NULL, NULL, NULL, 0, 0),
			(421, 'guid-console-d', 'D-console-renamed', 'Console.Release.D', 'Console.Release.D', 1180, NULL, NULL, NULL, 0, 1),
			(422, 'guid-console-linked', 'e-console-linked', 'Console.Release.Linked', 'Console.Release.Linked', 1080, NULL, NULL, 9101, 0, 1),
			(430, 'guid-game-g', 'G-game-eligible', 'Game.Release.G', 'Game.Release.G', 4050, NULL, NULL, NULL, 0, 0),
			(431, 'guid-game-h', 'H-game-renamed', 'Game.Release.H', 'Game.Release.H', 4050, NULL, NULL, NULL, 0, 1),
			(432, 'guid-game-linked', 'i-game-linked', 'Game.Release.Linked', 'Game.Release.Linked', 4050, NULL, NULL, NULL, 44, 1),
			(433, 'guid-game-null-info', 'j-game-null-info', 'Game.Release.NullInfo', 'Game.Release.NullInfo', 4050, NULL, NULL, NULL, NULL, 1)`,
	})
}

func SeedPostAdditionalRows(ctx context.Context, db *sql.DB) error {
	return execStatements(ctx, db, []string{
		`INSERT INTO settings (name, value) VALUES
			('postthreads', '5'),
			('nfothreads', '2'),
			('lookupnfo', '1'),
			('minsizetopostprocess', '1'),
			('maxsizetopostprocess', '100'),
			('minsizetoprocessnfo', '1'),
			('maxsizetoprocessnfo', '2'),
			('maxnforetries', '7')`,
		`INSERT INTO categories (id, disablepreview) VALUES
			(2000, 0),
			(3000, 1)`,
		`INSERT INTO releases (id, guid, leftguid, name, searchname, groups_id, categories_id, passwordstatus, haspreview, nzbstatus, nfostatus, size) VALUES
			(600, 'guid-add-a', 'a-add-eligible', 'Additional.Release.A', 'Additional.Release.A', 1, 2000, -1, -1, 1, 0, 2097152),
			(601, 'guid-add-b', 'B-add-eligible-large', 'Additional.Release.B', 'Additional.Release.B', 1, 2000, -1, -1, 1, 0, 31457280),
			(602, 'guid-add-a-duplicate', 'a-add-duplicate', 'Additional.Release.A2', 'Additional.Release.A2', 1, 2000, -1, -1, 1, 0, 3145728),
			(607, 'guid-add-blank-leftguid', '', 'Additional.Release.Blank', 'Additional.Release.Blank', 1, 2000, -1, -1, 1, 0, 4194304),
			(603, 'guid-add-too-small', 'c-add-too-small', 'Additional.Release.Small', 'Additional.Release.Small', 1, 2000, -1, -1, 1, 0, 1048576),
			(604, 'guid-add-preview-disabled', 'd-add-preview-disabled', 'Additional.Release.Disabled', 'Additional.Release.Disabled', 1, 3000, -1, -1, 1, 0, 4194304),
			(605, 'guid-add-already-previewed', 'e-add-previewed', 'Additional.Release.Previewed', 'Additional.Release.Previewed', 1, 2000, -1, 0, 1, 0, 4194304),
			(606, 'guid-add-missing-nzb', 'f-add-missing-nzb', 'Additional.Release.NoNZB', 'Additional.Release.NoNZB', 1, 2000, -1, -1, 0, 0, 4194304),
			(700, 'guid-nfo-n', 'N-nfo-eligible', 'NFO.Release.N', 'NFO.Release.N', 1, 2000, 0, 0, 1, -1, 2097152),
			(701, 'guid-nfo-o', 'o-nfo-retry', 'NFO.Release.O', 'NFO.Release.O', 1, 2000, 0, 0, 1, -8, 3145728),
			(702, 'guid-nfo-n-duplicate', 'N-nfo-duplicate', 'NFO.Release.N2', 'NFO.Release.N2', 1, 2000, 0, 0, 1, -2, 4194304),
			(703, 'guid-nfo-exhausted', 'p-nfo-exhausted', 'NFO.Release.Exhausted', 'NFO.Release.Exhausted', 1, 2000, 0, 0, 1, -9, 4194304),
			(704, 'guid-nfo-too-small', 'q-nfo-too-small', 'NFO.Release.Small', 'NFO.Release.Small', 1, 2000, 0, 0, 1, -1, 1048576),
			(705, 'guid-nfo-too-large', 'r-nfo-too-large', 'NFO.Release.Large', 'NFO.Release.Large', 1, 2000, 0, 0, 1, -1, 2147483648)`,
	})
}
