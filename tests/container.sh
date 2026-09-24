#!/usr/bin/env bash
# Real Compose packaging and TLS protocol checks; requires a Linux Docker engine.
set -Eeuo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
for executable in docker node openssl; do
	if ! command -v "$executable" >/dev/null 2>&1; then
		printf 'Container tests require %s.\n' "$executable" >&2
		exit 1
	fi
done
docker compose version >/dev/null
docker info >/dev/null

fixture_directory=$(mktemp -d)
fixture_pid=''
compose_arguments=(--project-name "mcp-client-test-$$" --file "$PWD/compose.yaml" --file "$fixture_directory/compose.json")
cleanup() {
	docker compose "${compose_arguments[@]}" down --remove-orphans >/dev/null 2>&1 || true
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
	-subj '/CN=JoomEngine container test fixture' \
	-addext 'subjectAltName=IP:127.0.0.1' \
	-keyout "$fixture_directory/key.pem" -out "$fixture_directory/cert.pem" \
	>"$fixture_directory/certificate.log" 2>&1
chmod 0644 "$fixture_directory/cert.pem"

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
	printf 'Container TLS fixture did not start.\n' >&2
	exit 1
fi

# Only this isolated fixture uses host networking and a private test CA.
node --input-type=module - "$fixture_directory" >"$fixture_directory/compose.json" <<'NODE'
import { join } from 'node:path';
console.log(JSON.stringify({ services: { mcp: {
	network_mode: 'host',
	entrypoint: ['php', '-d', 'curl.cainfo=/run/mcp-test/ca.pem', '/app/bin/joomengine-mcp'],
	volumes: [{ type: 'bind', source: join(process.argv[2], 'cert.pem'), target: '/run/mcp-test/ca.pem', read_only: true }],
} } }));
NODE

export JOOMENGINE_MCP_URL="https://127.0.0.1:$fixture_port/nested"
export JOOMENGINE_MCP_TOKEN='private-fixture-token'
docker compose "${compose_arguments[@]}" config --quiet
docker compose "${compose_arguments[@]}" build --pull mcp
node tests/container.mjs "$fixture_directory/cert.pem" "${compose_arguments[@]}"
