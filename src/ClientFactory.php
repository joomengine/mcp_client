<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Joomla\Mcp\Client;


use Composer\InstalledVersions;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use VDM\Joomla\Mcp\Client\Http\CurlClient;
use VDM\Joomla\Mcp\Client\Http\EndpointClient;


/**
 * Connects the official PHP SDK without requiring a local Joomla installation.
 *
 * The SDK exposes discovery, tool calls, resources and prompts. This factory
 * never embeds Joomla or JCB operation names, schemas or permission policies.
 *
 * @since 0.1.0
 */
final class ClientFactory
{
	/** @var ?ClientInterface Optional trusted transport substitution for integration/testing. @since 0.1.0 */
	private ?ClientInterface $http;

	/** @param ?ClientInterface $http Bounded transport with redirects disabled. @since 0.1.0 */
	public function __construct(?ClientInterface $http = null)
	{
		$this->http = $http;
	}

	/**
	 * Connect one site; callers must disconnect the returned client in finally.
	 *
	 * @param Connection $connection Validated URL and API token.
	 * @return Client Initialized PHP MCP client with server-discovered capabilities.
	 * @throws \Mcp\Exception\ConnectionException For a failed exchange/handshake.
	 * @since 0.1.0
	 */
	public function connect(Connection $connection): Client
	{
		$http = $this->http ?? new CurlClient($connection->timeout(), $connection->maximum());
		$factory = new Psr17Factory();
		$client = Client::builder()
			->setClientInfo('joomengine-mcp-client', $this->version())
			->setInitTimeout($connection->timeout())
			->setRequestTimeout($connection->timeout())
			->setMaxRetries(0)
			->build();
		$client->connect(new HttpTransport($connection->endpoint(), [], new EndpointClient($connection, $http),
			$factory, $factory, maxSseBufferBytes: $connection->maximum()));

		return $client;
	}

	/**
	 * Keep wire metadata aligned with Composer's installed tag or development ref.
	 *
	 * @return string Installed package version, or dev for an unregistered checkout.
	 * @since 0.1.0
	 */
	private function version(): string
	{
		if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('joomengine/mcp-client'))
		{
			return 'dev';
		}

		return InstalledVersions::getPrettyVersion('joomengine/mcp-client') ?? 'dev';
	}
}
