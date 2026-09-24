# Composer distribution and release automation

Composer package `joomengine/mcp-client` is versioned by Git tags, with no hard-coded version property. The package registers `bin/joomengine-mcp`; library and executable versions are independent of component/plugin versions.

## One-time Packagist setup

After the reviewed `composer.json` is on main, register `joomengine/mcp_client` on Packagist and configure Packagist's GitHub integration or authenticated push webhook. Verify the package name and an indexed test prerelease through Composer. This development PR does not configure an external Packagist account and does not publish a version.

References: https://packagist.org/about and https://getcomposer.org/doc/04-schema.md. Packagist indexes Git tags; releases do not require uploading an archive or committing credentials.

## Tested release workflow

Run **Tested client release** on main with a semantic version without `v`, plus the component and console-plugin refs to test. Prefer immutable tags or full commit IDs for the dependencies. The workflow accepts stable versions and `alpha.N`, `beta.N` or `rc.N` prereleases.

Before creating a tag, it runs the full PHP 8.3/8.4 contract/TLS suite, the Docker Compose build/HTTPS suites on both PHP versions, and the installed Joomla interoperability workflow using that same client commit. The installed workflow records all three actual commit IDs. Any failed job prevents tagging. Releases cannot run from pull requests, and existing tags are never moved or overwritten.

After testing, the workflow creates the tag and matching GitHub release, setting prerelease status when appropriate. Packagist's configured integration should index the tag; verify that externally rather than assuming it occurred. If release creation fails after tagging, inspect the existing tag and complete its release without moving the tag.

For local installed acceptance, supply `JOOMENGINE_MCP_URL` and `JOOMENGINE_MCP_TOKEN` and run `php tests/live.php`; a private test CA can be configured through PHP's `curl.cainfo`. Missing configuration fails. Full component/JCB write and job acceptance remains owned by the installed component's fixture, rather than a duplicated business-operation catalogue in this package.
