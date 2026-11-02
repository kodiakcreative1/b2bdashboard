#!/usr/bin/env bash
# Isolated local QA only. Never targets the production WordPress site.
set -euo pipefail
cd "$(dirname "$0")/.."
docker_local() { env -u DOCKER_HOST -u DOCKER_CONTEXT -u DOCKER_TLS -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH docker --host=unix:///var/run/docker.sock "$@"; }
prefix="cb2b-qa${CB2B_QA_SUFFIX:-}"
port="${CB2B_QA_PORT:-8088}"
image="${CB2B_QA_IMAGE:-wordpress:php8.3-apache}"
docker_local network inspect cb2b-qa >/dev/null 2>&1 || docker_local network create cb2b-qa >/dev/null
if ! docker_local container inspect "${prefix}-db" >/dev/null 2>&1; then
 docker_local run -d --name "${prefix}-db" --network cb2b-qa -e MARIADB_ROOT_PASSWORD=local-qa-only -e MARIADB_DATABASE=wordpress -e MARIADB_USER=wordpress -e MARIADB_PASSWORD=local-qa-only mariadb:11.4 >/dev/null
fi
if ! docker_local container inspect "${prefix}-wp" >/dev/null 2>&1; then
 docker_local run -d --name "${prefix}-wp" --network cb2b-qa -v "${prefix}-html:/var/www/html" -p "127.0.0.1:${port}:80" \
  -e CB2B_QA_PORT="$port" -e WORDPRESS_DEBUG=1 -e WORDPRESS_DB_HOST="${prefix}-db" -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=local-qa-only -e WORDPRESS_DB_NAME=wordpress \
  -e 'WORDPRESS_CONFIG_EXTRA=define("WP_ENVIRONMENT_TYPE", "local"); define("WP_DEBUG_LOG", true); define("WP_DEBUG_DISPLAY", false); define("DISABLE_WP_CRON", true); if (getenv("HTTPS_PROXY")) { define("WP_PROXY_HOST", parse_url(getenv("HTTPS_PROXY"), PHP_URL_HOST)); define("WP_PROXY_PORT", parse_url(getenv("HTTPS_PROXY"), PHP_URL_PORT)); define("WP_PROXY_BYPASS_HOSTS", "localhost,127.0.0.1"); }' \
  --mount "type=bind,src=$PWD/chamber-b2b-dashboard,dst=/var/www/html/wp-content/plugins/chamber-b2b-dashboard,readonly" \
  --mount "type=bind,src=$PWD/tests,dst=/qa,readonly" \
  --mount type=bind,src=/etc/ssl/certs/ca-certificates.crt,dst=/run/qa-ca-bundle.pem,readonly \
  "$image" >/dev/null
fi
for attempt in {1..30}; do
 if docker_local exec "${prefix}-db" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then break; fi
 sleep 1
done
for attempt in {1..30}; do
 if docker_local exec "${prefix}-wp" test -f /var/www/html/wp-config.php; then break; fi
 sleep 1
done
docker_local exec -u www-data "${prefix}-wp" php /qa/install.php
