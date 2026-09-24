# Changelog

## 1.0.0

Changes for the first stable client release. Publication is recorded by the [`v1.0.0` GitHub release](https://github.com/joomengine/mcp_client/releases/tag/v1.0.0) and [Packagist version history](https://packagist.org/packages/joomengine/mcp-client); this entry alone does not establish availability. Client versions are independent of component/plugin versions; the selected interoperability targets are component 0.1.1 and console plugin 0.1.0.

### Added

- Standalone external PHP MCP client and remote stdio bridge, with generic tool/resource/template/prompt discovery through the official SDK.
- HTTPS installation URL/token configuration, subdirectory support, bounded transport and endpoint-bound credentials.
- `joomengine-mcp` executable with ephemeral connections and private named-site configuration.
- Concurrent remote forwarding of HTTP identity, JSON-RPC/session metadata, pagination, finite SSE events, cancellation notifications and structured job results.
- Complete non-root Docker image and read-only Compose service, using a site URL and API token through environment variables.
- PHP 8.3/8.4 source contracts, TLS and process tests, real Compose checks and packaged Joomla interoperability acceptance.
- Main-only stable/prerelease automation gated on client, Docker and installed Joomla checks, with immutable first-release component/plugin inputs documented.
- Packagist metadata, stable-version/download badges, release links, Composer-first local/global installation, AI launcher setup and a complete PHP discovery example.
- Stable `^1.0` installation instructions with an explicit `dev-main` fallback before publication and for development testing.
- PHP 8.3/8.4 consumer checks that download the actual Packagist distribution into a clean project and exercise its generated executable and SDK against trusted local HTTPS.

### Fixed

- Use component/plugin `main` for installed interoperability after the original feature branches were merged and deleted.
- Send the SDK's negotiated `MCP-Protocol-Version` on subsequent HTTP requests, notifications and session deletion, including when the server selects a different supported revision.

### Distribution verification

- Consumer evidence identifies the downloaded version and commit and records SDK requests missing their negotiated protocol header. This compatibility diagnostic supports the older development package; stable versions from 1.0.0 must report zero missing SDK protocol headers.
- The stdio bridge must send the negotiated protocol header, and the fixture rejects incorrect supplied revisions for both SDK and stdio clients.
- Packagist synchronization and exact-version consumer verification are documented separately from source tests and installed Joomla acceptance.
