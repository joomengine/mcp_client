<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
use VDM\Joomla\Mcp\Client\Configuration\SiteStore;


require dirname(__DIR__) . '/vendor/autoload.php';

if (($argv[1] ?? '') === '--writer')
{
	$writer = new SiteStore($argv[2]);

	for ($index = 0; $index < 12; $index++)
	{
		$writer->save($argv[3] . $index, 'https://example.test/' . $argv[3], 'fixture-' . $argv[3]);
	}

	exit(0);
}

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
$root = sys_get_temp_dir() . '/joomengine-config-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
$cleanup = static function (string $path) use (&$cleanup): void
{
	if (is_link($path) || !is_dir($path))
	{
		unlink($path);
		return;
	}

	foreach (new FilesystemIterator($path) as $entry)
	{
		$cleanup($entry->getPathname());
	}

	rmdir($path);
};
$previousXdg = getenv('XDG_CONFIG_HOME');
$previousHome = getenv('HOME');

try
{
	$directory = $root . '/sites';
	$store = new SiteStore($directory);
	$check($store->sites() === [], 'empty configuration lists no sites');
	$store->save('Production', 'https://EXAMPLE.test:443/joomla/', 'secret-one');
	$store->save('Staging', 'https://staging.test/site', 'secret-two');
	$check($store->sites() === ['Production' => 'https://example.test/joomla', 'Staging' => 'https://staging.test/site'], 'site list is canonical and contains no credentials');
	$check((fileperms($directory) & 07777) === 0700, 'configuration directory is private');
	$check((fileperms($directory . '/sites.json') & 07777) === 0600, 'configuration file is private');
	$check((fileperms($directory . '/.lock') & 07777) === 0600, 'configuration lock is private');
	$check((new SiteStore($directory))->connection('Production')->headers()['X-Joomla-Token'] === 'secret-one', 'named credentials survive a new store instance');
	$check($store->connection('Staging')->headers()['X-Joomla-Token'] === 'secret-two', 'separate sites retain separate credentials');
	$store->save('Production', 'https://example.test/joomla', 'replacement-token');
	$check($store->connection('Production')->headers()['X-Joomla-Token'] === 'replacement-token', 'explicit credential replacement');
	$store->remove('Staging');
	$store->remove('Staging');
	$check(count($store->sites()) === 1, 'removal affects only its named site and is idempotent');
	$reject(static fn () => $store->connection('Missing'), 'missing named site fails clearly');
	$reject(static fn () => serialize($store), 'credential store is not serializable');
	ob_start();
	var_dump($store);
	$diagnostic = ob_get_clean();
	$check(!str_contains($diagnostic, 'replacement-token'), 'store diagnostics do not expose credentials');

	foreach (['../outside', '', '-option', "line\nbreak", '1numeric', str_repeat('a', 65)] as $name)
	{
		$reject(static fn () => $store->save($name, 'https://example.test', 'token'), 'unsafe site name rejected');
	}

	$reject(static fn () => $store->save('Unsafe', 'http://example.test', 'token'), 'non-TLS site is never persisted');
	$reject(static fn () => $store->save('Unsafe', 'https://user:password@example.test', 'token'), 'URL credentials are never persisted');
	$reject(static fn () => $store->save('Unsafe', 'https://example.test', 'line break'), 'invalid token is never persisted');
	$reject(static fn () => $store->save('Unsafe', 'https://example.test', "invalid-\xff"), 'invalid UTF-8 token cannot corrupt configuration');
	$check(count($store->sites()) === 1, 'rejected input leaves configured sites intact');

	chmod($directory, 0755);
	$reject(static fn () => $store->sites(), 'shared configuration directory is refused');
	chmod($directory, 0700);
	chmod($directory . '/sites.json', 0640);
	$reject(static fn () => $store->connection('Production'), 'shared credential file is refused');
	chmod($directory . '/sites.json', 0600);
	chmod($directory . '/.lock', 0640);
	$reject(static fn () => $store->sites(), 'shared lock file is refused');
	chmod($directory . '/.lock', 0600);
	$sharedParent = $root . '/shared';
	mkdir($sharedParent, 0700);
	chmod($sharedParent, 0777);
	$reject(static fn () => (new SiteStore($sharedParent . '/private'))->sites(), 'shared writable ancestor is refused');
	chmod($sharedParent, 0700);

	$linkDirectory = $root . '/linked';
	symlink($directory, $linkDirectory);
	$reject(static fn () => (new SiteStore($linkDirectory))->sites(), 'symlinked configuration directory is refused');
	$reject(static fn () => (new SiteStore($linkDirectory . '/nested'))->sites(), 'symlinked parent directory is refused');
	unlink($linkDirectory);
	$realFile = $root . '/original.json';
	rename($directory . '/sites.json', $realFile);
	symlink($realFile, $directory . '/sites.json');
	$reject(static fn () => $store->sites(), 'symlinked credential file is refused');
	unlink($directory . '/sites.json');
	link($realFile, $directory . '/sites.json');
	$reject(static fn () => $store->sites(), 'hardlinked credential file is refused');
	unlink($directory . '/sites.json');
	rename($realFile, $directory . '/sites.json');

	$lockTarget = $root . '/original-lock';
	rename($directory . '/.lock', $lockTarget);
	symlink($lockTarget, $directory . '/.lock');
	$reject(static fn () => $store->sites(), 'symlinked lock file is refused');
	unlink($directory . '/.lock');
	rename($lockTarget, $directory . '/.lock');

	if (posix_geteuid() === 0 && @chown($directory . '/sites.json', 65534))
	{
		$reject(static fn () => $store->sites(), 'foreign-owned credential file is refused');
		chown($directory . '/sites.json', 0);
		chown($directory, 65534);
		$reject(static fn () => $store->sites(), 'foreign-owned configuration directory is refused');
		chown($directory, 0);
	}
	else
	{
		echo 'SKIP foreign ownership fixtures: this process cannot assign another UID.' . PHP_EOL;
	}

	$valid = file_get_contents($directory . '/sites.json');
	file_put_contents($directory . '/sites.json', '{"version":1,"sites":{"Production":{"site":"http://unsafe.test","token":"secret-one"}}}');
	$reject(static fn () => $store->save('Additional', 'https://example.test', 'another-token'), 'invalid stored site prevents modification');
	$check(str_contains(file_get_contents($directory . '/sites.json'), 'http://unsafe.test'), 'corrupt configuration is preserved for recovery');
	file_put_contents($directory . '/sites.json', str_repeat('x', 1048577));
	$reject(static fn () => $store->sites(), 'oversized configuration is refused');
	file_put_contents($directory . '/sites.json', $valid);
	$check(glob($directory . '/.sites-*.tmp') === [], 'atomic updates leave no temporary credential files');

	foreach (['relative/path', '/', $root . '/../elsewhere', $root . '//double'] as $path)
	{
		$reject(static fn () => new SiteStore($path), 'ambiguous configuration path is refused');
	}

	putenv('XDG_CONFIG_HOME=' . $root . '/xdg');
	(new SiteStore())->save('Default', 'https://default.test', 'xdg-token');
	$check(is_file($root . '/xdg/joomengine-mcp/sites.json'), 'XDG_CONFIG_HOME determines default configuration directory');
	putenv('XDG_CONFIG_HOME');
	putenv('HOME=' . $root . '/home');
	(new SiteStore())->save('Default', 'https://default.test', 'home-token');
	$check(is_file($root . '/home/.config/joomengine-mcp/sites.json'), 'HOME fallback determines default configuration directory');
	putenv('XDG_CONFIG_HOME=relative');
	$reject(static fn () => new SiteStore(), 'relative XDG directory is refused');

	$held = fopen($directory . '/.lock', 'r+b');
	flock($held, LOCK_EX);
	$start = hrtime(true);
	$reject(static fn () => $store->sites(), 'configuration lock contention terminates with an error');
	$check((hrtime(true) - $start) / 1000000000 < 4, 'configuration lock wait is bounded');
	flock($held, LOCK_UN);
	fclose($held);

	$children = [];
	$concurrentDirectory = $root . '/concurrent';

	foreach (['Alpha', 'Beta'] as $prefix)
	{
		$process = proc_open([PHP_BINARY, __FILE__, '--writer', $concurrentDirectory, $prefix],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

		if (!is_resource($process))
		{
			throw new RuntimeException('Unable to start concurrent configuration fixture.');
		}

		fclose($pipes[0]);
		$children[] = [$process, $pipes];
	}

	foreach ($children as [$process, $pipes])
	{
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$check(proc_close($process) === 0 && $output === '' && $error === '', 'concurrent configuration writer completes without credential output');
	}

	$check(count((new SiteStore($concurrentDirectory))->sites()) === 24, 'concurrent independent writers preserve all named sites');
}
finally
{
	putenv($previousXdg === false ? 'XDG_CONFIG_HOME' : 'XDG_CONFIG_HOME=' . $previousXdg);
	putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
	$cleanup($root);
}

echo json_encode(['passed' => $passed, 'failed' => 0], JSON_PRETTY_PRINT) . PHP_EOL;
