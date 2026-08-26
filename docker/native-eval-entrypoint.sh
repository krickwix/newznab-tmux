#!/usr/bin/env sh
set -eu

if [ ! -f .env ]; then
    echo "Creating .env file from environment variables..."
    envsubst < .env.dist > .env
fi

echo "Waiting for MariaDB to accept PDO connections..."
attempts=60
until php -r '
$dsn = sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE"));
new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
' >/dev/null 2>&1; do
    attempts=$((attempts - 1))
    if [ "$attempts" -le 0 ]; then
        echo "Failed to connect to MariaDB with PDO." >&2
        exit 1
    fi
    echo "MariaDB not ready yet. Attempts left: ${attempts}."
    sleep 1
done

if [ ! -f '_install/install.lock' ]; then
    echo "Creating folders structure"
    mkdir -p /app/storage/app/public
    mkdir -p /app/storage/framework/cache
    mkdir -p /app/storage/framework/sessions
    mkdir -p /app/storage/framework/views
    mkdir -p "$COVERS_PATH/anime" "$COVERS_PATH/audio" "$COVERS_PATH/audiosample" "$COVERS_PATH/book"
    mkdir -p "$COVERS_PATH/console" "$COVERS_PATH/games" "$COVERS_PATH/movies" "$COVERS_PATH/music"
    mkdir -p "$COVERS_PATH/preview" "$COVERS_PATH/sample" "$COVERS_PATH/tvrage" "$COVERS_PATH/tvshows"
    mkdir -p "$COVERS_PATH/video" "$COVERS_PATH/xxx" "$PATH_TO_NZBS" "$TEMP_UNRAR_PATH" "$TEMP_UNZIP_PATH"
    chmod -R 775 bootstrap/cache
    chmod -R 777 storage resources public/covers

    mariadb -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" <<'SQL'
CREATE TABLE IF NOT EXISTS settings (
  name varchar(25) NOT NULL DEFAULT '',
  value varchar(1000) NOT NULL DEFAULT '',
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
INSERT INTO settings (name, value) VALUES
  ('categorizeforeign', '1'),
  ('innerfileblacklist', '/setup.exe|password.url/i')
ON DUPLICATE KEY UPDATE value = VALUES(value);
SQL

    php artisan config:clear || true
    php artisan cache:clear || true
    php artisan route:clear || true
    php artisan view:clear || true

    echo "NNTmux installation..."
    php artisan nntmux:install --yes

    php artisan nntmux:populate --manticore --releases || true
    php artisan nntmux:populate --manticore --predb || true
fi

exec docker-php-entrypoint "$@"
