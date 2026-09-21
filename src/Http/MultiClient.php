<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Joomla\Mcp\Client\Http;


use CurlHandle;
use CurlMultiHandle;
use Nyholm\Psr7\Response;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use VDM\Joomla\Mcp\Client\Connection;


/**
 * Bounded concurrent HTTPS requests to one canonical installation endpoint.
 *
 * HTTP error statuses remain responses. Network, TLS and byte/time failures
 * have an uncertain outcome and are returned without upstream diagnostics.
 * Polling performs no socket wait, leaving stdin and cancellation responsive.
 *
 * @since 0.1.0
 */
final class MultiClient implements AsyncClientInterface
{
	/** @var Connection Endpoint and in-memory credential. @since 0.1.0 */
	private Connection $connection;
	/** @var CurlMultiHandle|null Active cURL scheduler, null after close. @since 0.1.0 */
	private ?CurlMultiHandle $multi;
	/** @var array<string,object> Per-exchange handles and bounded response state. @since 0.1.0 */
	private array $pending;
	/** @var array<int,string> Handle identities mapped to caller correlation keys. @since 0.1.0 */
	private array $keys;

	/**
	 * Bind the transport to the operator-configured installation.
	 *
	 * @param Connection $connection Validated HTTPS connection and bounds.
	 * @throws RuntimeException When cURL cannot initialize the scheduler.
	 * @since 0.1.0
	 */
	public function __construct(Connection $connection)
	{
		$this->connection = $connection;
		$this->multi = null;
		$this->pending = [];
		$this->keys = [];

		if (!function_exists('curl_multi_init'))
		{
			throw new RuntimeException('The cURL HTTPS transport is unavailable.');
		}

		try
		{
			$this->multi = curl_multi_init();

			if (!curl_multi_setopt($this->multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 32)
				|| !curl_multi_setopt($this->multi, CURLMOPT_MAX_HOST_CONNECTIONS, 32))
			{
				throw new RuntimeException();
			}
		}
		catch (Throwable)
		{
			$this->close();
			throw new RuntimeException('The cURL HTTPS transport could not initialize.');
		}
	}

