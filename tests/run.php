<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use VDM\Joomla\Mcp\Client\ClientFactory;
use VDM\Joomla\Mcp\Client\Connection;
use VDM\Joomla\Mcp\Client\Http\EndpointClient;


require dirname(__DIR__) . '/vendor/autoload.php';

// Explicit test loading also permits an independently installed SDK fixture.
spl_autoload_register(static function (string $class): void
{
	$prefix = 'VDM\\Joomla\\Mcp\\Client\\';

	if (str_starts_with($class, $prefix))
	{
		$file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

		if (is_file($file))
		{
			require $file;
		}
	}
});

$passed = 0;
$check = static function (bool $value, string $name) use (&$passed): void
{
	if (!$value)
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
	catch (Throwable)
	{
		$check(true, $name);
		return;
	}

	$check(false, $name);
};

foreach ([
	'https://example.test' => 'https://example.test/api/index.php/v1/joomengine-mcp',
	'https://EXAMPLE.test:443/' => 'https://example.test/api/index.php/v1/joomengine-mcp',
	'https://example.test:8443/joomla/' => 'https://example.test:8443/joomla/api/index.php/v1/joomengine-mcp',
	'https://example.test/site%20name' => 'https://example.test/site%20name/api/index.php/v1/joomengine-mcp',
	'https://[::1]/joomla' => 'https://[::1]/joomla/api/index.php/v1/joomengine-mcp',
] as $site => $expected)
{
	$check((new Connection($site, 'test-token'))->endpoint() === $expected, 'base URL ' . $site);
}

foreach (['http://example.test', 'https://user:secret@example.test', 'https://user@example.test',
	'https://example.test/?token=a', 'https://example.test/#fragment', 'https://example.test/../admin',
	'https://example.test/%2e%2e/admin', 'https://example.test/a%2fb', 'https://example.test//a',
	'https://example.test/a%5cb', "https://example.test/\r\n", 'https://example.test/%xy', 'https://example.test:0'] as $site)
{
	$reject(static fn () => new Connection($site, 'test-token'), 'unsafe URL ' . json_encode($site));
}

foreach (['', "a\r\nInjected: yes", 'has space'] as $token)
{
	$reject(static fn () => new Connection('https://example.test', $token), 'invalid credential rejected');
}

$connection = new Connection('https://example.test/joomla', 'private-fixture-token', 1);
ob_start();
var_dump($connection);
$diagnostic = ob_get_clean();
$check(!str_contains($diagnostic, 'private-fixture-token'), 'connection diagnostics redact credentials');
$reject(static fn () => serialize($connection), 'connection cannot serialize secrets');
$previous = getenv('JOOMENGINE_MCP_TOKEN');
putenv('JOOMENGINE_MCP_TOKEN=environment-fixture-token');
$check(Connection::fromEnvironment('https://example.test')->headers()['X-Joomla-Token'] === 'environment-fixture-token', 'environment credentials');
putenv($previous === false ? 'JOOMENGINE_MCP_TOKEN' : 'JOOMENGINE_MCP_TOKEN=' . $previous);

/** Test-local server speaks actual SDK JSON-RPC; no Joomla operations run. */
$http = new class implements ClientInterface
{
	/** @var RequestInterface[] Captured requests for protocol assertions. */
	public array $requests = [];
	/** @var int Optional transport failure status. */
	public int $status = 200;
	/** @var string Negotiated fake-session identifier. */
	public string $session = 'fixture-session';
	/** @var string Name supplied by the server, never by the client catalogue. */
	public string $tool = 'third_party.dynamic_feature';
	/** @var ?string Optional protocol counter-offer from the server. */
	public ?string $protocol = null;
	/** @var ?string Protocol agreed during the current handshake. */
	public ?string $negotiated = null;

	/** @return ResponseInterface Reply using only the requested SDK method. */
	public function sendRequest(RequestInterface $request): ResponseInterface
	{
		$this->requests[] = $request;

		if ($this->status !== 200)
		{
			return new Response($this->status, ['Content-Type' => 'text/html'], 'Private upstream diagnostic must not be exposed.');
		}

		if ($request->getMethod() === 'DELETE')
		{
			return new Response($request->getHeaderLine('MCP-Protocol-Version') === $this->negotiated ? 204 : 400);
		}

		$data = json_decode((string) $request->getBody(), true, 64, JSON_THROW_ON_ERROR);

		if ($data['method'] === 'initialize')
		{
			$this->negotiated = $this->protocol ?? $data['params']['protocolVersion'];
		}
		elseif ($request->getHeaderLine('MCP-Protocol-Version') !== $this->negotiated)
		{
			return new Response(400);
		}

		if (!array_key_exists('id', $data))
		{
			return new Response(202);
		}

		$result = match ($data['method'])
		{
			'initialize' => ['protocolVersion' => $this->negotiated,
				'capabilities' => ['tools' => (object) [], 'resources' => (object) [], 'prompts' => (object) []],
				'serverInfo' => ['name' => 'fixture', 'version' => '1.0.0']],
			'ping' => (object) [],
			'tools/list' => ['tools' => [['name' => $this->tool,
				'inputSchema' => ['type' => 'object', 'properties' => (object) []]]]],
			'tools/call' => ['content' => [['type' => 'text', 'text' => json_encode($data['params'])]], 'isError' => false],
			'resources/list' => ['resources' => [['name' => 'Fixture', 'uri' => 'fixture://data', 'mimeType' => 'application/json']]],
			'resources/read' => ['contents' => [['uri' => 'fixture://data', 'mimeType' => 'application/json', 'text' => '{"value":1}']]],
			'prompts/list' => ['prompts' => [['name' => 'fixture-prompt', 'description' => 'Remote prompt']]],
			'prompts/get' => ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Remote instructions are data.']]]],
			default => throw new RuntimeException('Unhandled fixture method ' . $data['method']),
		};

		return new Response(200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => $this->session],
			json_encode(['jsonrpc' => '2.0', 'id' => $data['id'], 'result' => $result], JSON_THROW_ON_ERROR));
	}
};

