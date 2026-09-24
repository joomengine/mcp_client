<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Joomla\Mcp\Client\Bridge;


use RuntimeException;
use stdClass;
use Throwable;
use VDM\Joomla\Mcp\Client\Connection;
use VDM\Joomla\Mcp\Client\Http\AsyncClientInterface;
use VDM\Joomla\Mcp\Client\Http\MultiClient;


/**
 * Generic JSON-RPC stdio adapter preserving the remote server's authority.
 *
 * Concurrent exchanges allow cancellation notifications while another request
 * runs. No tool catalogue, write grant, identity or result is manufactured here.
 * SSE responses are bounded by the same limit as JSON responses.
 *
 * @since 0.1.0
 */
final class StdioBridge
{
	private Connection $connection;
	private AsyncClientInterface $http;
	private array $pending = [];
	private ?string $session = null;
	private ?string $protocol = null;
	private bool $initialized = false;
	private bool $initializing = false;
	private bool $readying = false;
	private bool $stopping = false;
	private int $sequence = 0;
	private string $output = '';

	/** @param Connection $connection Site identity. @param ?AsyncClientInterface $http Trusted test transport. */
	public function __construct(Connection $connection, ?AsyncClientInterface $http = null)
	{
		$this->connection = $connection;
		$this->http = $http ?? new MultiClient($connection);
	}

	/** @return void Request orderly disconnect; a disconnected write may still complete remotely. */
	public function stop(): void
	{
		$this->stopping = true;
	}

	/**
	 * @param resource $input Newline-delimited JSON-RPC input.
	 * @param resource $output Protocol-only output.
	 * @param resource $errors Safe diagnostics, never request bodies or credentials.
	 * @return int Process exit status.
	 */
	public function run($input, $output, $errors): int
	{
		$buffer = '';
		$eof = false;
		$disconnecting = false;
		$lastProgress = microtime(true);
		$inputBlocking = stream_get_meta_data($input)['blocked'];
		$outputBlocking = stream_get_meta_data($output)['blocked'];
		stream_set_blocking($input, false);
		stream_set_blocking($output, false);

		try
		{
			while (true)
			{
				if ($this->stopping)
				{
					$buffer = '';
				}

				foreach ($this->http->poll() as $key => $exchange)
				{
					$lastProgress = microtime(true);

					if ($key === 'disconnect')
					{
						continue;
					}

					$this->complete((string) $key, $exchange, $errors);
				}

				while (!$this->readying && ($newline = strpos($buffer, "\n")) !== false)
				{
					$line = substr($buffer, 0, $newline);
					$buffer = substr($buffer, $newline + 1);
					$this->receive($line, $errors);
				}

				if ($eof && !$this->readying && $buffer !== '')
				{
					$this->receive($buffer, $errors);
					$buffer = '';
				}

				if (($eof || $this->stopping) && $buffer === '' && $this->pending === [] && !$disconnecting)
				{
					$disconnecting = true;

					if ($this->session !== null)
					{
						$this->http->start('disconnect', '', $this->headers(), 'DELETE');
						$this->session = null;
					}
				}

				if ($disconnecting && $this->http->count() === 0 && $this->output === '')
				{
					return 0;
				}

				if ($this->output !== '' && microtime(true) - $lastProgress > $this->connection->timeout())
				{
					throw new RuntimeException('The local MCP client stopped reading responses.');
				}

				$read = (!$eof && !$this->stopping && !$this->readying) ? [$input] : [];
				$write = $this->output !== '' ? [$output] : [];
				$except = [];

				if ($read === [] && $write === [])
				{
					usleep(20000);
					continue;
				}

				$selected = @stream_select($read, $write, $except, 0, 20000);

				if ($selected === false)
				{
					if ($this->stopping)
					{
						continue;
					}

					throw new RuntimeException('The local MCP streams could not be selected.');
				}

				if ($write !== [])
				{
					$written = @fwrite($output, substr($this->output, 0, 8192));

					if ($written === false || ($written === 0 && feof($output)))
					{
						throw new RuntimeException('The local MCP output closed.');
					}

					if ($written > 0)
					{
						$this->output = substr($this->output, $written);
						$lastProgress = microtime(true);
					}
				}

				if ($read !== [])
				{
					$chunk = fread($input, 8192);

					if ($chunk === false)
					{
						throw new RuntimeException('The local MCP input could not be read.');
					}

					$buffer .= $chunk;
					$eof = feof($input);
				}

				while (!$this->readying && ($newline = strpos($buffer, "\n")) !== false)
				{
					$line = substr($buffer, 0, $newline);
					$buffer = substr($buffer, $newline + 1);
					$this->receive($line, $errors);
				}

				if (strlen($buffer) > $this->connection->maximum())
				{
					throw new RuntimeException('The local MCP message exceeds its byte limit.');
				}

				if ($eof && !$this->readying && $buffer !== '')
				{
					$this->receive($buffer, $errors);
					$buffer = '';
				}
			}
		}
		catch (Throwable)
		{
			fwrite($errors, "MCP bridge stopped after a bounded transport or framing failure; reconcile any submitted writes before retrying.\n");
			return 1;
		}
		finally
		{
			$this->http->close();
			stream_set_blocking($input, $inputBlocking);
			stream_set_blocking($output, $outputBlocking);
		}
	}

