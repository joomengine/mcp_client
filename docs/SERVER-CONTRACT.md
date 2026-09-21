# Installed server contract and client handoff

## Endpoint and identity

The component registers `v1/joomengine-mcp`. The client derives `<HTTPS Joomla installation base>/api/index.php/v1/joomengine-mcp`, retaining installation subdirectories. It never follows redirects, guesses another host/path, or accepts a tool-provided endpoint override.

Each exchange carries the configured user's `X-Joomla-Token`. The installed server owns Joomla API login, `mcp.access`, viewing levels, asset rules, per-action/native permissions and enabled-extension restrictions. Session IDs and confirmation grants never replace authentication. This is static-token support, not OAuth.

The component's webservices routing glue handles HTTP. The separate console plugin handles direct local Joomla stdio; the external bridge does not need it for HTTP and cannot acquire its trusted local-console identity.

## Discovery and transport

`ClientFactory::connect()` returns the official PHP SDK client. The remote executable is a generic JSON-RPC proxy. Tools, resources, resource templates, prompts, schemas, pagination cursors and result data are supplied by the server; neither client path contains a Joomla/JCB catalogue.

The executable forwards initialization unchanged, stores the server-negotiated protocol revision and session, and carries them on later exchanges. It forwards bounded JSON and finite SSE responses, including notifications and structured content. Multiple HTTP requests can remain in flight, so cancellation notifications are not delayed behind an ordinary call. Two transport slots are reserved for control traffic. An exhausted request capacity returns an error before forwarding an additional ordinary request.

The installed component currently returns finite SDK Streamable HTTP responses. The bridge does not open an unrelated persistent GET event stream, reconnect automatically, replay event IDs, invent subscriptions, or implement OAuth/sampling support that the server has not advertised. A future server transport contract must be tested before enabling such behavior.

Default bounds are 30 seconds and 8 MiB, configurable through `Connection` to 1–120 seconds and 1 KiB–16 MiB. TLS verification cannot be disabled through client configuration. Redirects, implicit proxy/netrc credentials and automatic retries are disabled. HTTP errors and malformed/mismatched responses produce safe protocol errors without upstream bodies, headers or tokens in diagnostics.

## Writes and durable jobs

Consent and confirmed-write rules remain on the server. Responses may contain untrusted Joomla/JCB content and do not authorize another operation. Network failures and request cancellation cannot prove rollback.

Long-running compilation/package operations use server-defined job identifiers, status/cancel operations and artifact resources. The client discovers these like any other capability and preserves their structured data. It does not reinterpret job IDs, use unbounded HTTP timeouts or repeat a compile after losing its response. Request cancellation is forwarded as a notification; durable job cancellation must follow the server's discovered operation and be verified by reading its resulting state.

## Acceptance

Client CI verifies SDK exchange behavior, configuration isolation, JSON-RPC framing, pagination, resource/prompt forwarding, SSE events, concurrent cancellation, TLS trust/hostname rejection, redirects, byte/time limits and clean shutdown. These fixture suites do not claim to be live Joomla tests.

Installed interoperability runs build actual component/plugin packages, install them on a disposable Joomla fixture and use a trusted local HTTPS reverse proxy. `tests/live.php` tests both direct SDK HTTP and the actual remote executable, complete discovery, discovered read calls and invalid-token rejection. The component fixture owns root/subdirectory coverage, ACL changes, confirmed writes, persistence/read-back, lifecycle, and JCB compiler/job acceptance. Source revisions are recorded in CI artifacts.