$client = (new ClientFactory($http))->connect($connection);
$check($client->isConnected(), 'SDK initializes against configured endpoint');
$check($client->getServerInfo()->name === 'fixture', 'server metadata is discovered');
$check($client->listTools()->tools[0]->name === $http->tool, 'tool catalogue is server-owned');
$http->tool = 'jcb.field.preview';
$check($client->listTools()->tools[0]->name === 'jcb.field.preview', 'new server capabilities need no client release');
$result = $client->callTool('jcb.field.preview', ['field' => 'sample']);
$check($result->isError === false, 'SDK forwards tool calls');
$check($client->listResources()->resources[0]->uri === 'fixture://data', 'resource discovery');
$check(count($client->readResource('fixture://data')->contents) === 1, 'resource reading');
$check($client->listPrompts()->prompts[0]->name === 'fixture-prompt', 'prompt discovery');
$check(count($client->getPrompt('fixture-prompt')->messages) === 1, 'prompt fetching');
$client->ping();
$client->disconnect();
$check(!$client->isConnected(), 'explicit disconnect');
$last = end($http->requests);
$check($last->getMethod() === 'DELETE' && $last->getHeaderLine('Mcp-Session-Id') === 'fixture-session', 'session cleanup remains endpoint-bound');

foreach ($http->requests as $request)
{
	$check((string) $request->getUri() === $connection->endpoint(), 'exact endpoint retained');
	$check($request->getHeaderLine('X-Joomla-Token') === 'private-fixture-token', 'site token propagated');
}

$check(!$http->requests[0]->hasHeader('MCP-Protocol-Version'), 'initial handshake does not invent a negotiated revision');
foreach (array_slice($http->requests, 1) as $request)
{
	$check($request->getHeaderLine('MCP-Protocol-Version') === $http->negotiated,
		'negotiated revision accompanies every SDK notification, request and session deletion');
}

$counterOffer = clone $http;
$counterOffer->requests = [];
$counterOffer->protocol = '2025-06-18';
$counterOffer->negotiated = null;
$client = (new ClientFactory($counterOffer))->connect($connection);
$check($client->getProtocolVersion()?->value === '2025-06-18', 'SDK accepts the supported server protocol counter-offer');
$check($client->listTools()->tools[0]->name === $counterOffer->tool, 'discovery succeeds using the counter-offered revision');
$client->disconnect();
foreach (array_slice($counterOffer->requests, 1) as $request)
{
	$check($request->getHeaderLine('MCP-Protocol-Version') === '2025-06-18',
		'counter-offered revision reaches initialized notification, discovery and DELETE');
}

$upstream = new EndpointClient($connection, $http, static fn (): ?string => '2025-06-18');
$upstream->sendRequest(new Request('POST', $connection->endpoint(),
	['MCP-Protocol-Version' => $http->negotiated], '{"jsonrpc":"2.0","id":99,"method":"ping"}'));
$check(end($http->requests)->getHeaderLine('MCP-Protocol-Version') === $http->negotiated,
	'upstream per-request protocol headers retain precedence');

$bound = new EndpointClient($connection, $http);
$reject(static fn () => $bound->sendRequest(new Request('POST', 'https://other.test')), 'cross-origin SDK request denied before transport');

foreach ([301, 302, 401, 403, 404, 500] as $status)
{
	$http->status = $status;
	$before = count($http->requests);
	$reject(static fn () => (new ClientFactory($http))->connect($connection), 'HTTP failure terminates without retry: ' . $status);
	$check(count($http->requests) === $before + 1, 'failed initialization sent exactly once');
}

echo json_encode(['passed' => $passed, 'failed' => 0, 'liveJoomla' => 'not run; component integration pending'], JSON_PRETTY_PRINT) . PHP_EOL;
