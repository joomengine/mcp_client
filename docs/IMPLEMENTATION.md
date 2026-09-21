# Implementation status — 21 September 2026

The standalone PHP client and remote stdio bridge are implemented on `feature/standalone-php-client` / PR #1. Component and console-plugin business logic remain in their own repositories.

Implemented:

- HTTPS installation/subdirectory normalization with separately supplied tokens and bounded TLS transport.
- Official SDK connection factory for tools, resources, templates and prompts.
- `joomengine-mcp` executable with ephemeral connections and persistent named sites.
- Private owner-checked storage, symlink/hardlink rejection, bounded locks and atomic credential updates.
- Generic concurrent stdio-to-HTTP forwarding, exact JSON-RPC IDs, initialization/session metadata, pagination, structured job data, finite SSE notifications, cancellation forwarding and orderly disconnect.
- Safe error framing, bounded input/output/concurrency, stdout isolation and no automatic retries.
- PHP 8.3/8.4 SDK/configuration/process/TLS CI and installed component interoperability CI.
- Manually triggered main-only stable/prerelease workflow gated on both test layers; tags are never overwritten.

Evidence is emitted by each test suite and retained by CI. `tests/run.php` uses the actual PHP SDK with recording HTTP substitutes. `tests/bridge.php` launches a real bridge process with a deterministic transport. `tests/http.sh` creates a private test CA and real HTTPS server, verifies transport limits/TLS failures and launches the public executable. `tests/live.php` uses actual installed Joomla HTTP and remote stdio, discovers the server catalogue, executes discovered reads and rejects invalid credentials. Missing live test configuration fails instead of reporting a skipped pass.

An installed CI success is tied to the exact component, plugin and client revisions recorded in its artifact. Do not infer production approval, JCB write acceptance or publication from isolated client checks. The component repository owns its full write/job/JCB acceptance matrix.

Packagist registration is a one-time distribution setup after the reviewed package metadata reaches main. No repository code can truthfully assert that an external Packagist account has registered this package. No merge, release or publication is performed by the implementation PR.
