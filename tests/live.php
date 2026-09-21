<?php
/** Live installed-server acceptance; missing configuration is a failure, never a skipped pass. */
require dirname(__DIR__) . '/vendor/autoload.php';

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use VDM\Joomla\Mcp\Client\ClientFactory;
use VDM\Joomla\Mcp\Client\Connection;

$site = getenv('JOOMENGINE_MCP_URL');
$token = getenv('JOOMENGINE_MCP_TOKEN');
if (!is_string($site) || $site === '' || !is_string($token) || $token === '')
{
	fwrite(STDERR, "Set JOOMENGINE_MCP_URL and JOOMENGINE_MCP_TOKEN for an installed disposable acceptance site.\n");
	exit(2);
}
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
$discover = static function (Client $client, string $method, string $property): array
{
	$cursor = null;
	$seen = [];
	$items = [];
	do
	{
		$page = $client->$method($cursor);
		$items = array_merge($items, $page->$property);
		$cursor = $page->nextCursor;
		if ($cursor !== null)
		{
			if (isset($seen[$cursor]) || count($seen) >= 100)
			{
				throw new RuntimeException('Discovery returned a repeated or excessive pagination cursor.');
			}
			$seen[$cursor] = true;
		}
	}
	while ($cursor !== null);
	return $items;
};
$inspect = static function (Client $client, string $track) use ($check, $discover): array
{
	$check($client->isConnected() && $client->getServerInfo() !== null && $client->getProtocolVersion() !== null, $track . ' negotiates real server metadata and protocol');
	$client->ping();
	$tools = $discover($client, 'listTools', 'tools');
	$check(count($tools) > 0, $track . ' discovers the installed tool catalogue');
	$readTool = null;
	foreach ($tools as $tool)
	{
		if ($tool->annotations?->readOnlyHint === true && ($tool->inputSchema['required'] ?? []) === [])
		{
			$readTool = $tool;
			break;
		}
	}
	$check($readTool !== null, $track . ' discovers a callable read-only tool');
	$result = $client->callTool($readTool->name);
	$check(!$result->isError, $track . ' executes a discovered read-only operation');
	$resources = $discover($client, 'listResources', 'resources');
	$templates = $discover($client, 'listResourceTemplates', 'resourceTemplates');
	$prompts = $discover($client, 'listPrompts', 'prompts');
	$check(is_array($resources) && is_array($templates) && is_array($prompts), $track . ' discovers resources, templates and prompts');
	if ($resources !== [])
	{
		$check(count($client->readResource($resources[0]->uri)->contents) > 0, $track . ' reads a discovered resource');
	}
	foreach ($prompts as $prompt)
	{
		$required = array_filter($prompt->arguments ?? [], static fn ($argument): bool => $argument->required === true);
		if ($required === [])
		{
			$check(count($client->getPrompt($prompt->name)->messages) > 0, $track . ' renders a discovered prompt');
			break;
		}
	}
	$names = array_map(static fn ($tool): string => $tool->name, $tools);
	sort($names);
	return $names;
};
$http = null;
$bridge = null;
$status = 0;
try
{
	$http = (new ClientFactory())->connect(new Connection($site, $token));
	$names = $inspect($http, 'HTTPS library');
	$bridge = Client::builder()->setClientInfo('client-acceptance', '1')->setInitTimeout(30)->setRequestTimeout(30)->setMaxRetries(0)->build();
	$arguments = [];
	$ca = ini_get('curl.cainfo');
	if (is_string($ca) && $ca !== '')
	{
		$arguments = ['-d', 'curl.cainfo=' . $ca];
	}
	$arguments = array_merge($arguments, [dirname(__DIR__) . '/bin/joomengine-mcp', 'connect', $site]);
	$bridge->connect(new StdioTransport(PHP_BINARY, $arguments, dirname(__DIR__), maxBufferSize: 16777216));
	$bridgeNames = $inspect($bridge, 'Remote stdio');
	$check($names === $bridgeNames, 'HTTP and remote stdio expose identical authenticated capabilities');
	$rejected = false;
	try
	{
		$bad = (new ClientFactory())->connect(new Connection($site, 'invalid-fixture-token'));
		$bad->disconnect();
	}
	catch (Throwable)
	{
		$rejected = true;
	}
	$check($rejected, 'invalid Joomla token is rejected');
	echo json_encode(['passed' => $passed, 'failed' => 0, 'liveJoomla' => true, 'tools' => count($names)], JSON_THROW_ON_ERROR) . PHP_EOL;
}
catch (Throwable $error)
{
	// Upstream errors can retain request headers: never print exception traces or credentials.
	fwrite(STDERR, "Installed-server client acceptance failed; inspect the fixture's redacted evidence.\n");
	$status = 1;
}
finally
{
	$bridge?->disconnect();
	$http?->disconnect();
}

exit($status);
