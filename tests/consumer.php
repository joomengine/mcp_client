<?php
/** Public API checks loaded exclusively from a fresh Composer consumer project. */
use Composer\InstalledVersions;
use VDM\Joomla\Mcp\Client\ClientFactory;
use VDM\Joomla\Mcp\Client\Connection;


$consumer = realpath($argv[1] ?? '');
$site = $argv[2] ?? '';
$audit = $argv[3] ?? '';
if ($consumer === false || !is_file($consumer . '/vendor/autoload.php'))
{
	throw new RuntimeException('A separately installed Composer consumer is required.');
}
require $consumer . '/vendor/autoload.php';

$passed = 0;
$check = static function (bool $condition, string $name) use (&$passed): void
{
	if (!$condition)
	{
		throw new RuntimeException('FAIL ' . $name);
	}
	$passed++;
	echo 'PASS ' . $name . PHP_EOL;
};
$lock = json_decode(file_get_contents($consumer . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$packages = array_column($lock['packages'], null, 'name');
$package = $packages['joomengine/mcp-client'] ?? [];
$version = InstalledVersions::getPrettyVersion('joomengine/mcp-client');
$reference = InstalledVersions::getReference('joomengine/mcp-client');
$installation = realpath(InstalledVersions::getInstallPath('joomengine/mcp-client') ?? '');
$check($installation === $consumer . '/vendor/joomengine/mcp-client', 'package installed inside the independent consumer');
$check($version === ($package['version'] ?? null) && $reference === ($package['source']['reference'] ?? null)
	&& preg_match('/\A[0-9a-f]{40}\z/D', $reference ?? '') === 1, 'installed version and source commit match the consumer lock');
$check(($package['source']['url'] ?? '') === 'https://github.com/joomengine/mcp_client.git'
	&& ($package['dist']['type'] ?? '') === 'zip'
	&& ($package['dist']['reference'] ?? null) === $reference
	&& ($package['dist']['url'] ?? '') === 'https://api.github.com/repos/joomengine/mcp_client/zipball/' . $reference,
	'lock records the public project source and distribution');
$installed = json_decode(file_get_contents($consumer . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$installedPackages = array_column($installed['packages'] ?? $installed, null, 'name');
$check(($installedPackages['joomengine/mcp-client']['installation-source'] ?? null) === 'dist', 'Composer installed the actual package distribution archive');
echo 'Installed joomengine/mcp-client ' . $version . ' at ' . $reference . PHP_EOL;

foreach ([Connection::class, ClientFactory::class, VDM\Joomla\Mcp\Client\Bridge\StdioBridge::class,
	VDM\Joomla\Mcp\Client\Http\CurlClient::class, VDM\Joomla\Mcp\Client\Http\MultiClient::class] as $class)
{
	$file = realpath((new ReflectionClass($class))->getFileName());
	$check(is_string($file) && str_starts_with($file, $installation . '/src/'), $class . ' autoloads from the installed package');
}
$proxy = $consumer . '/vendor/bin/joomengine-mcp';
$check(is_file($proxy) && is_executable($proxy) && !is_link($proxy)
	&& str_contains(file_get_contents($proxy), '_composer_autoload_path'), 'Composer generated the installed executable proxy');
$check(is_file($installation . '/LICENSE'), 'installed distribution includes its license');

$client = (new ClientFactory())->connect(new Connection($site, 'consumer-fixture-token', 5));
try
{
	$check($client->isConnected() && $client->getServerInfo()->name === 'packagist-consumer-fixture', 'SDK initializes over certificate-verified HTTPS');
	$check($client->getInstructions() === 'Fixture capabilities are discovered from the remote server.', 'SDK exposes server instructions');
	$tools = $client->listTools();
	$check($tools->tools[0]->name === 'extension.dynamic_echo' && $tools->nextCursor === 'opaque-second-page', 'SDK discovers arbitrary tools and opaque pagination');
	$second = $client->listTools($tools->nextCursor);
	$check($second->tools[0]->name === 'extension.dynamic_error' && $second->nextCursor === null, 'SDK reaches the final tool page');
	$result = $client->callTool($tools->tools[0]->name, ['nested' => ['value' => 7]]);
	$check(!$result->isError && $result->structuredContent['arguments']['nested']['value'] === 7, 'SDK forwards arguments and structured tool results');
	$check($client->callTool('extension.dynamic_error')->isError, 'SDK preserves remote tool error results');
	$resources = $client->listResources();
	$check($resources->resources[0]->uri === 'fixture://data/first' && $resources->nextCursor === 'opaque-second-page', 'SDK discovers resources and their cursor');
	$check($client->listResources($resources->nextCursor)->resources[0]->uri === 'fixture://data/second', 'SDK requests the second resource page');
	$check($client->listResourceTemplates()->resourceTemplates[0]->uriTemplate === 'fixture://data/{id}', 'SDK discovers resource templates');
	$content = $client->readResource('fixture://data/second')->contents[0];
	$check($content->uri === 'fixture://data/second' && json_decode($content->text, true)['value'] === 7, 'SDK reads the requested resource');
	$prompts = $client->listPrompts();
	$check($prompts->prompts[0]->name === 'dynamic-first' && $prompts->nextCursor === 'opaque-second-page', 'SDK discovers prompts and their cursor');
	$check($client->listPrompts($prompts->nextCursor)->prompts[0]->name === 'dynamic-second', 'SDK requests the second prompt page');
	$check($client->getPrompt('dynamic-second', ['topic' => 'consumer'])->messages[0]->content->text === 'Remote instructions for consumer.', 'SDK retrieves prompt arguments and content');
	$client->ping();
	$check(true, 'SDK ping completes');
}
finally
{
	$client->disconnect();
}
$check(!$client->isConnected(), 'SDK disconnect ends the session');

foreach (['consumer-invalid-token' => 'authentication failure', 'consumer-redirect-token' => 'redirect'] as $token => $name)
{
	$failure = null;
	try
	{
		$unexpected = (new ClientFactory())->connect(new Connection($site, $token, 5));
		$unexpected->disconnect();
	}
	catch (Throwable $error)
	{
		$failure = $error;
	}
	$check($failure !== null && !str_contains($failure->getMessage(), $token)
		&& !str_contains($failure->getMessage(), 'private-fixture-diagnostic'), 'SDK rejects ' . $name . ' with a safe diagnostic');
}

// Execute Composer's proxy, never the repository or installed-package binary directly.
$environment = getenv();
$environment['JOOMENGINE_MCP_URL'] = $site;
$environment['JOOMENGINE_MCP_TOKEN'] = 'consumer-fixture-token';
$environment['JOOMENGINE_MCP_CONFIG_DIR'] = $consumer . '/private-config';
$process = proc_open([PHP_BINARY, '-d', 'curl.cainfo=' . ini_get('curl.cainfo'), $proxy, 'connect'],
	[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $consumer, $environment);
$check(is_resource($process), 'installed Composer executable starts from the consumer directory');
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$buffer = '';
$read = static function () use ($pipes, &$buffer): array
{
	$deadline = microtime(true) + 8;
	while (($end = strpos($buffer, "\n")) === false)
	{
		$ready = [$pipes[1]];
		$write = $except = [];
		if (microtime(true) >= $deadline || stream_select($ready, $write, $except, 0, 100000) === false)
		{
			throw new RuntimeException('Installed executable response timed out.');
		}
		if ($ready !== [])
		{
			$chunk = fread($pipes[1], 8192);
			if ($chunk === false || ($chunk === '' && feof($pipes[1])))
			{
				throw new RuntimeException('Installed executable ended before its reply.');
			}
			$buffer .= $chunk;
		}
	}
	$line = substr($buffer, 0, $end);
	$buffer = substr($buffer, $end + 1);
	return json_decode($line, true, 64, JSON_THROW_ON_ERROR);
};
$send = static function (array $message) use ($pipes): void
{
	$data = json_encode($message, JSON_THROW_ON_ERROR) . "\n";
	if (fwrite($pipes[0], $data) !== strlen($data) || !fflush($pipes[0]))
	{
		throw new RuntimeException('Unable to send a complete request to the installed executable.');
	}
};
$request = static function (int $id, string $method, array $params = []) use ($send, $read, $check): array
{
	$message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
	if ($params !== [])
	{
		$message['params'] = $params;
	}
	$send($message);
	$reply = $read();
	$check(($reply['jsonrpc'] ?? '') === '2.0' && ($reply['id'] ?? null) === $id, 'stdio correlates ' . $method . ' reply');
	return $reply;
};
try
{
	$check(($request(1, 'tools/list')['error']['code'] ?? null) === -32002, 'stdio requires initialization before discovery');
	$initialize = $request(2, 'initialize', ['protocolVersion' => '2025-06-18',
		'capabilities' => (object) [], 'clientInfo' => ['name' => 'packagist-consumer', 'version' => '1.0.0']]);
	$check(($initialize['result']['serverInfo']['name'] ?? '') === 'packagist-consumer-fixture'
		&& ($initialize['result']['protocolVersion'] ?? '') === '2025-06-18', 'stdio preserves initialization and protocol negotiation');
	$send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
	$tools = $request(3, 'tools/list')['result'];
	$check($tools['tools'][0]['name'] === 'extension.dynamic_echo' && $tools['nextCursor'] === 'opaque-second-page', 'stdio discovers remote tools and pagination');
	$check($request(4, 'tools/list', ['cursor' => $tools['nextCursor']])['result']['tools'][0]['name'] === 'extension.dynamic_error', 'stdio forwards an opaque cursor');
	$result = $request(5, 'tools/call', ['name' => $tools['tools'][0]['name'], 'arguments' => ['nested' => ['value' => 9]]]);
	$check($result['result']['structuredContent']['arguments']['nested']['value'] === 9, 'stdio forwards tool arguments and structured content');
	$check($request(6, 'resources/list')['result']['resources'][0]['uri'] === 'fixture://data/first', 'stdio forwards resource discovery');
	$check($request(7, 'resources/templates/list')['result']['resourceTemplates'][0]['uriTemplate'] === 'fixture://data/{id}', 'stdio forwards resource templates');
	$check($request(8, 'resources/read', ['uri' => 'fixture://data/first'])['result']['contents'][0]['uri'] === 'fixture://data/first', 'stdio forwards resource reads');
	$check($request(9, 'prompts/list')['result']['prompts'][0]['name'] === 'dynamic-first', 'stdio forwards prompt discovery');
	$check($request(10, 'prompts/get', ['name' => 'dynamic-first', 'arguments' => ['topic' => 'stdio']])['result']['messages'][0]['content']['text'] === 'Remote instructions for stdio.', 'stdio forwards prompt arguments and content');
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], true);
	stream_set_timeout($pipes[1], 8);
	$remaining = $buffer . stream_get_contents($pipes[1]);
	$errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	$process = null;
	$check($status === 0 && $remaining === '' && $errors === '', 'installed executable exits cleanly with protocol-only stdout');
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
}

$requests = array_map(static fn (string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR), file($audit, FILE_IGNORE_NEW_LINES));
$check(count(array_filter($requests, static fn (array $request): bool => $request['path'] !== '/nested/api/index.php/v1/joomengine-mcp')) === 0, 'all requests retain the installation subdirectory and never follow redirects');
$check(count(array_filter($requests, static fn (array $request): bool => !$request['authorized'])) === 2, 'authentication and redirect failures are attempted once without retry');
$sessions = array_values(array_filter($requests, static fn (array $request): bool => $request['http'] === 'DELETE'));
$check(count($sessions) === 2 && count(array_unique(array_column($sessions, 'session'))) === 2, 'SDK and executable each delete their own isolated session');
$check(count(array_filter($requests, static fn (array $request): bool => $request['authorized']
	&& ($request['method'] ?? null) !== 'initialize' && $request['session'] === null)) === 0, 'every authenticated request after initialization carries its session');
$check(count(array_filter($requests, static fn (array $request): bool => $request['authorized']
	&& $request['http'] === 'POST' && ($request['method'] ?? null) !== 'initialize'
	&& $request['protocol'] !== null && $request['protocol'] !== $request['negotiated'])) === 0, 'supplied protocol headers agree with the negotiated session');
$stdioSession = $sessions[1]['session'];
$check(count(array_filter($requests, static fn (array $request): bool => $request['session'] === $stdioSession
	&& $request['http'] === 'POST' && $request['protocol'] !== '2025-06-18')) === 0, 'stdio requests always carry the negotiated revision');
$legacyRequests = count(array_filter($requests, static fn (array $request): bool => $request['session'] !== null
	&& $request['session'] !== $stdioSession && $request['protocol'] === null));
echo 'Published SDK requests using component-compatible missing-header handling: ' . $legacyRequests . PHP_EOL;

$evidence = ['package' => 'joomengine/mcp-client', 'version' => $version, 'reference' => $reference,
	'php' => PHP_VERSION, 'checks' => $passed, 'legacyProtocolHeaderRequests' => $legacyRequests,
	'fixture' => 'loopback HTTPS protocol fixture with component-compatible legacy header handling; no installed Joomla site'];
$report = json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
echo $report;
$reportPath = getenv('PACKAGIST_REPORT_PATH');
if (is_string($reportPath) && $reportPath !== '')
{
	$directory = dirname($reportPath);
	if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory))
	{
		throw new RuntimeException('Cannot create the consumer evidence directory.');
	}
	if (file_put_contents($reportPath, $report) === false)
	{
		throw new RuntimeException('Cannot save the consumer evidence report.');
	}
}
