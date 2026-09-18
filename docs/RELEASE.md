# Composer distribution and release automation

Composer consumes this Git repository as package `joomengine/mcp-client`; do not add a hard-coded version property to composer.json. Version tags identify releases.

## One-time Packagist setup

After the main branch contains the reviewed composer.json, register the repository on Packagist under the intended package name. Configure Packagist's GitHub integration with access to `joomengine/mcp_client`, or its documented authenticated push webhook. Verify an indexed test prerelease and Composer resolution. No Packagist account, registration, webhook or package publication has been configured by this development PR.

References: https://packagist.org/about and https://getcomposer.org/doc/04-schema.md. Packagist indexes tags; it is not an archive-upload step for each release. Do not print Packagist credentials in CI or place them in composer.json.

## Implemented workflow

After review/merge, run **Tested client prerelease** from the main branch and supply a prerelease version without `v`. The workflow validates the version, runs the reusable PHP 8.3/8.4 CI against that commit, then creates a new tag and GitHub prerelease. Existing tags are never replaced. Packagist's configured integration indexes the resulting Git tag automatically; verify indexing rather than assuming it.

This workflow is intentionally operator-triggered and prerelease-only while server interoperability is incomplete. It does not publish from PRs or silently turn every main commit into a stable release. If release creation fails after tagging, inspect the existing tag and finish its release rather than moving the tag.

## Stable release gate

Complete the installed component/JCB acceptance matrix and remote stdio bridge first, record exact tested server and client commits, add the stable-release acceptance job, and only then extend the version policy to stable tags. That remaining work is explicit, not a current claim that a fully certified package is available. Client versions are independent of the Joomla component/plugin package versions.
