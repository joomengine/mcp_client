<?php
/** Public CLI startup contracts, including environment-only container configuration. */
$passed = 0;
$environment = getenv();
unset($environment['JOOMENGINE_MCP_URL'], $environment['JOOMENGINE_MCP_TOKEN']);
$binary = dirname(__DIR__) . '/bin/joomengine-mcp';

foreach ([
	['missing URL', ['JOOMENGINE_MCP_TOKEN' => 'private-cli-token'], [], 2],
	['missing token', ['JOOMENGINE_MCP_URL' => 'https://example.test'], [], 1],
	['unsafe environment URL', ['JOOMENGINE_MCP_URL' => 'http://example.test', 'JOOMENGINE_MCP_TOKEN' => 'private-cli-token'], [], 1],
	['credential in environment URL', ['JOOMENGINE_MCP_URL' => 'https://user:private-cli-token@example.test', 'JOOMENGINE_MCP_TOKEN' => 'private-cli-token'], [], 1],
	['explicit URL wins', ['JOOMENGINE_MCP_URL' => 'https://example.test', 'JOOMENGINE_MCP_TOKEN' => 'private-cli-token'], ['http://example.test'], 1],
	['extra arguments rejected', ['JOOMENGINE_MCP_URL' => 'https://example.test', 'JOOMENGINE_MCP_TOKEN' => 'private-cli-token'], ['https://example.test', 'private-cli-token'], 2],
] as [$name, $variables, $arguments, $expected])
{
	$process = proc_open(array_merge([PHP_BINARY, $binary, 'connect'], $arguments),
		[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, array_merge($environment, $variables));

	if (!is_resource($process))
	{
		throw new RuntimeException('Unable to start the public executable.');
	}

	fclose($pipes[0]);
	$output = stream_get_contents($pipes[1]);
	$errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	if (proc_close($process) !== $expected || $output !== '' || $errors === '' || str_contains($errors, 'private-cli-token'))
	{
		throw new RuntimeException('FAIL ' . $name);
	}

	$passed++;
	echo 'PASS ' . $name . PHP_EOL;
}

echo json_encode(['passed' => $passed, 'failed' => 0], JSON_THROW_ON_ERROR) . PHP_EOL;
