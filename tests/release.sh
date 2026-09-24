#!/usr/bin/env bash
# Offline tests: no repository credentials, network calls, tags or releases.
set -Eeuo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
temporary=$(mktemp -d)
trap 'rm -rf -- "$temporary"' EXIT
mkdir "$temporary/bin"
cat >"$temporary/release.json" <<'JSON'
{"version":"1.0.0","component_ref":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","plugin_ref":"cccccccccccccccccccccccccccccccccccccccc"}
JSON
export GITHUB_REF=refs/heads/main GITHUB_EVENT_NAME=push GITHUB_REPOSITORY=joomengine/mcp_client
export GITHUB_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export RELEASE_TEST_LOG="$temporary/mutations"
unset RELEASE_VERSION RELEASE_COMPONENT_REF RELEASE_PLUGIN_REF
cat >"$temporary/bin/gh" <<'MOCK'
#!/usr/bin/env bash
set -eu
if [[ "$1" == release ]]; then
	printf '%s\n' "$*" >>"$RELEASE_TEST_LOG"
	[[ "${RELEASE_TEST_CASE:-}" != create_failure ]]
	printf 'https://github.com/joomengine/mcp_client/releases/tag/test-version\n'
	exit $?
fi
endpoint=${!#}
status=200
body='{}'
case "$endpoint" in
	*/commits/*)
		ref=${endpoint##*/}
		if [[ ! "$ref" =~ ^[0-9a-f]{40}$ ]]; then ref=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb; fi
		body="{\"sha\":\"$ref\"}"
		;;
	*/git/ref/tags/*)
		case "${RELEASE_TEST_CASE:-new}" in
			auth) status=403 ;;
			rate_limit) status=429 ;;
			network) exit 1 ;;
			malformed) printf 'unexpected data\n'; exit 0 ;;
			new|prerelease|create_failure|orphan_release) status=404 ;;
			annotated) body='{"object":{"type":"tag","sha":"cccccccccccccccccccccccccccccccccccccccc"}}' ;;
			mismatch|published_old) body='{"object":{"type":"commit","sha":"dddddddddddddddddddddddddddddddddddddddd"}}' ;;
			*) body="{\"object\":{\"type\":\"commit\",\"sha\":\"$GITHUB_SHA\"}}" ;;
		esac
		;;
	*/git/tags/*) body="{\"object\":{\"type\":\"commit\",\"sha\":\"$GITHUB_SHA\"}}" ;;
	*/releases/tags/*)
		case "${RELEASE_TEST_CASE:-new}" in
			published|published_old|orphan_release) body='{"tag_name":"v1.0.0","draft":false}' ;;
			draft) body='{"tag_name":"v1.0.0","draft":true}' ;;
			release_auth) status=401 ;;
			*) status=404 ;;
		esac
		;;
	*) printf 'Unexpected API request: %s\n' "$endpoint" >&2; exit 1 ;;
esac
printf 'HTTP/2.0 %s Mock\r\nContent-Type: application/json\r\n\r\n%s\n' "$status" "$body"
[[ "$status" == 200 ]]
MOCK
cat >"$temporary/bin/git" <<'MOCK'
#!/usr/bin/env bash
set -eu
if [[ "$1" == rev-parse ]]; then
	printf '%s\n' "$GITHUB_SHA"
else
	printf 'git %s\n' "$*" >>"$RELEASE_TEST_LOG"
fi
MOCK
chmod +x "$temporary/bin/gh" "$temporary/bin/git"
export PATH="$temporary/bin:$PATH"
checks=0
pass() { checks=$((checks + 1)); }
run() { bash "$root/tools/release-plan.sh" "$1" "${2:-$temporary/release.json}" >"$temporary/output" 2>"$temporary/error"; }
contains() { grep -Fxq -- "$1" "$temporary/output" || { cat "$temporary/output"; exit 1; }; pass; }
reject() {
	if run "$1" "${2:-$temporary/release.json}"; then
		printf 'Expected rejection for case %s.\n' "${RELEASE_TEST_CASE:-manifest}" >&2
		exit 1
	fi
	pass
}

run validate
contains 'version=1.0.0'
contains 'publish=false'
for invalid in '{"version":"01.0.0","component_ref":"main","plugin_ref":"main"}' \
	'{"version":"1.0.0","component_ref":"main","plugin_ref":"main"}' \
	'{"version":"1.0.0","component_ref":null,"plugin_ref":true}'; do
	printf '%s\n' "$invalid" >"$temporary/invalid.json"
	reject validate "$temporary/invalid.json"
done

export RELEASE_TEST_CASE=new
run plan
contains 'publish=true'
contains 'verify=true'
contains 'create_tag=true'
contains 'component_ref=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
[[ ! -e "$RELEASE_TEST_LOG" ]] || exit 1
pass
run publish
if grep -vE '^[a-z_]+=[A-Za-z0-9_.-]+$' "$temporary/output"; then
	printf 'Publication emitted invalid GitHub Actions output.\n' >&2
	exit 1
fi
pass
grep -Fxq "git tag v1.0.0 $GITHUB_SHA" "$RELEASE_TEST_LOG"
grep -Fxq 'git push origin refs/tags/v1.0.0' "$RELEASE_TEST_LOG"
grep -Fq 'release create v1.0.0' "$RELEASE_TEST_LOG"
pass

for RELEASE_TEST_CASE in recovery annotated; do
	export RELEASE_TEST_CASE
	: >"$RELEASE_TEST_LOG"
	run plan
	contains 'create_tag=false'
	run publish
	[[ "$(wc -l <"$RELEASE_TEST_LOG")" == 1 ]]
	grep -Fq 'release create v1.0.0' "$RELEASE_TEST_LOG"
	pass
done
for RELEASE_TEST_CASE in published published_old; do
	export RELEASE_TEST_CASE
	: >"$RELEASE_TEST_LOG"
	run publish
	contains 'publish=false'
	if [[ "$RELEASE_TEST_CASE" == published ]]; then contains 'verify=true'; else contains 'verify=false'; fi
	[[ ! -s "$RELEASE_TEST_LOG" ]]
	pass
done
for RELEASE_TEST_CASE in mismatch orphan_release draft auth rate_limit network malformed release_auth; do
	export RELEASE_TEST_CASE
	: >"$RELEASE_TEST_LOG"
	reject publish
	[[ ! -s "$RELEASE_TEST_LOG" ]] || exit 1
done
export RELEASE_TEST_CASE=create_failure
reject publish
export RELEASE_TEST_CASE=new GITHUB_EVENT_NAME=pull_request
reject publish
export GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/feature
reject publish
export GITHUB_REF=refs/heads/main GITHUB_EVENT_NAME=workflow_dispatch RELEASE_VERSION=1.1.0-rc.1
export RELEASE_COMPONENT_REF=v0.1.1 RELEASE_PLUGIN_REF=main RELEASE_TEST_CASE=prerelease
: >"$RELEASE_TEST_LOG"
run publish
contains 'version=1.1.0-rc.1'
grep -Fq -- '--prerelease' "$RELEASE_TEST_LOG"
pass
export RELEASE_VERSION=1.0.0 RELEASE_TEST_CASE=published_old
reject publish
export RELEASE_VERSION='1.0.0;false'
reject publish
printf 'Release declaration and publication planning: %s checks passed.\n' "$checks"
