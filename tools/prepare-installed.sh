#!/usr/bin/env bash
# Prepare either the released build layout or the current installable source layout.
set -Eeuo pipefail

fail() { printf '%s\n' "$*" >&2; exit 1; }
[[ $# == 1 ]] || fail 'Usage: bash tools/prepare-installed.sh COMPONENT_DIRECTORY'
[[ -d "$1" ]] || fail "Component checkout does not exist: $1"
cd -- "$1"
[[ -f tests/integration/run.sh ]] || fail 'Component checkout has no installed integration runner.'

if [[ -e tools/build.sh || -e tools/build-distribution.sh ]]; then
	[[ -f tools/build.sh && -f tools/build-distribution.sh ]] \
		|| fail 'Legacy component checkout must provide both build scripts.'
	bash tools/build.sh
	bash tools/build-distribution.sh
else
	[[ -f tests/package.php && -f tests/release.php ]] \
		|| fail 'Source component checkout must provide package and release validation scripts.'
	php tests/package.php
	php tests/release.php
fi
