<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Joomla\Mcp\Client;


use InvalidArgumentException;
use SensitiveParameter;


/**
 * One HTTPS Joomla installation and its separately supplied API credential.
 *
 * The endpoint is derived from the server's registered route, not from a tool
 * argument. This object never infers privileged local-console authority.
 *
 * @since 0.1.0
 */
final class Connection
{
	/** @var string Canonical installation-specific MCP endpoint. @since 0.1.0 */
	private string $endpoint;
	/** @var string In-memory credential, excluded from diagnostic output. @since 0.1.0 */
	private string $token;
	/** @var int Request timeout in seconds. @since 0.1.0 */
	private int $timeout;
	/** @var int Maximum request/response bytes. @since 0.1.0 */
	private int $maximum;

	/**
	 * Configure an HTTPS installation without embedded URL credentials.
	 *
	 * @param string $site Joomla base URL, including any installation subdirectory.
	 * @param string $token Joomla API token, not an MCP grant or session ID.
	 * @param int $timeout Maximum exchange duration in seconds.
	 * @param int $maximum Maximum exchange size in bytes.
	 * @throws InvalidArgumentException For unsafe URLs, credentials or bounds.
	 * @since 0.1.0
	 */
	public function __construct(string $site, #[SensitiveParameter] string $token, int $timeout = 30, int $maximum = 8388608)
	{
		if ($token === '' || strlen($token) > 16384 || preg_match('/[\x00-\x20\x7f]/', $token)
			|| $timeout < 1 || $timeout > 120 || $maximum < 1024 || $maximum > 16777216)
		{
			throw new InvalidArgumentException('A valid Joomla token and bounded transport settings are required.');
		}

		$this->endpoint = $this->resolve($site);
		$this->token = $token;
		$this->timeout = $timeout;
		$this->maximum = $maximum;
	}

	/**
	 * Keep credentials out of command-line arguments and source files.
	 *
	 * @param string $site Joomla installation URL.
	 * @return self A connection using JOOMENGINE_MCP_TOKEN.
	 * @throws InvalidArgumentException When the token is missing or invalid.
	 * @since 0.1.0
	 */
	public static function fromEnvironment(string $site): self
	{
		$token = getenv('JOOMENGINE_MCP_TOKEN');

		return new self($site, is_string($token) ? $token : '');
	}

	/** @return string Canonical MCP URL without secrets. @since 0.1.0 */
	public function endpoint(): string
	{
		return $this->endpoint;
	}

	/** @return int Transport timeout. @since 0.1.0 */
	public function timeout(): int
	{
		return $this->timeout;
	}

	/** @return int Request and response byte limit. @since 0.1.0 */
	public function maximum(): int
	{
		return $this->maximum;
	}

	/**
	 * Supply credentials only to the endpoint-bound transport.
	 *
	 * @return array<string,string> Sensitive headers; never log this array.
	 * @since 0.1.0
	 */
	public function headers(): array
	{
		return ['X-Joomla-Token' => $this->token];
	}

	/** @return array<string,mixed> Redacted object diagnostics. @since 0.1.0 */
	public function __debugInfo(): array
	{
		return ['endpoint' => $this->endpoint, 'token' => '[redacted]', 'timeout' => $this->timeout, 'maximum' => $this->maximum];
	}

	/** @return array Never serialize connection credentials. @throws \LogicException Always. @since 0.1.0 */
	public function __serialize(): array
	{
		throw new \LogicException('MCP credentials must not be serialized.');
	}

	/**
	 * Canonicalize the site path without guessing redirects or alternative hosts.
	 *
	 * @param string $site Explicit operator-configured installation URL.
	 * @return string Canonical endpoint derived from v1/joomengine-mcp.
	 * @throws InvalidArgumentException For ambiguous or unsafe installation URLs.
	 * @since 0.1.0
	 */
	private function resolve(string $site): string
	{
		$parts = parse_url($site);

		if (strlen($site) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $site) || !is_array($parts)
			|| strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
			|| isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['pass'])
			|| array_key_exists('query', $parts) || array_key_exists('fragment', $parts))
		{
			throw new InvalidArgumentException('Use an HTTPS Joomla base URL without credentials, query or fragment.');
		}

		$host = strtolower($parts['host']);
		$address = trim($host, '[]');

		if (filter_var($address, FILTER_VALIDATE_IP) === false
			&& filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)
		{
			throw new InvalidArgumentException('The Joomla site host is invalid; use an ASCII or punycode host.');
		}

		$port = $parts['port'] ?? 443;

		if ($port < 1 || $port > 65535)
		{
			throw new InvalidArgumentException('The Joomla site port is invalid.');
		}

		$path = rtrim($parts['path'] ?? '', '/');
		$segments = $path === '' ? [] : explode('/', ltrim($path, '/'));
		$canonical = [];

		if (str_contains($parts['path'] ?? '', '//'))
		{
			throw new InvalidArgumentException('The Joomla installation path is ambiguous.');
		}

		foreach ($segments as $segment)
		{
			$decoded = rawurldecode($segment);

			if ($segment === '' || preg_match('/%(?![a-fA-F0-9]{2})/', $segment) || in_array($decoded, ['.', '..'], true)
				|| preg_match('/[\x00-\x1f\x7f\\\\\/]/', $decoded))
			{
				throw new InvalidArgumentException('The Joomla installation path contains an unsafe segment.');
			}

			$canonical[] = rawurlencode($decoded);
		}

		return 'https://' . $host . ($port === 443 ? '' : ':' . $port)
			. ($canonical === [] ? '' : '/' . implode('/', $canonical)) . '/api/index.php/v1/joomengine-mcp';
	}
}
