#!/usr/bin/env bash
# Install the real registry package in an isolated consumer, then test its public API.
set -Eeuo pipefail

test_directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
export PACKAGIST_REPORT_PATH=${PACKAGIST_REPORT_PATH:-"$test_directory/../build/packagist/consumer.json"}
constraint=${1:-auto}
expected_reference=${PACKAGIST_EXPECTED_REFERENCE:-}
wait_for_index=${PACKAGIST_WAIT_FOR_INDEX:-false}
if (( $# > 1 )) || [[ -z "$constraint" ]]; then
	printf 'Usage: bash tests/packagist.sh [auto|COMPOSER_VERSION_CONSTRAINT]\n' >&2
	exit 2
fi
if [[ "$wait_for_index" != true && "$wait_for_index" != false ]]; then
	printf 'PACKAGIST_WAIT_FOR_INDEX must be true or false.\n' >&2
	exit 2
fi
if [[ -n "$expected_reference" && ! "$expected_reference" =~ ^[0-9a-f]{40}$ ]]; then
	printf 'PACKAGIST_EXPECTED_REFERENCE must be a full lowercase Git commit SHA.\n' >&2
	exit 2
fi
if [[ "$wait_for_index" == true || -n "$expected_reference" ]]; then
	if [[ ! "$constraint" =~ ^v?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(alpha|beta|rc)\.(0|[1-9][0-9]*))?$ ]]; then
		printf 'Release verification requires an exact MAJOR.MINOR.PATCH version, optionally with -alpha.N, -beta.N or -rc.N.\n' >&2
		exit 2
	fi
fi
export PACKAGIST_EXPECTED_REFERENCE="$expected_reference"
for executable in php composer curl node openssl timeout; do
	if ! command -v "$executable" >/dev/null 2>&1; then
		printf 'Packagist consumer tests require %s.\n' "$executable" >&2
		exit 1
	fi
done
php -r 'if (PHP_VERSION_ID < 80300 || !extension_loaded("curl")) { fwrite(STDERR, "PHP 8.3+ with ext-curl is required.\n"); exit(1); }'

consumer_directory=$(mktemp -d)
fixture_pid=''
cleanup() {
	if [[ -n "$fixture_pid" ]]; then
		kill "$fixture_pid" 2>/dev/null || true
		wait "$fixture_pid" 2>/dev/null || true
	fi
	rm -rf -- "$consumer_directory"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# A fresh Composer home/cache prevents local repositories, credentials and cached
# packages from disguising a broken public installation. No checkout autoloader,
# VCS/path repository override is supplied by this harness.
unset COMPOSER_AUTH COMPOSER_VENDOR_DIR COMPOSER_BIN_DIR COMPOSER_REPO_PACKAGIST COMPOSER_ROOT_VERSION
unset COMPOSER_DISABLE_NETWORK COMPOSER_IGNORE_PLATFORM_REQS COMPOSER_IGNORE_PLATFORM_REQ
unset COMPOSER_PREFER_STABLE COMPOSER_PREFER_LOWEST COMPOSER_MINIMAL_CHANGES
export COMPOSER="$consumer_directory/composer.json"
export COMPOSER_HOME="$consumer_directory/composer-home"
export COMPOSER_CACHE_DIR="$consumer_directory/composer-cache"
export COMPOSER_NO_INTERACTION=1
cat > "$COMPOSER" <<'JSON'
{
  "name": "joomengine/packagist-consumer-test",
  "description": "Temporary public registry consumer integration test",
  "license": "proprietary",
  "require": {},
  "config": { "allow-plugins": false, "secure-http": true }
}
JSON

if [[ "$wait_for_index" == true ]]; then
	printf 'Waiting up to 300 seconds for Packagist to index joomengine/mcp-client %s.\n' "$constraint"
	index_deadline=$((SECONDS + 300))
	while true; do
		remaining=$((index_deadline - SECONDS))
		if (( remaining <= 0 )); then
			printf 'Packagist did not index %s within 300 seconds; no package was substituted.\n' "$constraint" >&2
			exit 1
		fi
		request_timeout=$((remaining < 30 ? remaining : 30))
		# Read public Composer metadata only. HTTP failures are fatal; only an
		# otherwise valid index missing the requested tag is polled again.
		curl --disable --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
			--connect-timeout 15 --max-time "$request_timeout" \
			'https://repo.packagist.org/p2/joomengine/mcp-client.json' \
			--output "$consumer_directory/packagist.json"
		if php "$test_directory/consumer.php" --index-ready "$consumer_directory/packagist.json" \
			"$constraint" "$expected_reference"; then
			break
		else
			index_status=$?
			if (( index_status != 3 )); then
				exit "$index_status"
			fi
		fi
		remaining=$((index_deadline - SECONDS))
		if (( remaining > 0 )); then
			printf 'Release %s is not indexed yet; checking again in %s seconds.\n' \
				"$constraint" "$((remaining < 15 ? remaining : 15))"
			sleep "$((remaining < 15 ? remaining : 15))"
		fi
	done
fi

if [[ "$constraint" == auto ]]; then
	curl --disable --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
		--connect-timeout 15 --max-time 60 \
		'https://repo.packagist.org/p2/joomengine/mcp-client.json' \
		--output "$consumer_directory/packagist.json"
	constraint=$(php -r '
		$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
		$packages = $data["packages"]["joomengine/mcp-client"] ?? null;
		if (!is_array($packages)) { throw new RuntimeException("Invalid Packagist package metadata."); }
		foreach ($packages as $package) {
			if (preg_match("/\\Av?\\d+(?:\\.\\d+){0,3}(?:\\+[0-9A-Za-z.-]+)?\\z/D", $package["version"] ?? "")) {
				echo "*"; exit;
			}
		}
		echo "dev-main";
	' "$consumer_directory/packagist.json")
fi
printf 'Installing public Packagist package joomengine/mcp-client (%s).\n' "$constraint"
if [[ "$constraint" == dev-main ]]; then
	printf 'Development branch selected explicitly; this is not a tagged stable release.\n'
fi
composer --working-dir="$consumer_directory" require --no-interaction --no-progress \
	--prefer-dist --no-plugins --no-scripts --update-no-dev -- "joomengine/mcp-client:$constraint"
composer --working-dir="$consumer_directory" check-platform-reqs --no-dev
if [[ -n "${PACKAGIST_REPORT_PATH:-}" ]]; then
	evidence_directory=$(dirname -- "$PACKAGIST_REPORT_PATH")
	mkdir -p -- "$evidence_directory"
	cp -- "$consumer_directory/composer.json" "$evidence_directory/consumer-composer.json"
	cp -- "$consumer_directory/composer.lock" "$evidence_directory/consumer-composer.lock"
fi

openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
	-subj '/CN=JoomEngine consumer fixture' -addext 'subjectAltName=IP:127.0.0.1' \
	-keyout "$consumer_directory/key.pem" -out "$consumer_directory/cert.pem" \
	>"$consumer_directory/certificate.log" 2>&1
node "$test_directory/consumer-server.mjs" "$consumer_directory/key.pem" \
	"$consumer_directory/cert.pem" "$consumer_directory/audit.jsonl" \
	>"$consumer_directory/port" 2>"$consumer_directory/server.log" &
fixture_pid=$!
for ((attempt = 0; attempt < 100; attempt++)); do
	[[ -s "$consumer_directory/port" ]] && break
	if ! kill -0 "$fixture_pid" 2>/dev/null; then
		cat "$consumer_directory/server.log" >&2
		exit 1
	fi
	sleep 0.05
done
fixture_port=$(<"$consumer_directory/port")
if [[ ! "$fixture_port" =~ ^[0-9]+$ ]]; then
	printf 'Consumer TLS fixture failed to start.\n' >&2
	exit 1
fi

timeout 120 php -d "curl.cainfo=$consumer_directory/cert.pem" \
	"$test_directory/consumer.php" "$consumer_directory" \
	"https://127.0.0.1:$fixture_port/nested" "$consumer_directory/audit.jsonl"
printf 'Public package consumer checks passed; fixture protocol only, no installed Joomla site.\n'
