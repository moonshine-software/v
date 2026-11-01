#!/bin/sh
set -eu
# Executed only by the explicit restore command against the dedicated state volume.
find /var/www/html/storage -mindepth 1 -maxdepth 1 -exec rm -rf {} +
tar -C /var/www/html/storage -xzf -
chown -R 82:82 /var/www/html/storage
