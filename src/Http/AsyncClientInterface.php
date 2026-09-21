<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Joomla\Mcp\Client\Http;


use Psr\Http\Message\ResponseInterface;


/**
 * Concurrent endpoint-bound HTTP exchanges for the remote stdio bridge.
 *
 * @since 0.1.0
 */
interface AsyncClientInterface
{
	/**
	 * Queue one exchange; completing or failing an exchange never retries it.
	 *
	 * @param string $key Unique correlation key for the pending exchange.
	 * @param string $body Request body, within the configured byte limit.
	 * @param array<string,string> $headers MCP content and session headers only.
	 * @param string $method POST or DELETE at the configured endpoint.
	 * @return void
	 * @throws \RuntimeException When the request cannot be queued safely.
	 * @since 0.1.0
	 */
	public function start(string $key, string $body, array $headers = [], string $method = 'POST'): void;

	/**
	 * Advance pending exchanges without blocking for socket readiness.
	 *
	 * @return array<string,array{response:ResponseInterface|null,error:bool}> Completed exchanges only.
	 * @since 0.1.0
	 */
	public function poll(): array;

	/** @return int Number of pending exchanges. @since 0.1.0 */
	public function count(): int;

	/** @return void Cancel pending exchanges and release transport resources. @since 0.1.0 */
	public function close(): void;
}