	/**
	 * Queue exactly one request; the caller cannot change the URL or credential.
	 *
	 * @param string $key Unique pending exchange key.
	 * @param string $body Bounded request payload.
	 * @param array<string,string> $headers Allowlisted MCP headers.
	 * @param string $method POST or DELETE.
	 * @return void
	 * @throws RuntimeException For unsafe, oversized or excessive requests.
	 * @since 0.1.0
	 */
	public function start(string $key, #[SensitiveParameter] string $body, #[SensitiveParameter] array $headers = [], string $method = 'POST'): void
	{
		if ($this->multi === null || $key === '' || strlen($key) > 512 || isset($this->pending[$key])
			|| count($this->pending) >= 32 || strlen($body) > $this->connection->maximum()
			|| !in_array($method, ['POST', 'DELETE'], true))
		{
			throw new RuntimeException('The HTTP exchange cannot be queued within the configured transport bounds.');
		}

		$requestHeaders = $this->requestHeaders($headers);
		$handle = curl_init($this->connection->endpoint());

		if ($handle === false)
		{
			throw new RuntimeException('The HTTP exchange could not initialize.');
		}

		// Each callback owns one exchange's state; simultaneous replies cannot mix.
		$state = new class
		{
			/** @var CurlHandle Handle for this exchange only. */
			public CurlHandle $handle;
			/** @var string Bounded decoded response payload. */
			public string $body = '';
			/** @var array<string,string[]> Final response headers. */
			public array $headers = [];
			/** @var int Aggregate response header bytes, including interim headers. */
			public int $headerBytes = 0;
		};
		$state->handle = $handle;
		$maximum = $this->connection->maximum();
		$options = [
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => $requestHeaders,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS => 0,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => min(10, $this->connection->timeout()),
			CURLOPT_TIMEOUT => $this->connection->timeout(),
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => 0,
			CURLOPT_PROXY => '',
			CURLOPT_NETRC => CURL_NETRC_IGNORED,
			CURLOPT_NOSIGNAL => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_FRESH_CONNECT => true,
			CURLOPT_FORBID_REUSE => true,
			CURLOPT_ENCODING => '',
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use ($state, $maximum): int
			{
				if (strlen($state->body) + strlen($chunk) > $maximum)
				{
					return 0;
				}

				$state->body .= $chunk;

				return strlen($chunk);
			},
			CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use ($state): int
			{
				$length = strlen($line);
				$state->headerBytes += $length;

				if ($state->headerBytes > 65536)
				{
					return 0;
				}

				if (str_starts_with($line, 'HTTP/'))
				{
					$state->headers = [];
				}
				elseif (($colon = strpos($line, ':')) !== false)
				{
					$name = strtolower(substr($line, 0, $colon));
					$value = trim(substr($line, $colon + 1));

					if (preg_match('/\A[a-z0-9!#$%&\x27*+.^_`|~-]+\z/D', $name) !== 1
						|| preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1)
					{
						return 0;
					}

					$state->headers[$name][] = $value;
				}
				elseif (trim($line) !== '')
				{
					return 0;
				}

				return $length;
			},
		];

		try
		{
			if (!curl_setopt_array($handle, $options) || curl_multi_add_handle($this->multi, $handle) !== CURLM_OK)
			{
				throw new RuntimeException();
			}
		}
		catch (Throwable)
		{
			unset($state->handle);
			curl_close($handle);
			throw new RuntimeException('The HTTP exchange could not be queued.');
		}

		$this->pending[$key] = $state;
		$this->keys[spl_object_id($handle)] = $key;
	}

	/**
	 * Advance cURL once and collect completed responses without waiting for I/O.
	 *
	 * @return array<string,array{response:\Psr\Http\Message\ResponseInterface|null,error:bool}>
	 * @since 0.1.0
	 */
	public function poll(): array
	{
		if ($this->multi === null || $this->pending === [])
		{
			return [];
		}

		$completed = [];

		try
		{
			do
			{
				$status = curl_multi_exec($this->multi, $running);
			}
			while ($status === CURLM_CALL_MULTI_PERFORM);

			if ($status !== CURLM_OK)
			{
				throw new RuntimeException();
			}

			while (($message = curl_multi_info_read($this->multi)) !== false)
			{
				$handle = $message['handle'];
				$key = $this->keys[spl_object_id($handle)] ?? null;

				if ($key === null || $message['msg'] !== CURLMSG_DONE)
				{
					continue;
				}

				$state = $this->pending[$key];
				$response = null;
				$code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

				if ($message['result'] === CURLE_OK && $code >= 100 && $code <= 599)
				{
					// cURL has decoded compression and transfer framing already.
					unset($state->headers['content-encoding'], $state->headers['transfer-encoding'], $state->headers['content-length']);

					try
					{
						$response = new Response($code, $state->headers, $state->body);
					}
					catch (Throwable)
					{
						// Invalid upstream metadata is a transport failure, never a log.
					}
				}

				$completed[$key] = ['response' => $response, 'error' => $response === null];
				$this->release($key);
			}
		}
		catch (Throwable)
		{
			foreach (array_keys($this->pending) as $key)
			{
				$completed[$key] = ['response' => null, 'error' => true];
			}

			$this->close();
		}

		return $completed;
	}

	/** @return int Active request count. @since 0.1.0 */
	public function count(): int
	{
		return count($this->pending);
	}

	/** @return void Cancel requests without retrying ambiguous writes. @since 0.1.0 */
	public function close(): void
	{
		foreach (array_keys($this->pending) as $key)
		{
			$this->release((string) $key);
		}

		if ($this->multi !== null)
		{
			curl_multi_close($this->multi);
			$this->multi = null;
		}
	}

	/** @return void Release pending handles when the owner exits. @since 0.1.0 */
	public function __destruct()
	{
		$this->close();
	}

	/** @return array<string,mixed> Diagnostics without payloads or credentials. @since 0.1.0 */
	public function __debugInfo(): array
	{
		return ['pending' => $this->count(), 'closed' => $this->multi === null];
	}

	/** @return array Never persist credentials or pending payloads. @throws \LogicException Always. @since 0.1.0 */
	public function __serialize(): array
	{
		throw new \LogicException('MCP transport credentials and payloads must not be serialized.');
	}

	/**
	 * Validate the complete request header set before any network activity.
	 *
	 * @param array<string,string> $headers Non-credential MCP headers.
	 * @return string[] cURL header lines containing the connection-owned token.
	 * @throws RuntimeException For unsafe headers or header size overflow.
	 * @since 0.1.0
	 */
	private function requestHeaders(#[SensitiveParameter] array $headers): array
	{
		$allowed = ['accept', 'content-type', 'mcp-session-id', 'mcp-protocol-version'];
		$normalized = ['accept' => 'application/json, text/event-stream', 'content-type' => 'application/json'];
		$seen = [];

		foreach ($headers as $name => $value)
		{
			$lower = is_string($name) ? strtolower($name) : '';

			if (!in_array($lower, $allowed, true) || isset($seen[$lower]) || !is_string($value)
				|| preg_match('/[\x00-\x1f\x7f]/', $value) === 1)
			{
				throw new RuntimeException('The HTTP exchange contains an unsupported or unsafe header.');
			}

			$seen[$lower] = true;
			$normalized[$lower] = $value;
		}

		$lines = ['Expect:'];
		$bytes = strlen($lines[0]) + 2;

		foreach (array_merge($normalized, $this->connection->headers()) as $name => $value)
		{
			$line = $name . ': ' . $value;
			$bytes += strlen($line) + 2;

			if ($bytes > 65536)
			{
				throw new RuntimeException('The HTTP exchange headers exceed the transport limit.');
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/** @param string $key Completed or cancelled exchange key. @return void @since 0.1.0 */
	private function release(string $key): void
	{
		$state = $this->pending[$key];
		$handle = $state->handle;
		unset($state->handle);

		if ($this->multi !== null)
		{
			curl_multi_remove_handle($this->multi, $handle);
		}

		unset($this->keys[spl_object_id($handle)], $this->pending[$key]);
		curl_close($handle);
	}
}
