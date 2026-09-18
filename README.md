# JoomEngine MCP Client

Standalone PHP connection library for an installed JoomEngine MCP server.

**Repository:** `joomengine/mcp_client`  
**Composer package:** `joomengine/mcp-client`  
**Namespace:** `VDM\Joomla\Mcp\Client`

## Current implementation

`Connection` accepts a Joomla installation base URL (including a subdirectory) and a separately supplied Joomla API token. `ClientFactory` connects the official PHP MCP SDK to that site's standard endpoint. The returned SDK client supports tool/resource/prompt discovery and calls without a local Joomla installation or a copied Joomla/JCB action catalogue.

The connection library and its isolated behavioural tests are implemented. Final interoperability with the installed component is pending. The planned `joomengine-mcp` remote stdio executable is **not implemented or advertised as an available binary yet**. Packagist registration/publication has not been performed. See [implementation status](docs/IMPLEMENTATION.md) and [server contract](docs/SERVER-CONTRACT.md).

## Try the development checkout

Requires PHP 8.3+, cURL and the Composer-resolved PHP dependencies. From this repository checkout:

```bash
composer install
composer test
read -r -p 'Joomla HTTPS base URL: ' JOOMENGINE_MCP_SITE
read -r -s -p 'Joomla API token: ' JOOMENGINE_MCP_TOKEN
printf '\n'
export JOOMENGINE_MCP_TOKEN
php examples/discover.php "$JOOMENGINE_MCP_SITE"
unset JOOMENGINE_MCP_TOKEN
```

Discovery requires an installed and configured MCP component endpoint; a bare Joomla installation or the console plugin alone is insufficient. The token must belong to a Joomla user allowed to use the API and MCP. The console plugin is not required for a remote HTTP client, but is required for direct local MCP serving on the Joomla server.

## PHP API

```php
use VDM\Joomla\Mcp\Client\ClientFactory;
use VDM\Joomla\Mcp\Client\Connection;

$client = (new ClientFactory())->connect(Connection::fromEnvironment($siteUrl));

try
{
    $tools = $client->listTools();
    // Select a discovered tool and validate arguments against its inputSchema.
    // Tool calls, resources and prompts use the returned Mcp\Client API.
}
finally
{
    $client->disconnect();
}
```

The executable discovery example is complete and accepts the site URL as its sole argument. Credentials remain in `JOOMENGINE_MCP_TOKEN`; do not put them into URLs or log connection headers. Default transport bounds are 30 seconds and 8 MiB, redirects and retries are disabled, and TLS verification remains enabled. Failed writes can have persisted remotely: reconcile them through the server rather than replaying them blindly.

## Repository responsibilities

`mcp_component` owns the installed server, database definitions, administration, HTTP authentication/ACL and execution. `mcp_plugin` owns the local Joomla console adapter. **This repository alone owns the external client and remote stdio bridge.** The server's independent outbound Joomla API transport is not an external MCP client and does not depend on this package.

Joomla Component Builder is a mandatory first-class server integration alongside Joomla core. Its API, compiler and package command capabilities must be discovered from the server. Adding or updating JCB database definitions must not require hard-coding JCB commands or schemas into this client.

## Release automation

[Release instructions](docs/RELEASE.md) describe the tested main-branch prerelease workflow and the one-time Packagist/GitHub setup. No version is published by this development PR. Stable release and remote stdio completion follow the installed-server acceptance matrix.
