#!/usr/bin/env bash
# Offline fixtures exercise both supported server layouts without installing Joomla.
set -Eeuo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
temporary=$(mktemp -d)
trap 'rm -rf -- "$temporary"' EXIT
mkdir -p "$temporary/bin" "$temporary/legacy/tools" "$temporary/legacy/tests/integration" \
	"$temporary/source/tests/integration"
touch "$temporary/legacy/tests/integration/run.sh" "$temporary/source/tests/integration/run.sh" \
	"$temporary/source/tests/package.php" "$temporary/source/tests/release.php"
export SETUP_TEST_LOG="$temporary/calls"
unset SETUP_TEST_FAIL

cat >"$temporary/legacy/tools/build.sh" <<'BUILD'
#!/usr/bin/env bash
set -eu
[[ "$PWD" == "$SETUP_TEST_COMPONENT" ]]
printf 'build\n' >>"$SETUP_TEST_LOG"
if [[ "${SETUP_TEST_FAIL:-}" == build ]]; then exit 42; fi
BUILD
cat >"$temporary/legacy/tools/build-distribution.sh" <<'BUILD'
#!/usr/bin/env bash
set -eu
[[ "$PWD" == "$SETUP_TEST_COMPONENT" ]]
printf 'distribution\n' >>"$SETUP_TEST_LOG"
if [[ "${SETUP_TEST_FAIL:-}" == distribution ]]; then exit 43; fi
BUILD
cat >"$temporary/bin/php" <<'PHP'
#!/usr/bin/env bash
set -eu
[[ "$PWD" == "$SETUP_TEST_COMPONENT" && $# == 1 ]]
case "$1" in
	tests/package.php) step=package; status=44 ;;
	tests/release.php) step=release; status=45 ;;
	*) printf 'Unexpected PHP invocation: %s\n' "$*" >&2; exit 99 ;;
esac
printf '%s\n' "$step" >>"$SETUP_TEST_LOG"
if [[ "${SETUP_TEST_FAIL:-}" == "$step" ]]; then exit "$status"; fi
PHP
chmod +x "$temporary/bin/php"
export PATH="$temporary/bin:$PATH"

checks=0
run() {
	local expected_status=$1 expected_calls=$2 status=0
	shift 2
	: >"$SETUP_TEST_LOG"
	bash "$root/tools/prepare-installed.sh" "$@" >"$temporary/output" 2>"$temporary/error" || status=$?
	if [[ "$status" != "$expected_status" || "$(cat "$SETUP_TEST_LOG")" != "$expected_calls" ]]; then
		printf 'Expected status %s, received %s.\n' "$expected_status" "$status" >&2
		cat "$temporary/output" "$temporary/error" "$SETUP_TEST_LOG" >&2
		exit 1
	fi
	if [[ "$expected_status" == 1 && ! -s "$temporary/error" ]]; then
		printf 'Invalid setup did not explain its failure.\n' >&2
		exit 1
	fi
	checks=$((checks + 1))
}

export SETUP_TEST_COMPONENT="$temporary/legacy"
run 0 $'build\ndistribution' "$SETUP_TEST_COMPONENT"
export SETUP_TEST_FAIL=build
run 42 build "$SETUP_TEST_COMPONENT"
export SETUP_TEST_FAIL=distribution
run 43 $'build\ndistribution' "$SETUP_TEST_COMPONENT"
unset SETUP_TEST_FAIL

mv "$temporary/legacy/tools/build.sh" "$temporary/build.sh"
run 1 '' "$SETUP_TEST_COMPONENT"
mv "$temporary/build.sh" "$temporary/legacy/tools/build.sh"
mv "$temporary/legacy/tools/build-distribution.sh" "$temporary/build-distribution.sh"
run 1 '' "$SETUP_TEST_COMPONENT"
mv "$temporary/build-distribution.sh" "$temporary/legacy/tools/build-distribution.sh"
rm "$temporary/legacy/tests/integration/run.sh"
run 1 '' "$SETUP_TEST_COMPONENT"

export SETUP_TEST_COMPONENT="$temporary/source"
run 0 $'package\nrelease' "$SETUP_TEST_COMPONENT"
export SETUP_TEST_FAIL=package
run 44 package "$SETUP_TEST_COMPONENT"
export SETUP_TEST_FAIL=release
run 45 $'package\nrelease' "$SETUP_TEST_COMPONENT"
unset SETUP_TEST_FAIL

rm "$temporary/source/tests/package.php"
run 1 '' "$SETUP_TEST_COMPONENT"
touch "$temporary/source/tests/package.php"
rm "$temporary/source/tests/release.php"
run 1 '' "$SETUP_TEST_COMPONENT"
touch "$temporary/source/tests/release.php"
rm "$temporary/source/tests/integration/run.sh"
run 1 '' "$SETUP_TEST_COMPONENT"

run 1 '' "$temporary/absent"
run 1 '' "$temporary/source/tests/package.php"
run 1 ''
run 1 '' "$temporary/source" extra
printf 'Installed setup: %s checks passed.\n' "$checks"
