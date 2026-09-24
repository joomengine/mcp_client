# Implementation status — 24 September 2026

The standalone PHP client and remote stdio bridge from [PR #1](https://github.com/joomengine/mcp_client/pull/1) are merged into `main`. Component and console-plugin business logic remain in their own repositories. [Repository workflows](https://github.com/joomengine/mcp_client/actions) record current checks; the [component acceptance checklist](https://github.com/joomengine/mcp_component/pull/1#issuecomment-5732685349) records coordinated Joomla/JCB execution through the installed server and this generic bridge.

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
- Composer-first installation, project-local/global executable setup, complete PHP discovery example and linked Packagist/version/download badges.
- PHP 8.3/8.4 consumer workflow that downloads the indexed Packagist package into a clean project and tests the installed SDK and generated executable against trusted local HTTPS.

Evidence is emitted by each test suite and retained by CI. `tests/run.php` uses the actual PHP SDK with recording HTTP substitutes. `tests/cli.php` checks safe executable startup failures and environment/argument precedence. `tests/bridge.php` launches a real bridge process with a deterministic transport. `tests/http.sh` creates a private test CA and real HTTPS server, verifies transport limits/TLS failures and launches the public executable using only URL/token environment configuration. `tests/container.sh` builds the Docker image and exercises the delivered Compose service against that HTTPS fixture; it requires a Linux Docker engine and does not silently skip missing Docker. `tests/live.php` uses actual installed Joomla HTTP and remote stdio, discovers the server catalogue, executes discovered reads and rejects invalid credentials. Missing live test configuration fails instead of reporting a skipped pass.

An installed CI success is tied to the exact component, plugin and client revisions recorded in its artifact. Do not infer production approval, JCB write acceptance or publication from isolated client checks. The component repository owns its full write/job/JCB acceptance matrix.

Packagist registration is verified through the public [`joomengine/mcp-client` metadata](https://packagist.org/packages/joomengine/mcp-client.json). On 24 September 2026 it indexed `dev-main` from this repository at `8691e3556254e314f3a106fb933756cf502e4780`; no tagged version was present. Registration is distinct from a stable release and does not prove automatic webhook synchronization. [Release documentation](RELEASE.md) covers synchronization checks and first-release verification.

`composer test:packagist` tests the public distribution and reports its installed version and source reference; CI retains the report and log under `build/packagist/`. It uses Composer's generated binary proxy and the dependency's autoloader, with no local path override. A local HTTPS fixture supplies disposable credentials and protocol responses, so these checks need no Joomla site. On a PR this test covers the indexed package, while source contracts cover the PR commit. Package tests do not claim installed Joomla/JCB execution or external account configuration.

Verified runtime source: `1ebb989f6ae92eacfc3251c82f69f7c5ffd5118c`. PHP 8.3/8.4 contract and Docker Compose CI passed on both [push run 35983558847](https://github.com/joomengine/mcp_client/actions/runs/35983558847) and [PR run 35983565554](https://github.com/joomengine/mcp_client/actions/runs/35983565554). Each matrix includes 25 process-level bridge checks, including complete-frame/EOF byte limits for whitespace and JSON, and nine actual built-image Compose/TLS checks. The latter verify non-root/read-only runtime dependencies, required environment configuration, remote discovery, rejected credentials and orderly session deletion. The Compose connection command remains explicit when a private CA entrypoint is configured.

[Installed interoperability run 35983558934](https://github.com/joomengine/mcp_client/actions/runs/35983558934) passed on PHP 8.3 and 8.4 using that client source, component `75d9685268332241de846935aad2edc2e92c8459` and plugin `3526cae818803a02971374c044a2e2184f1c2c61`. Each installed client suite executed 14 live assertions against 24 discovered tools through the PHP SDK and external stdio executable. Source revisions and full fixture results are retained in the run's artifacts.

These historical results certify the recorded revisions and test scopes. Current workflows report checks for newer revisions, and the coordinated component acceptance checklist records the separate Joomla/JCB write/job matrix. Deliberate tagged releases follow separately; this runtime evidence does not claim a published image or stable Composer release. The public Packagist registration described above was verified independently.
