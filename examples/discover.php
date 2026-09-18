<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @created    18 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use VDM\Joomla\Mcp\Client\ClientFactory;
use VDM\Joomla\Mcp\Client\Connection;


if (PHP_SAPI !== 'cli' || count($argv) !== 2)
{
	fwrite(STDERR, 'Usage: php examples/discover.php HTTPS_JOOMLA_BASE_URL' . PHP_EOL);
	exit(2);
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload))
{
	fwrite(STDERR, 'Run composer install in this checkout first.' . PHP_EOL);
	exit(2);
}

require $autoload;
$client = null;
$status = 0;

try
{
	$client = (new ClientFactory())->connect(Connection::fromEnvironment($argv[1]));
	$names = [];
	$cursor = null;
	$seen = [];

	do
	{
		$result = $client->listTools($cursor);

		foreach ($result->tools as $tool)
		{
			$names[] = $tool->name;
		}

		$cursor = $result->nextCursor;

		if ($cursor !== null && (isset($seen[$cursor]) || count($seen) >= 100))
		{
			throw new RuntimeException('Discovery pagination did not terminate within its bound.');
		}

		if ($cursor !== null)
		{
			$seen[$cursor] = true;
		}
	}
	while ($cursor !== null);

	echo json_encode(['server' => $client->getServerInfo()->name, 'tools' => $names], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}
catch (Throwable)
{
	fwrite(STDERR, 'MCP discovery failed. Check the HTTPS installation URL, JOOMENGINE_MCP_TOKEN, server installation and Joomla permissions.' . PHP_EOL);
	$status = 1;
}
finally
{
	if ($client !== null)
	{
		$client->disconnect();
	}
}

exit($status);
