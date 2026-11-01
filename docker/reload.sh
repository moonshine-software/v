#!/bin/sh
# New generated classes use the application's dynamic autoloader.
# Restart queue/scheduler via Compose after deployment changes.
set -eu
cd "${APP_DIR:-/var/www/html}"
exec php artisan api-admin:reload
