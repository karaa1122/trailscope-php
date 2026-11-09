#!/usr/bin/env sh
# Static checks for the PHP SDK, in Docker (no local PHP needed):
#   php -l on the lowest (8.2) and newest (8.5) supported PHP, Pint, PHPStan.
# Usage: sdks/php/scripts/check.sh [--fix]   (--fix lets Pint rewrite files)
set -eu

PHP_MIN_IMAGE=php:8.2.35-cli-alpine
PHP_IMAGE=php:8.5.12-cli-alpine
COMPOSER_IMAGE=composer:2.11.1

ROOT=$(cd "$(dirname "$0")/.." && pwd)
RUN="docker run --rm --user $(id -u):$(id -g) -e COMPOSER_HOME=/tmp/composer -v $ROOT:/app -w /app"

if [ ! -d "$ROOT/vendor" ]; then
  $RUN "$COMPOSER_IMAGE" install --no-interaction --no-progress
fi

for image in "$PHP_MIN_IMAGE" "$PHP_IMAGE"; do
  echo "php -l ($image)"
  $RUN "$image" sh -c "find src config bench -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null"
done

if [ "${1:-}" = "--fix" ]; then
  $RUN "$PHP_IMAGE" vendor/bin/pint
else
  $RUN "$PHP_IMAGE" vendor/bin/pint --test
fi
$RUN "$PHP_IMAGE" php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
