#!/bin/sh
set -eu
response=$(SCRIPT_FILENAME=/var/www/html/docker/health.php SCRIPT_NAME=/health.php REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000)
printf '%s\n' "$response" | grep -q 'api-admin-ready'
