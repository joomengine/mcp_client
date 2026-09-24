# Composer distribution and release automation

Composer package [`joomengine/mcp-client`](https://packagist.org/packages/joomengine/mcp-client) is registered against [`joomengine/mcp_client`](https://github.com/joomengine/mcp_client). Semantic versions come from Git tags, with no hard-coded version property in `composer.json`. The package registers `bin/joomengine-mcp`; library and executable versions are independent of component/plugin versions.

## Current distribution and installation

Packagist's public metadata was checked on 24 September 2026: `dev-main` points to this repository, and no tagged release is indexed. Registration makes the development branch installable; it does not create a stable release. The README's live version badge therefore displays Packagist's `dev-main` version.

```bash
composer show --all joomengine/mcp-client
composer require joomengine/mcp-client:dev-main
composer check-platform-reqs
./vendor/bin/joomengine-mcp help
```

Use the explicit development constraint until a tested stable tag is indexed. For a new project after a stable release, `composer require joomengine/mcp-client` selects a compatible stable version. Existing development users must replace their `dev-main` requirement with the chosen release constraint. Composer installs the executable proxy under the consuming project's `vendor/bin`; this is distinct from `bin/joomengine-mcp` in a source checkout.

## Keep Packagist synchronized

Packagist registration is already complete. A package maintainer should check [Packagist's package list](https://packagist.org/profile/) for an automatic-sync warning and use [Packagist's GitHub integration instructions](https://packagist.org/about#how-to-update-packages) if synchronization needs configuring. The integration must have access to the `joomengine` organization and this repository. Alternatively, configure the authenticated GitHub push webhook documented by Packagist, keeping the API token in the webhook secret setting.

The public listing and a successful consumer install verify published metadata, not the account's webhook configuration. After a main-branch push or a new tag, check the package's indexed source reference and version. A logged-in maintainer can trigger an update from the package page if necessary. No Packagist credentials belong in source files or workflow logs; the public consumer test requires none.

References: [Packagist versioning and update schedule](https://packagist.org/about#managing-package-versions), [Composer package schema](https://getcomposer.org/doc/04-schema.md) and [Composer versions and constraints](https://getcomposer.org/doc/articles/versions.md). Packagist indexes Git tags; releases do not require uploading a separate archive.

## Verify the package as a consumer

From a development checkout on Linux with Bash, PHP 8.3+, Composer 2, cURL, Node.js, OpenSSL and GNU `timeout` installed:

```bash
composer install
composer test
composer test:packagist
```

The consumer test resolves the public package in a new Composer project without a local path or VCS repository override. It checks package metadata and autoloading, runs Composer's generated executable, and exercises the installed SDK and bridge against a local HTTPS fixture with a private test CA. Its output records the selected package version and source reference; CI saves the report and log under `build/packagist/`. Set `PACKAGIST_REPORT_PATH` to save the JSON report locally. These checks need no Joomla installation or real site token. Missing requirements, an unavailable package or a protocol failure fail the test.

`composer test:packagist -- dev-main` selects a specific version constraint. With no argument, the runner selects the latest compatible stable version when available, falling back to `dev-main` when no stable release is indexed. The [Packagist consumer workflow](https://github.com/joomengine/mcp_client/actions/workflows/packagist.yml) runs on PHP 8.3 and 8.4 for pull requests and main-branch pushes, and supports a manual version input. CI retains the installation and fixture evidence as artifacts.

On a PR, the registry install tests the version already indexed by Packagist, not unpublished PR code. The separate PHP contract and Docker checks exercise the proposed source. Neither layer substitutes for installed Joomla acceptance.

## Tested release workflow

Run **Tested client release** on main with a semantic version without `v`, plus the component and console-plugin refs to test. Prefer immutable tags or full commit IDs for the dependencies. The workflow accepts stable versions and `alpha.N`, `beta.N` or `rc.N` prereleases.

Before creating a tag, it runs the full PHP 8.3/8.4 contract/TLS suite, the Docker Compose build/HTTPS suites on both PHP versions, and the installed Joomla interoperability workflow using that same client commit. The installed workflow records all three actual commit IDs. Any failed job prevents tagging. Releases cannot run from pull requests, and existing tags are never moved or overwritten.

After testing, the workflow creates the tag and matching GitHub release, setting prerelease status when appropriate. Packagist's configured integration should index the tag; verify that externally rather than assuming it occurred. If release creation fails after tagging, inspect the existing tag and complete its release without moving the tag.

After Packagist indexes the tag:

1. Confirm the exact version and source commit in `composer show --all joomengine/mcp-client` and on the [package page](https://packagist.org/packages/joomengine/mcp-client).
2. Run **Packagist consumer** manually with that exact version. Both PHP versions must pass against the downloaded distribution.
3. For the first stable release, update the README's primary installation command and development-status text. Replace its development-version badge with the standard [Packagist release badge](https://img.shields.io/packagist/v/joomengine/mcp-client), retaining the link to the package page. This badge then tracks subsequent stable releases automatically.

For local installed acceptance, supply `JOOMENGINE_MCP_URL` and `JOOMENGINE_MCP_TOKEN` and run `php tests/live.php`; a private test CA can be configured through PHP's `curl.cainfo`. Missing configuration fails. Full component/JCB write and job acceptance remains owned by the installed component's fixture, rather than a duplicated business-operation catalogue in this package.