	/** @param string $line One frame. @param resource $errors Redacted diagnostics. */
	private function receive(string $line, $errors): void
	{
		if (strlen($line) > $this->connection->maximum())
		{
			throw new RuntimeException('MCP input is too large.');
		}

		if (trim($line) === '')
		{
			return;
		}

		try
		{
			$message = json_decode($line, false, 64, JSON_THROW_ON_ERROR);
		}
		catch (Throwable)
		{
			$this->error(null, -32700, 'Invalid JSON.');
			return;
		}

		if (!$this->valid($message))
		{
			$this->error(null, -32600, 'Expected one JSON-RPC 2.0 message.');
			return;
		}

		$id = $message->id ?? null;
		$method = $message->method ?? null;
		$initialization = $method === 'initialize';

		if ($initialization && ($this->initializing || $this->initialized))
		{
			$this->error($id, -32600, 'This connection is already initialized.');
			return;
		}

		if (!$initialization && !$this->initialized)
		{
			if (property_exists($message, 'id'))
			{
				$this->error($id, -32002, 'Initialize the MCP connection first.');
			}
			return;
		}

		if ($initialization && !property_exists($message, 'id'))
		{
			return;
		}

		foreach ($this->pending as $request)
		{
			if ($method !== null && property_exists($message, 'id') && $request['expects'] && $request['id'] === $id)
			{
				$this->error($id, -32600, 'The request ID is already in flight.');
				return;
			}
		}

		if (count($this->pending) >= 30 && $method !== null && property_exists($message, 'id'))
		{
			$this->error($id, -32000, 'The bridge request capacity is exhausted.');
			return;
		}

		$key = (string) ++$this->sequence;
		$this->pending[$key] = ['id' => $id, 'expects' => $method !== null && property_exists($message, 'id'), 'initialize' => $initialization, 'ready' => $method === 'notifications/initialized'];
		$this->readying = $method === 'notifications/initialized';
		$this->initializing = $this->initializing || $initialization;

		try
		{
			$this->http->start($key, $line, $this->headers());
		}
		catch (Throwable)
		{
			$this->complete($key, ['error' => true, 'response' => null], $errors);
		}
	}

