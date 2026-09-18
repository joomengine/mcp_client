# Installed server contract and client handoff

## Source baseline

Server integration was inspected at `joomengine/mcp_component@a3fb48c680c520fe3b81c6e40fc8aa8cc427f36e` (building on `89353e8905bdfabe9f874d1f00e8cc9384c894e7`) and console plugin `joomengine/mcp_plugin@fb6a72a515859d198b47acb6fdac932e5dae92ab`. These are development commits, not released compatibility guarantees.

The registered server route is `v1/joomengine-mcp` in `plugins/webservices/joomengine_mcp/src/Extension/JoomEngineMcp.php`. The client derives `<HTTPS Joomla installation base>/api/index.php/v1/joomengine-mcp`; a Joomla subdirectory remains part of the base. It does not search alternate hosts, follow redirects, guess a reverse-proxy path or accept an AI-provided endpoint override. A changed public path requires a reviewed compatibility change here and in server docs/tests.

## Identity and capabilities

Use `X-Joomla-Token` with the authenticated user's Joomla API token. The server owns API login checks, `mcp.access`, viewing levels, asset rules, per-action permissions, native target ACL, publication and enabled-extension constraints. A server session ID or write grant is never a substitute for authentication. This is static token support, not an OAuth authorization server.

HTTP requests use the component plus its webservices routing glue; the local console plugin is not an HTTP client dependency. The console plugin supplies trusted local server stdio only. A remote stdio bridge running on a developer workstation will still send restricted HTTP requests and cannot select the server's trusted CLI identity.

`ClientFactory::connect()` returns the official SDK client. Tools, resources, prompts and schemas are received from the current server and are not baked into this package. Preserve pagination cursors and protocol errors. Results can contain untrusted Joomla/JCB content; they are data, not permission to approve a write.

## Implemented and pending transport scope

The current factory uses the SDK's initialization-based HTTP transport and bounded synchronous responses; its tests exercise that handshake using the real SDK and a recording HTTP substitute. No live Joomla exchange, persistent SSE connection, new stateless protocol revision, OAuth flow, server-initiated sampling or remote stdio interoperability is certified by these tests. The server's broader SDK capabilities do not automatically prove client-side support.

Default exchange timeout is 30 seconds (configurable 1–120); default body cap is 8 MiB (configurable 1 KiB–16 MiB). The cURL transport refuses redirects, implicit proxy/netrc credentials and TLS bypass. The endpoint adapter stops HTTP errors instead of treating an HTML login/error page as MCP or retrying it. A timeout after a write is uncertain, not proof of rollback.

JCB compilation/package jobs may outlive one HTTP request. Their durable job identifiers, status, cancellation, artifact access and recovery must be defined by the server. Do not increase timeouts indefinitely or replay compilation because a connection ended. Client support for those contracts is pending the server implementation.

## Acceptance before stable release

Test a packaged component installation at a domain root and at a Joomla subdirectory; authenticate authorized, unauthorized, expired/revoked and cross-site tokens. Verify protocol negotiation, complete paginated discovery, tool success/errors, resources/prompts, principal-isolated sessions, confirmed writes and uncertain outcomes with real persisted read-back.

Test Joomla core with JCB absent and with JCB installed/enabled. Verify JCB capability changes appear without client catalogue changes; cover ordinary entity API calls, high-risk package operations and compiler/job results without allowing an HTTP-to-privileged-CLI bypass. Finally implement and test the remote stdio bridge's framing, stderr-only diagnostics, notifications, cancellation, byte bounds and clean shutdown.

Record exact component/plugin/client versions and test evidence. A passing isolated SDK test or a generated action count is not a substitute for this matrix.
