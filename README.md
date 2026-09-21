# JoomEngine MCP Client

Standalone PHP client and remote stdio bridge for an installed JoomEngine MCP server.

**Composer package:** `joomengine/mcp-client`
**Namespace:** `VDM\Joomla\Mcp\Client`
**Executable:** `joomengine-mcp`

Requires PHP 8.3+, cURL and JSON. Persistent named-site configuration also requires POSIX ownership support (`ext-posix`). `ext-pcntl` enables orderly signal handling. No local Joomla installation, component classes or copied Joomla/JCB catalogue are needed.

## Run from a development checkout

```bash
composer install
composer test
read -r -p 'Joomla HTTPS base URL: ' JOOMENGINE_MCP_SITE
read -r -s -p 'Joomla API token: ' JOOMENGINE_MCP_TOKEN
printf '\n'
export JOOMENGINE_MCP_TOKEN
php bin/joomengine-mcp configure production "$JOOMENGINE_MCP_SITE"
unset JOOMENGINE_MCP_TOKEN
php bin/joomengine-mcp serve production
```

The final command speaks newline-delimited MCP JSON-RPC on stdin/stdout; launch it from your MCP application's stdio configuration. For a Composer-installed executable, use `joomengine-mcp serve production`. The `configure` command saves the site URL and separately supplied token in an owner-only configuration file; it never accepts a token in command-line arguments. `sites` lists names and URLs, and `remove NAME` removes one saved site. Configuration messages and all diagnostics go to stderr.

By default named sites are stored beneath `$XDG_CONFIG_HOME/joomengine-mcp`, or `$HOME/.config/joomengine-mcp` when XDG is unset. `JOOMENGINE_MCP_CONFIG_DIR` selects an explicit private directory. Directories must be mode 0700 and credential files mode 0600; unsafe owners, links and shared writable paths are rejected. Writes are locked and atomic. The file is plaintext protected by filesystem permissions, not encrypted storage.

For an ephemeral connection use `joomengine-mcp connect HTTPS_BASE_URL` with `JOOMENGINE_MCP_TOKEN` in the environment. `php examples/discover.php HTTPS_BASE_URL` runs the PHP SDK discovery example.

## PHP API

```php
use VDM\Joomla\Mcp\Client\ClientFactory;
use VDM\Joomla\Mcp\Client\Connection;

$client = (new ClientFactory())->connect(Connection::fromEnvironment($siteUrl));

try
{
    $tools = $client->listTools();
}
finally
{
    $client->disconnect();
}
```

The returned official SDK client supports tools, resources, resource templates and prompts. Preserve `nextCursor` when listing multiple pages and use each discovered input schema when constructing arguments. `Configuration\SiteStore::connection($name)` loads the same `Connection` used by the executable.

The base URL may contain a Joomla subdirectory. The component endpoint is derived as `/api/index.php/v1/joomengine-mcp`; credentials, query strings, ambiguous paths and redirects are refused. TLS verification stays enabled. Default transport bounds are 30 seconds and 8 MiB, with no retries. A failed write may have persisted remotely: reconcile it with the server before submitting anything again.

## Protocol and jobs

The stdio bridge forwards the original initialization, request IDs, negotiated protocol revision, pagination, results and notifications. It supports bounded JSON and finite SSE responses from the component, concurrent cancellation notifications, and session deletion on orderly shutdown. It does not run a persistent GET event stream or manufacture server capabilities. Output backpressure and request concurrency are bounded.

Joomla and JCB operations, confirmation grants, durable jobs, cancellation and artifacts are defined by the installed server and discovered as normal tools/resources. The bridge preserves their structured data without shipping business logic or an action allowlist. Closing a connection or sending a request cancellation notification does not itself prove a job was cancelled; inspect the server's job state.

## Repositories and verification

`mcp_component` owns the installed server, database definitions, authentication, ACL and execution. `mcp_plugin` owns direct local Joomla console serving. This repository owns the external client and remote bridge, which always retain HTTP authority.

`composer test` runs SDK contracts, secure configuration checks, process-level framing checks and a real HTTPS fixture. Tests require Node.js and OpenSSL in addition to PHP. The installed interoperability workflow builds and installs the actual component/plugin and tests both this library and this executable over trusted HTTPS. [Implementation evidence](docs/IMPLEMENTATION.md) distinguishes these layers.

See [the server contract](docs/SERVER-CONTRACT.md) and [release instructions](docs/RELEASE.md). This development branch does not itself publish a Composer package or register it on Packagist.
