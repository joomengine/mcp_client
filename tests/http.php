<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use VDM\Joomla\Mcp\Client\Connection;
use VDM\Joomla\Mcp\Client\Http\MultiClient;


require dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$check = static function (bool $valid, string $name) use (&$passed): void
{
	if (!$valid)
	{
		throw new RuntimeException('FAIL ' . $name);
	}

	$passed++;
	echo 'PASS ' . $name . PHP_EOL;
};
$reject = static function (callable $operation, string $name) use ($check): void
{
	try
	{
		$operation();
	}
	catch (RuntimeException $error)
	{
		$check(!str_contains($error->getMessage(), 'private-fixture-token'), $name);
		return;
	}

	$check(false, $name);
};
$collect = static function (MultiClient $client, float $seconds = 4): array
{
	$result = [];
	$deadline = microtime(true) + $seconds;

	while ($client->count() > 0 && microtime(true) < $deadline)
	{
		foreach ($client->poll() as $key => $exchange)
		{
			$result[$key] = $exchange;
		}

		if ($client->count() > 0)
		{
			usleep(1000);
		}
	}

	if ($client->count() !== 0)
	{
		throw new RuntimeException('HTTP fixture exchanges failed to finish within the test deadline.');
	}

	return $result;
};

if (($argv[1] ?? '') === '--untrusted')
{
	$client = new MultiClient(new Connection($argv[2], 'private-fixture-token', 1, 1024));
	$client->start('tls', '{}');
	$result = $collect($client);
	$check($result['tls']['error'] && $result['tls']['response'] === null, 'untrusted server certificate rejected');
	exit(0);
}

$site = $argv[1] ?? '';
$client = new MultiClient(new Connection($site, 'private-fixture-token', 1, 1024));
$check($client->count() === 0 && $client->poll() === [], 'new scheduler has no pending work');

foreach ([
	['Authorization' => 'Bearer private-fixture-token'], ['X-Joomla-Token' => 'replacement'],
	['Host' => 'other.example'], ['Cookie' => 'credential'], ['Proxy-Authorization' => 'credential'],
	['Mcp-Session-Id' => "unsafe\r\nHeader: value"], ['Mcp-Session-Id' => ['bad-type']],
	['Accept' => 'application/json', 'accept' => 'text/plain'], ['Accept' => str_repeat('a', 65536)],
] as $headers)
{
	$reject(static fn () => $client->start('unsafe', '{}', $headers), 'unsafe request headers rejected before sending');
}
$reject(static fn () => $client->start('method', '', [], 'GET'), 'unsupported HTTP method rejected');
$reject(static fn () => $client->start('large', str_repeat('x', 1025)), 'request byte limit enforced');
$check($client->count() === 0, 'rejected exchanges create no pending handles');

$client->start('slow', '{"mode":"slow"}');
$client->start('fast', '{"mode":"fast"}', ['Mcp-Session-Id' => 'session-A', 'MCP-Protocol-Version' => '2025-03-26']);
$reject(static fn () => $client->start('fast', '{}'), 'duplicate pending key rejected');
$check($client->count() === 2, 'requests can run concurrently');
$before = microtime(true);
$initial = $client->poll();
$check(microtime(true) - $before < 0.2, 'poll does not wait for a slow response');
$result = $initial + $collect($client);
$check(array_keys($result) === ['fast', 'slow'], 'fast request finishes while slow request remains active');
$data = json_decode((string) $result['fast']['response']->getBody(), true, 64, JSON_THROW_ON_ERROR);
$check(!$result['fast']['error'] && $data['mode'] === 'fast', 'real TLS response received');
$check($data['path'] === '/nested/api/index.php/v1/joomengine-mcp', 'installation subdirectory preserved');
$check($data['token'] === 'private-fixture-token' && $data['session'] === 'session-A'
	&& $data['protocol'] === '2025-03-26', 'configured token and protocol headers reach only the endpoint');
$check(json_decode((string) $result['slow']['response']->getBody(), true)['mode'] === 'slow', 'concurrent response bodies remain isolated');
$check($client->poll() === [], 'completed exchanges are returned once');

foreach (['overflow', 'compressed', 'headers', 'timeout', 'drop'] as $mode)
{
	$client->start($mode, json_encode(['mode' => $mode], JSON_THROW_ON_ERROR));
}
$result = $collect($client);
foreach (['overflow', 'compressed', 'headers', 'timeout', 'drop'] as $mode)
{
	$check($result[$mode]['error'] && $result[$mode]['response'] === null, 'bounded transport rejects ' . $mode);
}

