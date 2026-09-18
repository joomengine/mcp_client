# Agent contract — standalone MCP client

Own only the external PHP MCP connection client, its remote stdio bridge, examples, tests and independent Composer releases. Package `joomengine/mcp-client`; namespace `VDM\Joomla\Mcp\Client`; intended executable `joomengine-mcp`. Keep implementation on `feature/standalone-php-client` / PR #1 unless the owner changes that instruction. Do not merge or publish releases without approval.

The installed server and all Joomla/JCB business rules remain in `joomengine/mcp_component`; trusted local Joomla console serving remains in `joomengine/mcp_plugin`. Neither server repository may depend on this client. Do not copy server catalogues, local administrator classes, SQL, JCB compiler/package implementations or tool allowlists here. Learn all exposed capabilities through the authenticated MCP protocol.

Use PHP 8.3+ and the specified JCB style: tabs, LF, Allman braces, explicit typed dependency properties and constructor assignment, meaningful documentation, no closing PHP tag, no isolated strict_types, property promotion or readonly declarations. Authority: https://github.com/extension-builder/joomla/blob/main/docs/development/php-code-style.md. Preserve native and upstream public signatures. Third-party vendor code retains upstream style/licences.

Accept the installation base URL including subdirectories; obtain the token separately. Reject credentials in URLs, ambiguous path traversal, untrusted redirects and credential forwarding outside the canonical endpoint. The remote stdio bridge remains HTTP-ACL-restricted; it must never enable local-console privilege or manufacture operator consent. Do not retry ambiguous writes, log secrets, disable TLS verification or serialize tokens.

The owner explicitly permits finishing the component before final client interoperability. Do not invent unknown server routes or pretend unit/SDK tests are live Joomla evidence. Keep docs/SERVER-CONTRACT.md and docs/IMPLEMENTATION.md current. Complete remote stdio framing, pagination, resource/prompt forwarding, cancellation, error and session handling only against verified server behaviour. Advertise no absent binary or unsupported protocol feature.

Full JCB integration is a required server objective, not an optional follow-up: every actual JCB API operation and registered CLI command must be reachable via appropriate reviewed server bindings. The client must remain generic as those definitions are added. JCB package get/init/pull/push/reset are not ordinary read-only entity lookups; grants and job semantics come from the server.

Run composer validate, PHP syntax and composer test on PHP 8.3 and 8.4. Add TLS/HTTP failure, byte/time bounds, protocol revision, cross-site token, stdio and real installed-server tests before a stable release. Retain original authorship/licences in derived HTTP code. Packagist availability must be verified rather than inferred from composer.json.
