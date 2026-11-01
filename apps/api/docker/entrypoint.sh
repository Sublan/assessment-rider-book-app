#!/bin/sh
set -e

# Wait for MySQL. Migrations on a fresh volume race the database's first
# start, and "container exited because the DB was not ready" is an avoidable
# failure mode.
if [ "${DB_CONNECTION}" = "mysql" ]; then
  echo "waiting for mysql at ${DB_HOST}:${DB_PORT}..."
  i=0
  until php -r "exit(@fsockopen(getenv('DB_HOST'), (int) getenv('DB_PORT')) ? 0 : 1);" 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
      echo "mysql did not become reachable in 60s" >&2
      exit 1
    fi
    sleep 1
  done
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  echo "running migrations..."
  php artisan migrate --force --no-interaction
fi

# The seeder is idempotent (updateOrCreate on fixed UUIDs), so re-running it on
# every boot is safe; it only fills in the demo directory rows.
if [ "${SEED_DEMO:-false}" = "true" ]; then
  echo "seeding demo data..."
  php artisan db:seed --force --no-interaction
fi

if [ "${APP_ENV}" = "production" ] || [ "${APP_ENV}" = "staging" ]; then
  php artisan config:cache
  php artisan route:cache
fi

exec "$@"
