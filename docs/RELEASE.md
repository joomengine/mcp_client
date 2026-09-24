# Composer distribution and release automation

Composer package [`joomengine/mcp-client`](https://packagist.org/packages/joomengine/mcp-client) is registered against [`joomengine/mcp_client`](https://github.com/joomengine/mcp_client). Semantic versions come from Git tags, with no hard-coded version property in `composer.json`. The package registers `bin/joomengine-mcp`; library and executable versions are independent of component/plugin versions.

## Current distribution and installation

The first stable client release target is **1.0.0**. During release preparation on 24 September 2026, Packagist's public metadata indexed only `dev-main` from this repository. Registration makes the development branch installable; it does not create a stable release. Check the live [package page](https://packagist.org/packages/joomengine/mcp-client) and [GitHub Releases](https://github.com/joomengine/mcp_client/releases) for current publication status. The README's standard Packagist badge tracks the latest indexed stable version.

After the tested `v1.0.0` tag is published and indexed:

```bash
composer show --all joomengine/mcp-client
composer require 'joomengine/mcp-client:^1.0'
composer check-platform-reqs
./vendor/bin/joomengine-mcp help
```

Until indexing completes, `composer require joomengine/mcp-client:dev-main` remains the explicit development fallback. Existing development users can switch to stable with `composer require 'joomengine/mcp-client:^1.0' --with-dependencies`. Composer installs the executable proxy under the consuming project's `vendor/bin`; this is distinct from `bin/joomengine-mcp` in a source checkout.

## First stable release manifest

The client follows its own semantic versioning: **1.0.0** identifies the first stable external client API and executable. The installed component currently declares **0.1.1**, and the console plugin declares **0.1.0**. These package versions do not need to match. Interoperability is tested against concrete source revisions and the installed MCP protocol.

The reviewed [`release.json`](../release.json) declares these first-release inputs:

| Manifest field | Exact value |
| --- | --- |
| `version` | `1.0.0` |
| `component_ref` | `14c715c50c2cc29fd3c8cc3c4780442efb507398` |
| `plugin_ref` | `993522852770e2f8968ab066deedef00f174d8c7` |

These immutable component/plugin refs are the release-test targets. [**Tested client release**](https://github.com/joomengine/mcp_client/actions/workflows/release.yml) records the actual client revision and installs the component/plugin in its Joomla 6.1+ fixture on PHP 8.3 and 8.4. The manifest declares intent; it is not evidence that `v1.0.0` has been published or indexed.

## Publish through review and merge

1. Update `release.json` with the new client semantic version and the full component/plugin commit IDs to test. Add that version's changes to `CHANGELOG.md`; `composer.json` continues to omit a version property.
2. Open a pull request. The release workflow validates the proposed manifest with read-only permissions. Pull requests cannot create tags or releases. Source, Docker and installed interoperability checks validate the proposed client changes separately.
3. Review and merge the PR into `main`. The main-branch release workflow reads the manifest. A new version triggers the full release tests before any tag is created; an existing completed version on a later main-branch push is skipped without moving or overwriting its tag.
4. After the tests pass, the workflow tags the tested client commit and creates its GitHub release. It then waits a bounded time for Packagist indexing and invokes consumer verification against that exact version and source commit on both supported PHP versions.

Future releases use the same process: bump the manifest and changelog in a reviewed PR, then merge. Merging ordinary changes with an already published manifest version does not republish it. Stable `X.Y.Z` versions and `alpha.N`, `beta.N` or `rc.N` prereleases are supported; prerelease tags produce GitHub prereleases.

Manual `workflow_dispatch` remains available on `main` for explicit release operations. Inputs default to the manifest and may be overridden; component/plugin refs are resolved to immutable commit IDs before testing. It uses the same validation, test gates, immutable-tag protection and consumer verification; it is not required for normal publication after merging a new manifest version.

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

The consumer test resolves the public package in a new Composer project without a local path or VCS repository override. It checks package metadata and autoloading, runs Composer's generated executable, and exercises the installed SDK and bridge against a local HTTPS fixture with a private test CA. Its output records the selected package version and source reference and saves the JSON report to `build/packagist/consumer.json` by default; `PACKAGIST_REPORT_PATH` overrides that destination. CI retains the report and log under `build/packagist/`. These checks need no Joomla installation or real site token. Missing requirements, an unavailable package or a protocol failure fail the test.

For stable package versions 1.0.0 or later, consumer evidence must report `legacyProtocolHeaderRequests: 0`: every post-initialization SDK request must carry its negotiated `MCP-Protocol-Version`. The fixture retains the component's missing-header compatibility for the older development package and records it explicitly; that compatibility cannot hide a missing-header regression in a stable release.

`composer test:packagist -- 1.0.0` checks the exact first stable release after indexing; `composer test:packagist -- dev-main` explicitly selects development. With no argument, the runner selects the latest compatible stable version when available, falling back to `dev-main` when no stable release is indexed. The [Packagist consumer workflow](https://github.com/joomengine/mcp_client/actions/workflows/packagist.yml) runs on PHP 8.3 and 8.4 for pull requests and main-branch pushes, supports a manual version input, and is reusable by the release workflow. CI retains the installation and fixture evidence as artifacts.

For a release, the caller supplies the exact version and expected source commit. Only Packagist's indexing delay is retried within the configured time bound. Once metadata identifies the expected release, Composer installation and protocol tests run once: download, dependency, certificate or behaviour failures fail the workflow. If indexing times out, the workflow fails visibly and preserves its evidence; it does not substitute `dev-main` or another version.

On a PR, the registry install tests the version already indexed by Packagist, not unpublished PR code. The separate PHP contract and Docker checks exercise the proposed source. Neither layer substitutes for installed Joomla acceptance.

## Tested release workflow

Before creating a tag, it runs the full PHP 8.3/8.4 contract/TLS suite, the Docker Compose build/HTTPS suites on both PHP versions, and the installed Joomla interoperability workflow using that same client commit. The installed workflow records all three actual commit IDs. Any failed job prevents tagging. Releases cannot run from pull requests, and existing tags are never moved or overwritten.

After testing, the workflow creates the tag and matching GitHub release, setting prerelease status when appropriate. Packagist's configured integration should index the tag; the subsequent consumer job verifies this externally. A failed post-publication check leaves the immutable tag and release intact for diagnosis. Retrying the original workflow at the same client commit can repeat registry verification for an already published version.

If tag creation succeeded but GitHub release creation failed, the workflow can recover the missing release only when the tag resolves to the exact tested client commit. An orphan tag at another commit, an existing draft release, or an API authentication, rate-limit or network failure stops publication instead of being treated as an absent release. Existing tags are never moved to repair a failed run.

After Packagist indexes the tag:

1. Confirm the exact version and source commit in `composer show --all joomengine/mcp-client` and on the [package page](https://packagist.org/packages/joomengine/mcp-client).
2. Confirm that the release workflow's **Packagist consumer** checks passed for the exact version and source commit on both PHP versions. The standalone manual consumer workflow remains available for later verification.
3. Confirm the standard [Packagist release badge](https://img.shields.io/packagist/v/joomengine/mcp-client) shows the indexed stable version and that the exact-version consumer report records the tag's source commit with no missing SDK protocol headers. Retain the release and consumer workflow evidence. The `^1.0` installation command supports subsequent compatible stable 1.x releases.

For local installed acceptance, supply `JOOMENGINE_MCP_URL` and `JOOMENGINE_MCP_TOKEN` and run `php tests/live.php`; a private test CA can be configured through PHP's `curl.cainfo`. Missing configuration fails. Full component/JCB write and job acceptance remains owned by the installed component's fixture, rather than a duplicated business-operation catalogue in this package.
