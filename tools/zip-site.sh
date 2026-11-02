#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
docker_local() { env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH docker --host=unix:///var/run/docker.sock "$@"; }
if ! docker_local container inspect cb2b-qa-zip-wp >/dev/null 2>&1; then
 docker_local run -d --name cb2b-qa-zip-wp --network cb2b-qa -v cb2b-qa-zip-html:/var/www/html -p 127.0.0.1:8090:80 \
  -e WORDPRESS_DB_HOST=cb2b-qa-db -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=local-qa-only -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_TABLE_PREFIX=zipqa_ \
  -e CB2B_QA_PORT=8090 -e CB2B_QA_SKIP_PLUGIN_ACTIVATE=1 -e WORDPRESS_DEBUG=1 \
  -e 'WORDPRESS_CONFIG_EXTRA=define("WP_ENVIRONMENT_TYPE", "local"); define("WP_DEBUG_LOG", true); define("WP_DEBUG_DISPLAY", false); define("DISABLE_WP_CRON", true);' \
  --mount "type=bind,src=$PWD/tests,dst=/qa,readonly" wordpress:php8.3-apache >/dev/null
fi
for attempt in {1..30}; do
 if docker_local exec cb2b-qa-zip-wp test -f /var/www/html/wp-config.php; then break; fi
 sleep 1
done
docker_local exec -u www-data cb2b-qa-zip-wp php /qa/install.php
