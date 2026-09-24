# Docker Compose client

Build from the reviewed checkout with `docker compose build --pull mcp`. The image includes the locked Composer dependencies and PHP runtime, including cURL, JSON, fileinfo, POSIX and signal handling. No PHP installation is needed on the AI application's host.

The service accepts two environment variables:

| Variable | Value |
| --- | --- |
| `JOOMENGINE_MCP_URL` | The HTTPS Joomla installation base URL, including its subdirectory if applicable. |
| `JOOMENGINE_MCP_TOKEN` | That site's separately supplied native Joomla API token. |

The component and its HTTP routing plugin must be installed and enabled on the target website. The API user needs the native Joomla API login permission and the component's MCP access permission. Tools, resources, prompts, action permissions, approval grants and durable jobs come from the authenticated server; changing packaging cannot grant local-console authority.

## AI application launch

Use the [README command](../README.md#run-with-docker-compose) to supply the two values without writing the token into a shell command. In an MCP application's stdio configuration, select `docker` as the executable and use these individual arguments:

1. `compose`
2. `--file`
3. The absolute `compose.yaml` path, obtained with `realpath compose.yaml` in this checkout.
4. `run`
5. `--rm`
6. `--no-deps`
7. `-T`
8. `mcp`

Set the two environment values through that application's environment or secret configuration. Keep the checkout at that path. Each AI connection starts its own process and authenticated remote session. The service deliberately has no restart policy, port mapping or application healthcheck: its lifetime belongs to the stdio connection. Use `run`, which attaches stdin/stdout; `up` is intended for service logs and is not this MCP launcher. Build before configuring the AI application so dependency-download output never enters a protocol session.

The bridge uses newline-delimited JSON-RPC, sends diagnostics to stderr and closes its server session on EOF or an orderly signal. `-T` prevents terminal framing from changing the protocol. The Compose grace period allows a bounded pending request and session DELETE to finish; forced termination can leave remote writes running, so inspect their job state before retrying.

The default connection is ephemeral. No credentials are copied into the image or written to a volume. Docker administrators can inspect a container's environment, so use the host's ordinary Docker and secret-management access controls. Named-site storage remains available through the PHP executable but is not needed by this read-only container setup.

An AI application that already supports Streamable HTTP and a custom `X-Joomla-Token` header can connect directly to the component endpoint documented in [SERVER-CONTRACT.md](SERVER-CONTRACT.md). Compose provides the stdio bridge for applications that need it; it does not open a second HTTP server.

## Private certificate authorities

Publicly trusted server certificates use the image's standard CA bundle. For a private site, provide the organization's trusted PEM CA bundle and keep certificate/hostname verification enabled. Place the bundle at `certificates/joomla-ca-bundle.pem` beside `compose.yaml`, and save this as `compose.ca.yaml`:

```yaml
services:
  mcp:
    volumes:
      - ./certificates/joomla-ca-bundle.pem:/run/joomla-ca-bundle.pem:ro
    entrypoint:
      - php
      - -d
      - curl.cainfo=/run/joomla-ca-bundle.pem
      - /app/bin/joomengine-mcp
```

The file must be readable by the image's UID 10001. Use `docker compose --file compose.yaml --file compose.ca.yaml run --rm --no-deps -T mcp`, and add the same second `--file` argument with its absolute path in the AI launcher. Keep private certificates/configuration out of source control. A Joomla server on the host or another network must be reachable from the Docker engine using its certificate's valid hostname; `localhost` ordinarily addresses the container itself.

## Build and verification

PHP 8.3 is the default. `JOOMENGINE_MCP_PHP_VERSION=8.4 docker compose build --pull mcp` builds the tested PHP 8.4 variant under the same local image name. Build arguments select the PHP runtime only; no token is a build argument. The build context uses an explicit file allowlist, and the final image excludes Composer, test fixtures, repository metadata and local credentials. No published registry image is assumed.

`bash tests/container.sh` requires Docker Compose v2, a Linux Docker engine, Node.js and OpenSSL. It builds from source, verifies runtime extensions/non-root/read-only execution, rejects missing configuration, performs authenticated initialization and discovery through real TLS, verifies an invalid token is rejected, and observes session deletion. The fixture alone uses host networking and a temporary CA mount; the delivered Compose service keeps its normal isolated networking. CI runs this on PHP 8.3 and 8.4 and retains its log. These checks complement the separately recorded installed Joomla/JCB acceptance; simulated protocol responses are not evidence of installed Joomla execution.

Docker's [Compose run reference](https://docs.docker.com/reference/cli/docker/compose/run/) documents stream/terminal options. The image uses the [official PHP image](https://hub.docker.com/_/php) and [official Composer image](https://hub.docker.com/_/composer).
