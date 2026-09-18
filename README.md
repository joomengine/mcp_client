# JoomEngine MCP Client

The standalone PHP client for an installed JoomEngine MCP server. This repository owns the external Composer client and remote stdio bridge; neither belongs in `joomengine/mcp_component` or `joomengine/mcp_plugin`.

| Identity | Value |
| --- | --- |
| Repository | `joomengine/mcp_client` |
| Composer package | `joomengine/mcp-client` |
| PHP namespace | `VDM\Joomla\Mcp\Client` |
| Planned executable | `joomengine-mcp` |

## Scope

Accept a Joomla installation base URL, including installations in a subdirectory, and connect to that site's standard MCP endpoint. Read its Joomla API token from the environment or a secure per-site configuration, never from an argument echoed into shell history. Discover tools, resources and prompts from the authenticated server; do not embed a copy of Joomla's or JCB's action catalogue. HTTP access never inherits trusted local-console authority.

Joomla Component Builder is a required first-class server integration alongside Joomla core. Compiler, package synchronization, entity API and CLI bindings remain server-owned database definitions. This client must discover them without per-component client releases.

## Repository boundaries

- `joomengine/mcp_component`: installed server, database catalogue, Joomla HTTP authentication/ACL, administrator UI, execution and HTTP routing glue.
- `joomengine/mcp_plugin`: local Joomla console adapter and direct server stdio, sharing the component engine.
- This repository: external PHP client, base-URL configuration, remote stdio bridge, client tests and independent Composer releases.

The component's outbound HTTP adapter is server infrastructure, not an external MCP client. It remains independently usable by the component and must not introduce a dependency from the server on this package.

## Delivery status

Documentation-first branch: `feature/standalone-php-client`. Runtime foundations follow on this branch. This initial commit does not advertise an available executable, Packagist release or live server compatibility. The owner explicitly permits completing the server before finalizing and certifying client interoperability. Record the exact server contract and verification gaps rather than inventing missing endpoint behaviour.

## Release objective

Use independent semantic-version tags, automated PHP CI and a tested release workflow on the default branch. Register `joomengine/mcp-client` on Packagist and connect the Packagist GitHub integration once; subsequent version tags are indexed automatically. Do not publish a stable release before the installed component/plugin acceptance matrix passes. Version tags belong to this client, not to the component's Joomla package lifecycle.