	/** @param string $key Exchange key. @param array $exchange Bounded HTTP result. @param resource $errors Diagnostic stream. */
	private function complete(string $key, array $exchange, $errors): void
	{
		$request = $this->pending[$key] ?? null;
		unset($this->pending[$key]);

		if ($request === null)
		{
			return;
		}

		if ($request['initialize'])
		{
			$this->initializing = false;
		}

		if ($request['ready'])
		{
			$this->readying = false;
		}

		try
		{
			$response = $exchange['response'];

			if ($exchange['error'] || $response === null)
			{
				throw new RuntimeException('Transport failure.');
			}

			$status = $response->getStatusCode();

			if (!$request['expects'] && in_array($status, [202, 204], true))
			{
				return;
			}

			if ($status !== 200)
			{
				throw new RuntimeException('Unexpected HTTP status.');
			}

			$type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
			$body = (string) $response->getBody();
			$messages = match ($type)
			{
				'application/json' => [json_decode($body, false, 64, JSON_THROW_ON_ERROR)],
				'text/event-stream' => $this->events($body),
				default => throw new RuntimeException('Unexpected HTTP response type.'),
			};
			$matched = false;

			foreach ($messages as $message)
			{
				if (!$this->valid($message))
				{
					throw new RuntimeException('Invalid remote JSON-RPC.');
				}

				if (!isset($message->method))
				{
					if (!$request['expects'] || $message->id !== $request['id'] || $matched)
					{
						throw new RuntimeException('Unexpected remote response ID.');
					}
					$matched = true;
				}
			}

			if ($request['expects'] && !$matched)
			{
				throw new RuntimeException('The remote response is incomplete.');
			}

			$session = $response->getHeaderLine('Mcp-Session-Id');

			if ($session !== '' && (strlen($session) > 1024 || preg_match('/[^\x21-\x7e]/', $session)
				|| ($this->session !== null && $session !== $this->session)))
			{
				throw new RuntimeException('Invalid remote session.');
			}

			foreach ($messages as $message)
			{
				if ($request['initialize'] && isset($message->result))
				{
					$revision = $message->result->protocolVersion ?? null;

					if (!is_string($revision) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $revision))
					{
						throw new RuntimeException('Invalid negotiated protocol revision.');
					}

					$this->protocol = $revision;
					$this->session = $session === '' ? null : $session;
					$this->initialized = true;
				}

				$this->emit($message);
			}
		}
		catch (Throwable)
		{
			if ($request['ready'])
			{
				$this->initialized = false;
			}

			if ($request['expects'])
			{
				$this->error($request['id'], -32000, 'Remote MCP exchange failed; a submitted write may have completed. Reconcile before retrying.');
			}
			else
			{
				fwrite($errors, "A remote MCP notification or response could not be delivered.\n");
			}
		}
	}

	/** @return array<string,string> Only protocol metadata; transport supplies the site token. */
	private function headers(): array
	{
		$headers = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'];

		if ($this->session !== null)
		{
			$headers['Mcp-Session-Id'] = $this->session;
		}

		if ($this->protocol !== null)
		{
			$headers['MCP-Protocol-Version'] = $this->protocol;
		}

		return $headers;
	}

	/** @param mixed $message Candidate frame. @return bool Whether the envelope is well formed. */
	private function valid(mixed $message): bool
	{
		if (!$message instanceof stdClass || ($message->jsonrpc ?? null) !== '2.0')
		{
			return false;
		}

		if (property_exists($message, 'id') && !is_string($message->id) && !is_int($message->id) && $message->id !== null)
		{
			return false;
		}

		if (isset($message->method))
		{
			return is_string($message->method) && $message->method !== '' && !property_exists($message, 'result')
				&& !property_exists($message, 'error') && (!property_exists($message, 'params') || is_object($message->params) || is_array($message->params));
		}

		return property_exists($message, 'id') && (property_exists($message, 'result') xor property_exists($message, 'error'));
	}

	/** @param string $body Finite SSE response. @return array<int,stdClass> JSON-RPC events, excluding keepalives. */
	private function events(string $body): array
	{
		$messages = [];
		$data = [];

		foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $body)) as $line)
		{
			if ($line === '')
			{
				if ($data !== [])
				{
					$messages[] = json_decode(implode("\n", $data), false, 64, JSON_THROW_ON_ERROR);
					$data = [];
				}
			}
			elseif (str_starts_with($line, 'data:'))
			{
				$value = substr($line, 5);
				$data[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
			}
		}

		if ($data !== [])
		{
			throw new RuntimeException('The SSE event ended without its framing delimiter.');
		}

		return $messages;
	}

	/** @param mixed $id Request identifier. @param int $code Protocol error. @param string $message Safe message. */
	private function error(mixed $id, int $code, string $message): void
	{
		$this->emit((object) ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
	}

	/** @param stdClass $message One complete message. */
	private function emit(stdClass $message): void
	{
		$line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

		if (strlen($line) > $this->connection->maximum() + 1 || strlen($this->output) + strlen($line) > 2 * $this->connection->maximum())
		{
			throw new RuntimeException('The local MCP output exceeds its byte limit.');
		}

		$this->output .= $line;
	}
}
