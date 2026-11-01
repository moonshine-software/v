#!/bin/sh
set -eu
umask 027
php docker/validate-environment.php
mkdir -p storage/runtime storage/api-admin storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
if [ ! -f storage/runtime/.initialized ]
then
    cp -a /opt/api-admin-seed/. storage/runtime/
    touch storage/runtime/.env
    touch storage/runtime/.initialized
fi
chown -R 82:82 storage
chmod 700 storage/runtime
chmod 600 storage/runtime/.env
