<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Joomla\Mcp\Client\Http;


use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use VDM\Joomla\Mcp\Client\Connection;


/**
 * Binds SDK traffic to one configured endpoint and rejects HTTP error pages.
 *
 * The supplied transport must obey the configured byte/time bounds and must not
 * follow redirects. The default CurlClient provides that contract.
 *
 * @since 0.1.0
 */
final class EndpointClient implements ClientInterface
{
	/** @var Connection Installation-specific configuration. @since 0.1.0 */
	private Connection $connection;
	/** @var ClientInterface Bounded non-redirecting transport. @since 0.1.0 */
	private ClientInterface $http;

	/** @param Connection $connection Endpoint. @param ClientInterface $http Trusted transport. @since 0.1.0 */
	public function __construct(Connection $connection, ClientInterface $http)
	{
		$this->connection = $connection;
		$this->http = $http;
	}

	/**
	 * Send one request and preserve failed/uncertain outcomes without retries.
	 *
	 * @param RequestInterface $request SDK-created MCP request.
	 * @return ResponseInterface MCP JSON, SSE or an accepted notification.
	 * @throws RuntimeException For unexpected endpoint, status or media type.
	 * @since 0.1.0
	 */
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		if ((string) $request->getUri() !== $this->connection->endpoint()
			|| !in_array($request->getMethod(), ['POST', 'DELETE'], true))
		{
			throw new RuntimeException('MCP transport refused a request outside the configured endpoint.');
		}

		$request = $request->withoutHeader('Authorization')->withoutHeader('X-Joomla-Token');

		foreach ($this->connection->headers() as $name => $value)
		{
			$request = $request->withHeader($name, $value);
		}

		$response = $this->http->sendRequest($request);
		$status = $response->getStatusCode();

		if ($request->getMethod() === 'DELETE' && in_array($status, [200, 202, 204, 404, 405], true))
		{
			return $response;
		}

		if ($status === 202)
		{
			$payload = json_decode((string) $request->getBody());

			if ($payload instanceof \stdClass && !property_exists($payload, 'id'))
			{
				return $response;
			}
		}

		if ($status !== 200)
		{
			throw new RuntimeException('MCP HTTP exchange returned status ' . $status . '; a submitted write may require reconciliation.', $status);
		}

		$type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

		if (!in_array($type, ['application/json', 'text/event-stream'], true))
		{
			throw new RuntimeException('The endpoint did not return an MCP JSON or SSE response.');
		}

		return $response;
	}
}
