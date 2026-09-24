# Implementation status — 24 September 2026

The standalone PHP client and remote stdio bridge are implemented on `feature/standalone-php-client` / [PR #1](https://github.com/joomengine/mcp_client/pull/1). Component and console-plugin business logic remain in their own repositories. The PR records current check results and review status; the [component acceptance checklist](https://github.com/joomengine/mcp_component/pull/1#issuecomment-5732685349) records coordinated Joomla/JCB execution through the installed server and this generic bridge.

Implemented:

- HTTPS installation/subdirectory normalization with separately supplied tokens and bounded TLS transport.
- Official SDK connection factory for tools, resources, templates and prompts.
- `joomengine-mcp` executable with ephemeral connections and persistent named sites.
- Dockerfile and Compose v2 service with a complete locked PHP runtime, non-root/read-only operation and environment-only URL/token configuration.
- Private owner-checked storage, symlink/hardlink rejection, bounded locks and atomic credential updates.
- Generic concurrent stdio-to-HTTP forwarding, exact JSON-RPC IDs, initialization/session metadata, pagination, structured job data, finite SSE notifications, cancellation forwarding and orderly disconnect.
- Safe error framing, bounded input/output/concurrency, stdout isolation and no automatic retries.
- PHP 8.3/8.4 SDK/configuration/process/TLS CI and installed component interoperability CI.
- PHP 8.3/8.4 image builds and real Compose-to-HTTPS protocol checks, including clean EOF/session deletion and failed authentication.
- Manually triggered main-only stable/prerelease workflow gated on both test layers; tags are never overwritten.

Evidence is emitted by each test suite and retained by CI. `tests/run.php` uses the actual PHP SDK with recording HTTP substitutes. `tests/cli.php` checks safe executable startup failures and environment/argument precedence. `tests/bridge.php` launches a real bridge process with a deterministic transport. `tests/http.sh` creates a private test CA and real HTTPS server, verifies transport limits/TLS failures and launches the public executable using only URL/token environment configuration. `tests/container.sh` builds the Docker image and exercises the delivered Compose service against that HTTPS fixture; it requires a Linux Docker engine and does not silently skip missing Docker. `tests/live.php` uses actual installed Joomla HTTP and remote stdio, discovers the server catalogue, executes discovered reads and rejects invalid credentials. Missing live test configuration fails instead of reporting a skipped pass.

An installed CI success is tied to the exact component, plugin and client revisions recorded in its artifact. Do not infer production approval, JCB write acceptance or publication from isolated client checks. The component repository owns its full write/job/JCB acceptance matrix.

Packagist registration is a one-time distribution setup after the reviewed package metadata reaches main. No repository code can truthfully assert that an external Packagist account has registered this package. No merge, release or publication is performed by the implementation PR.

Historical passing baseline, client source `2abea4bf02cd8e7ab6b15270309c69e9f5a54aa5`: PHP 8.3/8.4 contract CI [35614914110](https://github.com/joomengine/mcp_client/actions/runs/35614914110) and installed interoperability [35614914117](https://github.com/joomengine/mcp_client/actions/runs/35614914117) passed. Each installed run executed 14 live client assertions against 24 discovered tools using component `080e189aec094b5c1f883926498623ef1f16099d` and plugin `873c46742d1ee95d661779e73649fcedaf069ff4`.

Current completion follows the linked PR and coordinated acceptance checklist. Those records tie results to exact component/plugin/client revisions; historical evidence does not certify later runtime changes. Review/merge and deliberate release publication follow separately.
