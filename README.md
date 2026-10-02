# JoomEngine MCP Client

[![Latest stable Packagist version](https://img.shields.io/packagist/v/joomengine/mcp-client)](https://packagist.org/packages/joomengine/mcp-client)
[![Total downloads](https://img.shields.io/packagist/dt/joomengine/mcp-client)](https://packagist.org/packages/joomengine/mcp-client/stats)
[![PHP requirement](https://img.shields.io/badge/PHP-%5E8.3-777BB4)](composer.json)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue)](LICENSE)
[![PHP client contracts](https://github.com/joomengine/mcp_client/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/joomengine/mcp_client/actions/workflows/ci.yml)
[![Packagist consumer](https://github.com/joomengine/mcp_client/actions/workflows/packagist.yml/badge.svg?branch=main)](https://github.com/joomengine/mcp_client/actions/workflows/packagist.yml)

Standalone PHP client and remote stdio bridge for an installed JoomEngine MCP server.

**Start with the Joomla package:** install the [latest MCP package](https://github.com/joomengine/mcp_package/tags) on your existing Joomla site. It bundles the [MCP component](https://github.com/joomengine/mcp_component), [console plugin](https://github.com/joomengine/mcp_plugin) and [webservices plugin](https://github.com/joomengine/mcp_webservices). This client runs separately on your computer or in your PHP application and connects to that installed server.

**[Joomla installation and setup](https://github.com/joomengine/mcp_component/blob/main/docs/GETTING-STARTED.md)** · **[AI connections and direct tool use](https://github.com/joomengine/mcp_component/blob/main/docs/CLIENT-CONNECTIONS.md)** · **[Component documentation](https://github.com/joomengine/mcp_component/tree/main/docs)**

The component and its plugins are the actively maintained fourth build of JoomEngine MCP, built for Joomla and Joomla Component Builder (JCB). They succeed the original [TypeScript proof of concept](https://github.com/joomengine/joomla-mcp). The client discovers the capabilities available on your authenticated site; JCB operations require JCB to be installed there. It provides a PHP SDK and a stdio bridge, rather than a browser dashboard or an HTTP listener.

**[Packagist package](https://packagist.org/packages/joomengine/mcp-client)** · **[Releases](https://github.com/joomengine/mcp_client/releases)** · **[Changelog](CHANGELOG.md)** · **[Issues](https://github.com/joomengine/mcp_client/issues)**

| Interface | Name |
| --- | --- |
| Composer package | `joomengine/mcp-client` |
| PHP namespace | `VDM\Joomla\Mcp\Client` |
| Executable | `joomengine-mcp` |

Requires PHP 8.3+, cURL, JSON and [Composer 2](https://getcomposer.org/download/). Persistent named-site configuration also requires POSIX ownership support (`ext-posix`). `ext-pcntl` enables orderly signal handling. No local Joomla installation is needed. The target HTTPS site must have the MCP package installed, its webservices plugin enabled and MCP access configured; use that site's Joomla API token for a user with the necessary permissions. Follow the [site setup guide](https://github.com/joomengine/mcp_component/blob/main/docs/GETTING-STARTED.md) before configuring this client. The console plugin bundled in the package is only used for direct local Joomla console serving.

## Install from Packagist

The stable **1.x** series starts at [**1.0.0**](https://github.com/joomengine/mcp_client/releases/tag/v1.0.0). Install it in your PHP project or an empty working directory:

```bash
composer require 'joomengine/mcp-client:^1.0'
composer check-platform-reqs
./vendor/bin/joomengine-mcp help
```

Packagist is Composer's default repository, so no custom repository configuration or Git checkout is required. `^1.0` accepts compatible stable 1.x updates while excluding 2.0. Keep your application's `composer.lock` to reproduce the resolved version and dependencies. `composer update joomengine/mcp-client --with-dependencies` updates it deliberately. The live version badge and [package page](https://packagist.org/packages/joomengine/mcp-client) show the indexed stable release.

### Development version

When intentionally testing unreleased development, use:

```bash
composer require joomengine/mcp-client:dev-main
```

The explicit constraint allows this package's development version without lowering your project's overall `minimum-stability`. Development installs follow `main` when updated. Existing development users can run `composer require 'joomengine/mcp-client:^1.0' --with-dependencies` to move to the stable series. [Release instructions](docs/RELEASE.md) explain publication and distribution checks.

### Version compatibility

The client, installed component and console plugin have independent version numbers. Client 1.0.0 does not require component or plugin 1.0.0. The selected first-release interoperability targets are:

| Part | Version / requirement | Release-test source |
| --- | --- | --- |
| MCP client | `1.0.0` baseline; PHP 8.3+ | Client commit tested by the release workflow |
| Installed MCP component | `0.1.1` | [`14c715c`](https://github.com/joomengine/mcp_component/commit/14c715c50c2cc29fd3c8cc3c4780442efb507398) |
| Console plugin | `0.1.0`; needed for local console serving | [`9935228`](https://github.com/joomengine/mcp_plugin/commit/993522852770e2f8968ab066deedef00f174d8c7) |
| Joomla site | Joomla 6.1+ | Packaged Joomla installation in the interoperability fixture |

The reviewed [release manifest](release.json) declares the version and exact component/plugin revisions. After its pull request is merged into `main`, the release workflow tests that client commit and publishes a new version only when all release checks pass. It then verifies the exact version and source commit installed from Packagist on PHP 8.3 and 8.4. The manifest declares release intent; the live badge and package listing show actual publication. [Implementation evidence](docs/IMPLEMENTATION.md) records test scope, and [release instructions](docs/RELEASE.md) explain subsequent version updates.

### Connect an AI application

Follow [AI connections and direct tool use](https://github.com/joomengine/mcp_component/blob/main/docs/CLIENT-CONNECTIONS.md) for complete PHP and Docker launcher configuration, Claude examples, ChatGPT compatibility requirements and a direct SDK example that calls tools without an AI. Local applications that launch stdio MCP processes can use this executable; remote-only chat connectors need a compatible reachable HTTP endpoint and authentication. `joomengine-mcp serve` exposes stdio, so it is not a URL to paste into a web connector.


For a direct stdio connection, supply the site's HTTPS base URL and token separately. This Bash example prompts for both without putting the token in command history:

```bash
read -r -p 'Joomla HTTPS base URL: ' JOOMENGINE_MCP_URL
read -r -s -p 'Joomla API token: ' JOOMENGINE_MCP_TOKEN
printf '\n'
export JOOMENGINE_MCP_URL JOOMENGINE_MCP_TOKEN
./vendor/bin/joomengine-mcp connect
```

The final command waits for MCP JSON-RPC on stdin and writes protocol replies to stdout. Configure your AI application's MCP launcher as follows:

| Setting | Value |
| --- | --- |
| Transport | `stdio` |
| Command | The absolute path to `vendor/bin/joomengine-mcp` in your project |
| Arguments | `connect` |
| Environment | `JOOMENGINE_MCP_URL` and `JOOMENGINE_MCP_TOKEN`, supplied through the application's environment or secret settings |

Run `realpath vendor/bin/joomengine-mcp` on Linux to obtain the launcher command. On Windows, use Composer's generated `vendor/bin/joomengine-mcp.bat` launcher. The client discovers tools, resources and prompts from the authenticated server; the AI application does not need a copied Joomla/JCB catalogue. An explicit URL argument to `connect` overrides `JOOMENGINE_MCP_URL`.

To install the stable executable globally instead of in a project:

```bash
composer global require 'joomengine/mcp-client:^1.0'
composer global config bin-dir --absolute
```

For intentional development testing, use `composer global require joomengine/mcp-client:dev-main`. Add the directory printed by the second command to your `PATH`, or give the AI launcher the absolute executable path in that directory. Then `joomengine-mcp connect` uses the same URL/token environment variables. Project-local installations use `./vendor/bin/joomengine-mcp`; a global executable is only available as a bare command when its directory is on `PATH`.

### Save a named site

On POSIX systems with `ext-posix`, the installed executable can save credentials in a private configuration file:

```bash
read -r -p 'Joomla HTTPS base URL: ' JOOMENGINE_MCP_SITE
read -r -s -p 'Joomla API token: ' JOOMENGINE_MCP_TOKEN
printf '\n'
export JOOMENGINE_MCP_TOKEN
./vendor/bin/joomengine-mcp configure production "$JOOMENGINE_MCP_SITE"
unset JOOMENGINE_MCP_TOKEN
./vendor/bin/joomengine-mcp serve production
```

For an AI launcher, use the same absolute executable path with separate arguments `serve` and `production`. `sites` lists saved names and URLs; `remove NAME` removes a saved site. Configuration messages and diagnostics go to stderr. Tokens are never accepted as command-line arguments.

By default named sites are stored beneath `$XDG_CONFIG_HOME/joomengine-mcp`, or `$HOME/.config/joomengine-mcp` when XDG is unset. `JOOMENGINE_MCP_CONFIG_DIR` selects an explicit private directory. Directories must be mode 0700 and credential files mode 0600; unsafe owners, links and shared writable paths are rejected. Writes are locked and atomic. The file is plaintext protected by filesystem permissions, not encrypted storage.

## Run with Docker Compose

Docker Engine and Compose v2 provide the complete PHP runtime. Clone this repository using the commands in [Run from a development checkout](#run-from-a-development-checkout), then build once from that directory and enter the installed site's HTTPS base URL and its Joomla API token:

```bash
docker compose build --pull mcp
read -r -p 'Joomla HTTPS base URL: ' JOOMENGINE_MCP_URL
read -r -s -p 'Joomla API token: ' JOOMENGINE_MCP_TOKEN
printf '\n'
export JOOMENGINE_MCP_URL JOOMENGINE_MCP_TOKEN
docker compose run --rm --no-deps -T mcp
```

The last command is a stdio MCP connection for an AI application's MCP launcher. Configure that application to execute `docker`, passing `compose`, `--file`, the absolute path to this checkout's `compose.yaml`, `run`, `--rm`, `--no-deps`, `-T`, and `mcp` as separate arguments. Supply `JOOMENGINE_MCP_URL` and `JOOMENGINE_MCP_TOKEN` through the application's environment or secret settings. The connection retains the Joomla user's HTTP permissions and discovers the installed capabilities automatically.

The container runs without root privileges, with a read-only filesystem, no published ports and no mounted Joomla directory. It contacts the component over HTTPS; it does not require the console plugin. See [Docker operation and private CA configuration](docs/DOCKER.md).

## Run from a development checkout

```bash
git clone --branch v1.0.0 --depth 1 https://github.com/joomengine/mcp_client.git
cd mcp_client
composer install
composer test
php bin/joomengine-mcp help
```

The commands above check out the released 1.0.0 source. Omit `--branch v1.0.0 --depth 1` when deliberately contributing to current development. In a checkout, use `php bin/joomengine-mcp` with the same connection/configuration commands described above. With the URL/token environment variables set, `php examples/discover.php "$JOOMENGINE_MCP_URL"` runs the PHP SDK discovery example.

## Use tools directly without an AI

The [direct SDK walkthrough](https://github.com/joomengine/mcp_component/blob/main/docs/CLIENT-CONNECTIONS.md#use-tools-directly-without-an-ai) provides a complete script to list tools, inspect their input schemas and call a selected tool using a configured site. The same Joomla ACL, confirmation grants and job rules apply. The executable itself does not provide a separate `tools call` command.

## PHP API

After installing the package with Composer, save this as `discover.php` in your project's root and run `php discover.php` with `JOOMENGINE_MCP_URL` and `JOOMENGINE_MCP_TOKEN` set as above:

```php
<?php

use VDM\Joomla\Mcp\Client\ClientFactory;
use VDM\Joomla\Mcp\Client\Connection;

require __DIR__ . '/vendor/autoload.php';

$siteUrl = getenv('JOOMENGINE_MCP_URL');

if (!is_string($siteUrl) || $siteUrl === '')
{
	fwrite(STDERR, "Set JOOMENGINE_MCP_URL and JOOMENGINE_MCP_TOKEN before running discovery.\n");
	exit(2);
}

$client = null;
$status = 0;

try
{
	$client = (new ClientFactory())->connect(Connection::fromEnvironment($siteUrl));
	$cursor = null;
	$seen = [];

	do
	{
		$result = $client->listTools($cursor);

		foreach ($result->tools as $tool)
		{
			echo $tool->name . PHP_EOL;
		}

		$cursor = $result->nextCursor;

		if ($cursor !== null)
		{
			if (isset($seen[$cursor]) || count($seen) >= 100)
			{
				throw new RuntimeException('Discovery pagination exceeded its bound.');
			}

			$seen[$cursor] = true;
		}
	}
	while ($cursor !== null);
}
catch (Throwable)
{
	fwrite(STDERR, "MCP discovery failed. Check the URL, token, component installation and Joomla permissions.\n");
	$status = 1;
}
finally
{
	if ($client !== null)
	{
		$client->disconnect();
	}
}

exit($status);
```

The returned official SDK client supports tools, resources, resource templates and prompts. Preserve `nextCursor` when listing multiple pages and use each discovered input schema when constructing arguments. `Configuration\SiteStore::connection($name)` loads the same `Connection` used by the executable.

The base URL may contain a Joomla subdirectory. The component endpoint is derived as `/api/index.php/v1/joomengine-mcp`; credentials, query strings, ambiguous paths and redirects are refused. TLS verification stays enabled. Default transport bounds are 30 seconds and 8 MiB, with no retries. A failed write may have persisted remotely: reconcile it with the server before submitting anything again.

## Protocol and jobs

The stdio bridge forwards the original initialization, request IDs, negotiated protocol revision, pagination, results and notifications. It supports bounded JSON and finite SSE responses from the component, concurrent cancellation notifications, and session deletion on orderly shutdown. It does not run a persistent GET event stream or manufacture server capabilities. Output backpressure and request concurrency are bounded.

Joomla and JCB operations, confirmation grants, durable jobs, cancellation and artifacts are defined by the installed server and discovered as normal tools/resources. The bridge preserves their structured data without shipping business logic or an action allowlist. Closing a connection or sending a request cancellation notification does not itself prove a job was cancelled; inspect the server's job state.

## Repositories and verification

`mcp_component` owns the installed server, database definitions, authentication, ACL and execution. `mcp_plugin` owns direct local Joomla console serving. This repository owns the external client and remote bridge, which always retain HTTP authority.

`composer test` runs SDK contracts, secure configuration checks, CLI/process framing checks and a real HTTPS fixture. Tests require Node.js and OpenSSL in addition to PHP. `bash tests/container.sh` builds the actual Docker image and tests Compose against the TLS fixture on a Linux Docker engine. Both packaging and PHP contracts run on PHP 8.3/8.4 in CI. The installed interoperability workflow builds and installs the actual component/plugin and tests both this library and this executable over trusted HTTPS. [Implementation evidence](docs/IMPLEMENTATION.md) distinguishes these layers.

`composer test:packagist` installs the publicly indexed package into a clean Composer project, exercises Composer's generated executable and uses the installed PHP API against a local trusted HTTPS fixture. It verifies distribution, autoloading and protocol behaviour without a Joomla site or real credentials. Use `composer test:packagist -- dev-main` to select the development version explicitly. This consumer test runs independently of checkout tests in the [Packagist consumer workflow](https://github.com/joomengine/mcp_client/actions/workflows/packagist.yml); its logs record the version and source reference actually installed. On a pull request it tests the existing Packagist package, while `composer test` tests the proposed source. It does not certify installed Joomla business operations.

See [the server contract](docs/SERVER-CONTRACT.md), [release instructions](docs/RELEASE.md) and the [Packagist listing](https://packagist.org/packages/joomengine/mcp-client).

