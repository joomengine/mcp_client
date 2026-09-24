# Changelog

## Unreleased — Packagist distribution

- Link the registered `joomengine/mcp-client` package, live development-version/download badges, release history and CI from the README.
- Add Composer-first local and global installation, AI stdio launcher setup and a complete PHP SDK discovery example.
- Document indexed `dev-main` availability, semantic-version releases and Packagist synchronization without claiming an unissued stable release.
- Add package discovery/support metadata and PHP 8.3/8.4 consumer checks that download the actual Packagist distribution into a clean project and exercise its generated executable and SDK against local trusted HTTPS.
- Use the component and plugin `main` branches for installed interoperability checks after their original feature branches were merged and deleted.
- Send the SDK's negotiated `MCP-Protocol-Version` on subsequent HTTP requests, notifications and session deletion, including when the server selects a different supported protocol revision.
- Report missing SDK protocol headers in consumer evidence for the existing published development version while retaining the component's backward-compatible handling; require the stdio bridge's negotiated header and reject incorrect revisions.

## Unreleased

- Establish independent ownership of the external PHP MCP client and remote stdio bridge.
- Add HTTPS installation URL/token configuration, endpoint-bound official SDK connection and bounded transport derived from the component infrastructure.
- Add dynamic tool/resource/prompt discovery and call contract tests, developer discovery example, PHP 8.3/8.4 CI and tested prerelease automation.
- Preserve the server-first acceptance dependency, full JCB discovery objective and transport provenance. No stable release, available stdio executable or live Joomla/JCB certification is claimed.

## Unreleased — remote connection completion

- Add the generic remote `joomengine-mcp` stdio executable and private named-site configuration.
- Preserve HTTP identity, protocol/session metadata, pagination, finite SSE events, cancellation notifications and structured job results.
- Add concurrent bounded HTTPS transport with TLS failure and process-level acceptance coverage.
- Gate stable/prerelease automation on client contracts and packaged Joomla interoperability.

## Unreleased — Docker Compose client

- Add a complete non-root Docker image and read-only Compose service for connecting an AI application's stdio MCP transport using a Joomla HTTPS URL and API token.
- Allow `connect` to obtain its URL from `JOOMENGINE_MCP_URL` while preserving explicit URL arguments and credential isolation.
- Gate client releases on real Compose/TLS packaging checks for PHP 8.3 and 8.4, alongside existing PHP and installed Joomla acceptance.
- Document AI launcher setup, private CA bundles, Docker operation and the distinction between remote HTTP authority and trusted local-console serving.
