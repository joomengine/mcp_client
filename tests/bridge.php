<?php
/** Process-level checks for the transport-neutral remote bridge. */
require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$check = static function (bool $condition, string $message) use (&$passed): void
{
	if (!$condition)
	{
		throw new RuntimeException('FAIL ' . $message);
	}
	$passed++;
	echo 'PASS ' . $message . PHP_EOL;
};
$audit = tempnam(sys_get_temp_dir(), 'mcp-bridge-');
$process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/bridge.php', $audit], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
if (!is_resource($process))
{
	throw new RuntimeException('Cannot start bridge fixture.');
}
$send = static function (array $message) use ($pipes): void
{
	fwrite($pipes[0], json_encode($message, JSON_THROW_ON_ERROR) . "\n");
	fflush($pipes[0]);
};
$readBuffer = '';
stream_set_blocking($pipes[1], false);
$read = static function () use ($pipes, &$readBuffer): array
{
	$deadline = microtime(true) + 4;
	while (($newline = strpos($readBuffer, "\n")) === false)
	{
		$ready = [$pipes[1]];
		$write = $except = [];
		if (microtime(true) > $deadline || stream_select($ready, $write, $except, 0, 100000) === false)
		{
			throw new RuntimeException('Bridge response timeout.');
		}
		if ($ready !== [])
		{
			$chunk = fread($pipes[1], 8192);
			if ($chunk === false || ($chunk === '' && feof($pipes[1])))
			{
				throw new RuntimeException('Bridge ended before its response.');
			}
			$readBuffer .= $chunk;
		}
	}
	$line = substr($readBuffer, 0, $newline);
	$readBuffer = substr($readBuffer, $newline + 1);
	return json_decode($line, true, 64, JSON_THROW_ON_ERROR);
};
try
{
	$send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
	$check($read()['error']['code'] === -32002, 'requests require initialization');
	fwrite($pipes[0], "not-json\n[]\n");
	$check($read()['error']['code'] === -32700, 'invalid JSON receives parse error');
	$check($read()['error']['code'] === -32600, 'batch envelopes are rejected');
	$initialize = ['jsonrpc' => '2.0', 'id' => 'init', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'fixture', 'version' => '1']]];
	$send($initialize);
	$response = $read();
	$check($response['id'] === 'init' && $response['result']['protocolVersion'] === '2025-06-18', 'initialization preserves request and negotiated revision');
	$send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
	$send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
	$first = $read();
	$check($first['result']['nextCursor'] === 'opaque-next', 'pagination cursor is preserved');
	$send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => ['cursor' => $first['result']['nextCursor']]]);
	$check($read()['result']['tools'][0]['name'] === 'second', 'arbitrary discovered second page is forwarded');
	$send(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'events', 'arguments' => ['opaque' => ['value' => 7]]]]);
	$check($read()['method'] === 'notifications/progress', 'SSE progress notification reaches stdio');
	$reply = $read();
	$check($reply['id'] === 4 && $reply['result']['structuredContent']['arguments']['opaque']['value'] === 7, 'SSE structured result and arbitrary arguments preserved');
	$check($reply['result']['structuredContent']['job']['id'] === 'opaque-job', 'server job identifiers remain opaque');
	$send(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'slow']]);
	$send(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 5, 'reason' => 'caller stopped']]);
	$check($read()['result']['structuredContent']['job']['state'] === 'cancelled', 'cancellation is forwarded while request remains in flight');
	$send(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'denied']]);
	$check($read()['error']['code'] === -32000, 'HTTP denial becomes a safe protocol failure');
	$send(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'wrong-id']]);
	$check($read()['error']['code'] === -32000, 'unexpected remote response ID is rejected');
	$send(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'resources/list']);
	$check($read()['result']['resources'][0]['uri'] === 'fixture://data', 'resources remain server-defined');
	$send(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'prompts/list']);
	$check($read()['result']['prompts'][0]['name'] === 'fixture', 'prompts remain server-defined');
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], true);
	$remaining = stream_get_contents($pipes[1]);
	$errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$check(proc_close($process) === 0 && $remaining === '' && $errors === '', 'EOF closes cleanly without stdout diagnostics');
	$process = null;
	$entries = array_map(static fn (string $line): array => json_decode($line, true), file($audit, FILE_IGNORE_NEW_LINES));
	$check(end($entries)['method'] === 'DELETE', 'orderly disconnect ends the remote session');
	$check($entries[1]['headers']['Mcp-Session-Id'] === 'isolated-session' && $entries[1]['headers']['MCP-Protocol-Version'] === '2025-06-18', 'subsequent requests carry negotiated session and revision');
	$check(count(array_filter($entries, static fn (array $entry): bool => ($entry['message']['params']['name'] ?? '') === 'denied')) === 1, 'failed calls are never retried');

	// Apply the configured limit to complete frames, including whitespace and EOF.
	$request = '{"jsonrpc":"2.0","id":17,"method":"tools/list"}';
	foreach ([
		['maximum whitespace frame', str_repeat(' ', 1024) . "\n", 0, 0],
		['oversized whitespace frame', str_repeat(' ', 1025) . "\n", 1, 0],
		['maximum whitespace at EOF', str_repeat(' ', 1024), 0, 0],
		['oversized whitespace at EOF', str_repeat(' ', 1025), 1, 0],
		['maximum JSON frame', str_pad($request, 1024) . "\n", 0, 1],
		['oversized JSON frame', str_pad($request, 1025) . "\n", 1, 0],
		['multiple valid frames exceeding one frame limit in aggregate', str_repeat(str_pad($request, 600) . "\n", 2), 0, 2],
	] as [$name, $input, $expectedStatus, $expectedReplies])
	{
		file_put_contents($audit, '');
		$process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/bridge.php', $audit, '1024'],
			[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

		if (!is_resource($process))
		{
			throw new RuntimeException('Cannot start bounded framing fixture.');
		}

		fwrite($pipes[0], $input);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$diagnostic = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		$process = null;
		$replies = array_filter(explode("\n", $output), static fn (string $line): bool => $line !== '');
		$validReplies = count($replies) === $expectedReplies;

		foreach ($replies as $reply)
		{
			$decoded = json_decode($reply, true, 64, JSON_THROW_ON_ERROR);
			$validReplies = $validReplies && ($decoded['id'] ?? null) === 17 && ($decoded['error']['code'] ?? null) === -32002;
		}

		$check($status === $expectedStatus && $validReplies && file_get_contents($audit) === ''
			&& ($expectedStatus === 0 ? $diagnostic === '' : $diagnostic !== '')
			&& !str_contains($diagnostic, 'never-print-fixture-token'), $name . ' honors byte bounds without HTTP forwarding');
	}

	echo json_encode(['passed' => $passed, 'failed' => 0, 'fixture' => 'process-level protocol; not live Joomla']) . PHP_EOL;
}
finally
{
	if (is_resource($process))
	{
		proc_terminate($process);
		foreach ($pipes as $pipe)
		{
			if (is_resource($pipe))
			{
				fclose($pipe);
			}
		}
		proc_close($process);
	}
	unlink($audit);
}
