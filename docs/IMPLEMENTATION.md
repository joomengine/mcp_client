# Implementation status — 18 September 2026

## Established

Documentation-first branch `feature/standalone-php-client`, draft PR #1. All external Composer-client and remote-bridge ownership is now assigned here, not to the component. The server's outbound transport is independent and scoped to its own Administrator namespace.

## Implemented

- Composer metadata and standalone PSR-4 namespace without Joomla/component/plugin dependencies.
- HTTPS base-URL/subdirectory normalization and separately supplied API token, redacted object diagnostics and serialization refusal.
- Bounded cURL transport derived from the component infrastructure, with TLS verification, redirect refusal and no retries.
- Endpoint-bound SDK factory and HTTP response guard, initialized discovery/calls/resources/prompts and explicit disconnect.
- Complete developer discovery example, PHP 8.3/8.4 CI, and an operator-triggered main-only tested prerelease workflow.

## Evidence

`php tests/run.php` passed 70 checks locally on PHP 8.4 with the official SDK 0.8.1 dependency tree. These are recording-transport/SDK tests, not live Joomla integration. CI independently runs the same suite with Composer-installed dependencies on PHP 8.3 and 8.4; inspect the PR checks for the tested head rather than assuming a queued run passed.

## Deliberately pending the installed server

The remote `joomengine-mcp` stdio executable, secure persistent per-site credential configuration, wider protocol/streaming interoperability and real Joomla/JCB end-to-end acceptance are not finished. The owner explicitly permits this server-first sequence. No bin entry is published for an absent executable. The connection library is available in this development branch, but is not certified production-ready.

Packagist registration/integration and any actual release are not performed by this PR. Stable releases remain unavailable in the prerelease workflow until the installed-server acceptance contract is complete. See SERVER-CONTRACT.md and RELEASE.md.
