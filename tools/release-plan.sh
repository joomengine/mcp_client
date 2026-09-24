#!/usr/bin/env bash
# Validate the reviewed release declaration, plan publication, or publish tested code.
set -Eeuo pipefail

mode=${1:-validate}
manifest=${2:-release.json}
[[ $# -le 2 && "$mode" =~ ^(validate|plan|publish)$ ]] || {
	printf 'Usage: bash tools/release-plan.sh [validate|plan|publish] [release.json]\n' >&2
	exit 2
}
fail() { printf '%s\n' "$*" >&2; exit 1; }
semver='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(alpha|beta|rc)\.(0|[1-9][0-9]*))?$'
jq -e 'type == "object" and (keys == ["component_ref", "plugin_ref", "version"])
	and (.version | type == "string") and (.component_ref | type == "string")
	and (.plugin_ref | type == "string")' "$manifest" >/dev/null || fail 'Invalid release manifest.'
version=$(jq -r .version "$manifest")
component_ref=$(jq -r .component_ref "$manifest")
plugin_ref=$(jq -r .plugin_ref "$manifest")
[[ "$version" =~ $semver ]] || fail 'The manifest must declare a semantic release version.'
[[ "$component_ref" =~ ^[0-9a-f]{40}$ && "$plugin_ref" =~ ^[0-9a-f]{40}$ ]] \
	|| fail 'Manifest component and plugin references must be immutable commit IDs.'

if [[ "$mode" == validate ]]; then
	printf 'version=%s\ncomponent_ref=%s\nplugin_ref=%s\npublish=false\n' "$version" "$component_ref" "$plugin_ref"
	exit 0
fi

[[ "${GITHUB_REF:-}" == refs/heads/main ]] || fail 'Releases can only run from main.'
[[ "${GITHUB_SHA:-}" =~ ^[0-9a-f]{40}$ ]] || fail 'Missing immutable client commit.'
[[ "${GITHUB_REPOSITORY:-}" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || fail 'Invalid GitHub repository.'
[[ "${GITHUB_EVENT_NAME:-}" =~ ^(push|workflow_dispatch)$ ]] || fail 'This event cannot publish releases.'

temporary=$(mktemp -d)
trap 'rm -rf -- "$temporary"' EXIT

# Only an actual HTTP 404 means absent. Authentication, rate limits, transport
# failures and unexpected responses must never turn into permission to create a tag.
api_get() {
	local endpoint=$1 status result=0
	gh api --include --method GET "$endpoint" >"$temporary/response" 2>"$temporary/error" || result=$?
	status=$(sed -n '1s/^HTTP\/[0-9.]* \([0-9][0-9][0-9]\).*$/\1/p' "$temporary/response")
	awk 'body { print } /^\r?$/ { body = 1 }' "$temporary/response" >"$temporary/body"
	if [[ "$status" == 404 && "$result" != 0 ]]; then return 4; fi
	if [[ "$status" != 200 || "$result" != 0 ]]; then
		cat "$temporary/error" >&2
		fail "Cannot verify GitHub release state (HTTP ${status:-unavailable})."
	fi
	jq -e 'type == "object"' "$temporary/body" >/dev/null || fail 'Invalid GitHub API response.'
	cat "$temporary/body"
}

resolve_ref() {
	local repository=$1 ref=$2 response resolved
	[[ "$ref" =~ ^[A-Za-z0-9._/-]+$ && "$ref" != -* ]] || fail 'Invalid server reference.'
	response=$(api_get "repos/$repository/commits/$ref") || fail "Cannot resolve $repository reference."
	resolved=$(jq -er '.sha | select(test("^[0-9a-f]{40}$"))' <<<"$response") || fail 'Invalid resolved server commit.'
	if [[ "$ref" =~ ^[0-9a-f]{40}$ && "$resolved" != "$ref" ]]; then
		fail 'GitHub resolved an immutable server reference to a different commit.'
	fi
	printf '%s\n' "$resolved"
}

if [[ "$GITHUB_EVENT_NAME" == workflow_dispatch ]]; then
	version=${RELEASE_VERSION:-$version}
	component_ref=${RELEASE_COMPONENT_REF:-$component_ref}
	plugin_ref=${RELEASE_PLUGIN_REF:-$plugin_ref}
fi
[[ "$version" =~ $semver ]] || fail 'Invalid requested semantic release version.'
component_ref=$(resolve_ref joomengine/mcp_component "$component_ref")
plugin_ref=$(resolve_ref joomengine/mcp_plugin "$plugin_ref")
tag="v$version"
publish=true
verify=true
create_tag=true
tag_response=''
if tag_response=$(api_get "repos/$GITHUB_REPOSITORY/git/ref/tags/$tag"); then
	create_tag=false
	tag_type=$(jq -er .object.type <<<"$tag_response")
	tag_commit=$(jq -er '.object.sha | select(test("^[0-9a-f]{40}$"))' <<<"$tag_response")
	for ((depth = 0; depth < 8; depth++)); do
		[[ "$tag_type" == tag ]] || break
		tag_response=$(api_get "repos/$GITHUB_REPOSITORY/git/tags/$tag_commit") || fail 'Cannot resolve annotated tag.'
		tag_type=$(jq -er .object.type <<<"$tag_response")
		tag_commit=$(jq -er '.object.sha | select(test("^[0-9a-f]{40}$"))' <<<"$tag_response")
	done
	[[ "$tag_type" == commit ]] || fail 'The version tag does not resolve to a commit.'
else
	status=$?
	[[ "$status" == 4 ]] || exit "$status"
fi

if release_response=$(api_get "repos/$GITHUB_REPOSITORY/releases/tags/$tag"); then
	[[ "$create_tag" == false ]] || fail 'Published release has no corresponding tag.'
	jq -e --arg tag "$tag" '.tag_name == $tag and .draft == false' <<<"$release_response" >/dev/null \
		|| fail 'An existing draft or inconsistent release needs maintainer review.'
	if [[ "$GITHUB_EVENT_NAME" == workflow_dispatch && "$tag_commit" != "$GITHUB_SHA" ]]; then
		fail 'The requested version already belongs to another commit.'
	fi
	publish=false
	if [[ "$tag_commit" != "$GITHUB_SHA" ]]; then verify=false; fi
	printf 'Version %s is already published; leaving its immutable tag unchanged.\n' "$version" >&2
else
	status=$?
	[[ "$status" == 4 ]] || exit "$status"
	if [[ "$create_tag" == false && "$tag_commit" != "$GITHUB_SHA" ]]; then
		fail 'An unreleased version tag belongs to another commit; it will not be moved.'
	fi
fi

if [[ "$mode" == publish && "$publish" == true ]]; then
	[[ "$(git rev-parse HEAD)" == "$GITHUB_SHA" ]] || fail 'Checkout differs from the tested client commit.'
	if [[ "$create_tag" == true ]]; then
		git tag "$tag" "$GITHUB_SHA" >&2
		git push origin "refs/tags/$tag" >&2
	fi
	release_flags=()
	if [[ "$version" == *-* ]]; then release_flags+=(--prerelease); fi
	gh release create "$tag" --repo "$GITHUB_REPOSITORY" --verify-tag \
		"${release_flags[@]}" --generate-notes --title "JoomEngine MCP Client $version" >&2
fi

printf 'version=%s\ntag=%s\ncomponent_ref=%s\nplugin_ref=%s\nclient_ref=%s\npublish=%s\nverify=%s\ncreate_tag=%s\n' \
	"$version" "$tag" "$component_ref" "$plugin_ref" "$GITHUB_SHA" "$publish" "$verify" "$create_tag"
