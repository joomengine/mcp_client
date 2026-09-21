#!/usr/bin/env bash
# Real loopback TLS exchanges; no Joomla installation or external network needed.
set -Eeuo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
for executable in php node openssl; do
	if ! command -v "$executable" >/dev/null 2>&1; then
		printf 'HTTP tests require %s.\n' "$executable" >&2
		exit 1
	fi
done

fixture_directory=$(mktemp -d)
fixture_pid=''
cleanup() {
	if [[ -n "$fixture_pid" ]]; then
		kill "$fixture_pid" 2>/dev/null || true
		wait "$fixture_pid" 2>/dev/null || true
	fi
	rm -rf -- "$fixture_directory"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
	-subj '/CN=JoomEngine local test fixture' \
	-addext 'subjectAltName=IP:127.0.0.1' \
	-keyout "$fixture_directory/key.pem" -out "$fixture_directory/cert.pem" \
	>"$fixture_directory/certificate.log" 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
	-subj '/CN=Unrelated local test CA' \
	-keyout "$fixture_directory/unrelated-key.pem" -out "$fixture_directory/unrelated-ca.pem" \
	>"$fixture_directory/unrelated-certificate.log" 2>&1

node tests/http-server.mjs "$fixture_directory/key.pem" "$fixture_directory/cert.pem" \
	>"$fixture_directory/port" 2>"$fixture_directory/server.log" &
fixture_pid=$!
for ((attempt = 0; attempt < 100; attempt++)); do
	[[ -s "$fixture_directory/port" ]] && break
	if ! kill -0 "$fixture_pid" 2>/dev/null; then
		cat "$fixture_directory/server.log" >&2
		exit 1
	fi
	sleep 0.05
done
fixture_port=$(<"$fixture_directory/port")
if [[ ! "$fixture_port" =~ ^[0-9]+$ ]]; then
	printf 'TLS fixture did not start.\n' >&2
	exit 1
fi

php -d "curl.cainfo=$fixture_directory/cert.pem" tests/http.php \
	"https://127.0.0.1:$fixture_port/nested" "$fixture_directory/unrelated-ca.pem"
