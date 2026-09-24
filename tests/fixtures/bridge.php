<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Nyholm\Psr7\Response;
use VDM\Joomla\Mcp\Client\Bridge\StdioBridge;
use VDM\Joomla\Mcp\Client\Connection;
use VDM\Joomla\Mcp\Client\Http\AsyncClientInterface;

$transport = new class($argv[1]) implements AsyncClientInterface
{
	private array $pending = [];
	private string $audit;
	private bool $cancelled = false;

	public function __construct(string $audit)
	{
		$this->audit = $audit;
	}

	public function start(string $key, string $body, array $headers = [], string $method = 'POST'): void
	{
		$message = $body === '' ? null : json_decode($body);
		file_put_contents($this->audit, json_encode(['method' => $method, 'message' => $message, 'headers' => $headers]) . "\n", FILE_APPEND);
		if (($message->method ?? '') === 'notifications/cancelled')
		{
			$this->cancelled = true;
		}
		$this->pending[$key] = ['message' => $message, 'time' => microtime(true), 'method' => $method];
	}

	public function poll(): array
	{
		$completed = [];
		foreach ($this->pending as $key => $request)
		{
			$message = $request['message'];
			if (($message->params->name ?? '') === 'slow' && !$this->cancelled && microtime(true) - $request['time'] < 1)
			{
				continue;
			}
			unset($this->pending[$key]);
			if ($request['method'] === 'DELETE' || !property_exists($message, 'id'))
			{
				$completed[$key] = ['response' => new Response(202), 'error' => false];
				continue;
			}
			$headers = ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'isolated-session'];
			$result = match ($message->method ?? '')
			{
				'initialize' => ['protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => new stdClass()], 'serverInfo' => ['name' => 'fixture', 'version' => '1']],
				'tools/list' => isset($message->params->cursor) ? ['tools' => [['name' => 'second', 'inputSchema' => ['type' => 'object']]]] : ['tools' => [['name' => 'first', 'inputSchema' => ['type' => 'object']]], 'nextCursor' => 'opaque-next'],
				'tools/call' => ['content' => [['type' => 'text', 'text' => 'Fixture result']], 'structuredContent' => ['arguments' => $message->params->arguments ?? new stdClass(), 'job' => ['id' => 'opaque-job', 'state' => $this->cancelled ? 'cancelled' : 'running']], 'isError' => false],
				'resources/list' => ['resources' => [['uri' => 'fixture://data', 'name' => 'Fixture']]],
				'prompts/list' => ['prompts' => [['name' => 'fixture']]],
				default => new stdClass(),
			};
			$reply = ['jsonrpc' => '2.0', 'id' => $message->id, 'result' => $result];
			if (($message->params->name ?? '') === 'wrong-id')
			{
				$reply['id'] = 'different';
			}
			$body = json_encode($reply);
			if (($message->params->name ?? '') === 'events')
			{
				$headers['Content-Type'] = 'text/event-stream';
				$body = ': keepalive' . "\r\n\r\n" . 'data: {"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":"progress-1","progress":1}}' . "\r\n\r\n" . 'data: ' . $body . "\n\n";
			}
			$status = ($message->params->name ?? '') === 'denied' ? 403 : 200;
			$completed[$key] = ['response' => new Response($status, $headers, $body), 'error' => false];
		}
		return $completed;
	}

	public function count(): int
	{
		return count($this->pending);
	}

	public function close(): void
	{
		$this->pending = [];
	}
};
exit((new StdioBridge(new Connection('https://example.test/subdirectory', 'never-print-fixture-token', 30, (int) ($argv[2] ?? 8388608)), $transport))->run(STDIN, STDOUT, STDERR));