$client->start('redirect', '{"mode":"redirect"}');
$client->start('unauthorized', '{"mode":"unauthorized"}');
$client->start('delete', '', ['Mcp-Session-Id' => 'session-A'], 'DELETE');
$result = $collect($client);
$check(!$result['redirect']['error'] && $result['redirect']['response']->getStatusCode() === 302, 'redirect returned without following it');
$check(!$result['unauthorized']['error'] && $result['unauthorized']['response']->getStatusCode() === 401, 'HTTP authentication error remains a response');
$check(!$result['delete']['error'] && $result['delete']['response']->getStatusCode() === 204, 'session DELETE completes over TLS');
$client->start('stats', '{"mode":"stats"}');
$stats = json_decode((string) $collect($client)['stats']['response']->getBody(), true, 64, JSON_THROW_ON_ERROR);
$check($stats['redirected'] === 0, 'no credentials or request sent to redirect target');
$check($stats['counts']['drop'] === 1 && $stats['counts']['timeout'] === 1, 'uncertain request outcomes are not retried');

$proxy = getenv('HTTPS_PROXY');
putenv('HTTPS_PROXY=http://127.0.0.1:9');
try
{
	$client->start('proxy', '{}');
	$check(!$collect($client)['proxy']['error'], 'ambient proxy cannot capture credentials');
}
finally
{
	putenv($proxy === false ? 'HTTPS_PROXY' : 'HTTPS_PROXY=' . $proxy);
}

$wrongHost = new MultiClient(new Connection(str_replace('127.0.0.1', 'localhost', $site), 'private-fixture-token', 1, 1024));
$wrongHost->start('hostname', '{}');
$check($collect($wrongHost)['hostname']['error'], 'certificate hostname verification remains enabled');
$wrongHost->close();
$process = proc_open([PHP_BINARY, '-d', 'curl.cainfo=' . $argv[2], __FILE__, '--untrusted', $site], [STDIN, STDOUT, STDERR], $pipes);
$check(is_resource($process) && proc_close($process) === 0, 'certificate trust verification remains enabled');

ob_start();
var_dump($client);
$check(!str_contains(ob_get_clean(), 'private-fixture-token'), 'scheduler diagnostics redact credentials');
try
{
	serialize($client);
	$check(false, 'scheduler serialization rejected');
}
catch (LogicException)
{
	$check(true, 'scheduler serialization rejected');
}
for ($index = 0; $index < 32; $index++)
{
	$client->start('bound-' . $index, '{}');
}
$reject(static fn () => $client->start('over-bound', '{}'), 'concurrent handle limit enforced');
$client->close();
$client->close();
$check($client->count() === 0 && $client->poll() === [], 'close cancels all pending work and is idempotent');
$reject(static fn () => $client->start('closed', '{}'), 'closed scheduler cannot send further requests');

// Exercise the actual distributable executable over the same real TLS endpoint.
$environment = getenv();
$environment['JOOMENGINE_MCP_TOKEN'] = 'private-fixture-token';
$binary = dirname(__DIR__) . '/bin/joomengine-mcp';
$bridge = proc_open([PHP_BINARY, '-d', 'curl.cainfo=' . ini_get('curl.cainfo'), $binary, 'connect', $site],
	[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $streams, null, $environment);
$check(is_resource($bridge), 'packaged executable starts with token outside arguments');
$read = static function () use ($streams): object
{
	$ready = [$streams[1]];
	$write = null;
	$except = null;

	if (stream_select($ready, $write, $except, 5) !== 1)
	{
		throw new RuntimeException('Remote executable did not emit a protocol reply.');
	}

	$line = fgets($streams[1]);

	if (!is_string($line))
	{
		throw new RuntimeException('Remote executable closed stdout before replying.');
	}

	return json_decode($line, false, 64, JSON_THROW_ON_ERROR);
};
try
{
	fwrite($streams[0], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
		'protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
	]], JSON_THROW_ON_ERROR) . "\n");
	$initialize = $read();
	$check(($initialize->result->serverInfo->name ?? '') === 'loopback-tls-fixture', 'executable initializes across verified TLS');
	fwrite($streams[0], "{\"jsonrpc\":\"2.0\",\"method\":\"notifications/initialized\"}\n");
	fwrite($streams[0], "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/list\"}\n");
	$tools = $read();
	$check(($tools->result->tools[0]->name ?? '') === 'remote_fixture', 'executable discovers the remote catalogue');
	fclose($streams[0]);
	$stdout = stream_get_contents($streams[1]);
	$stderr = stream_get_contents($streams[2]);
	fclose($streams[1]);
	fclose($streams[2]);
	$check(proc_close($bridge) === 0 && trim($stdout) === '' && trim($stderr) === '', 'executable closes cleanly with protocol-only stdout');
	$bridge = null;
}
finally
{
	if (is_resource($bridge))
	{
		proc_terminate($bridge);
		foreach ($streams as $stream)
		{
			if (is_resource($stream))
			{
				fclose($stream);
			}
		}
		proc_close($bridge);
	}
}

echo $passed . ' real TLS transport checks passed.' . PHP_EOL;
