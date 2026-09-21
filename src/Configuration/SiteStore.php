<?php
/**
 * @package    JoomEngine.Mcp.Client
 * @created    21 September 2026
 * @author     Llewellyn van der Merwe <https://dev.vdm.io>
 * @copyright  Copyright (C) 2026 Vast Development Method. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
namespace VDM\Joomla\Mcp\Client\Configuration;


use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SensitiveParameter;
use VDM\Joomla\Mcp\Client\Connection;


/**
 * Explicitly configured site credentials in a private POSIX directory.
 *
 * Files contain plaintext credentials and rely on operating-system ownership
 * and permissions. They are never placed in a project or sent to diagnostics.
 * Independent processes use one lock and atomically replace the configuration.
 *
 * @since 0.1.0
 */
final class SiteStore
{
	/** @var int Maximum encoded configuration size. @since 0.1.0 */
	private const MAXIMUM_BYTES = 1048576;
	/** @var string Absolute, private configuration directory. @since 0.1.0 */
	private string $directory;
	/** @var int Effective process owner. @since 0.1.0 */
	private int $owner;

	/**
	 * Select a private directory without silently repairing unsafe permissions.
	 *
	 * @param string|null $directory Override the XDG or home configuration path.
	 * @throws RuntimeException When POSIX ownership cannot be checked.
	 * @throws InvalidArgumentException For an ambiguous configuration path.
	 * @since 0.1.0
	 */
	public function __construct(?string $directory = null)
	{
		if (!function_exists('posix_geteuid') || DIRECTORY_SEPARATOR !== '/')
		{
			throw new RuntimeException('Persistent MCP configuration requires POSIX ownership support.');
		}

		$this->owner = posix_geteuid();

		if ($directory === null)
		{
			$base = getenv('XDG_CONFIG_HOME');

			if (!is_string($base) || $base === '')
			{
				$home = getenv('HOME');

				if (!is_string($home) || $home === '')
				{
					throw new RuntimeException('Set an absolute XDG_CONFIG_HOME or HOME for MCP configuration.');
				}

				$base = rtrim($home, '/') . '/.config';
			}

			$directory = rtrim($base, '/') . '/joomengine-mcp';
		}

		$directory = rtrim($directory, '/');

		if ($directory === '' || !str_starts_with($directory, '/') || str_contains($directory, '//')
			|| preg_match('/[\x00-\x1f\x7f]/', $directory)
			|| preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $directory))
		{
			throw new InvalidArgumentException('The MCP configuration directory must be an unambiguous absolute path.');
		}

		$this->directory = $directory;
	}

	/**
	 * Persist one validated HTTPS installation and separately supplied token.
	 *
	 * @param string $name Operator-selected site name.
	 * @param string $site Installation base URL, including its subdirectory.
	 * @param string $token Joomla API token, obtained outside command arguments.
	 * @return void
	 * @since 0.1.0
	 */
	public function save(string $name, string $site, #[SensitiveParameter] string $token): void
	{
		$this->validateName($name);
		$connection = new Connection($site, $token);
		$site = substr($connection->endpoint(), 0, -strlen('/api/index.php/v1/joomengine-mcp'));
		$lock = $this->lock();

		try
		{
			$sites = $this->read();
			$sites[$name] = ['site' => $site, 'token' => $token];
			ksort($sites, SORT_STRING);
			$this->write($sites);
		}
		finally
		{
			$this->unlock($lock);
		}
	}

	/** @return Connection Endpoint-bound credentials for one named site. @since 0.1.0 */
	public function connection(string $name): Connection
	{
		$this->validateName($name);
		$lock = $this->lock();

		try
		{
			$sites = $this->read();

			if (!isset($sites[$name]))
			{
				throw new RuntimeException('The requested MCP site is not configured.');
			}

			return new Connection($sites[$name]['site'], $sites[$name]['token']);
		}
		finally
		{
			$this->unlock($lock);
		}
	}

	/** @return array<string,string> Site names and URLs, without credentials. @since 0.1.0 */
	public function sites(): array
	{
		$lock = $this->lock();

		try
		{
			$result = [];

			foreach ($this->read() as $name => $entry)
			{
				$result[$name] = $entry['site'];
			}

			ksort($result, SORT_STRING);

			return $result;
		}
		finally
		{
			$this->unlock($lock);
		}
	}

	/** @return void Remove one site; an absent name is harmless. @since 0.1.0 */
	public function remove(string $name): void
	{
		$this->validateName($name);
		$lock = $this->lock();

		try
		{
			$sites = $this->read();

			if (isset($sites[$name]))
			{
				unset($sites[$name]);
				$this->write($sites);
			}
		}
		finally
		{
			$this->unlock($lock);
		}
	}

	/** @return array<string,string> Safe diagnostic state. @since 0.1.0 */
	public function __debugInfo(): array
	{
		return ['directory' => $this->directory];
	}

	/** @return array Never serialize a credential store. @since 0.1.0 */
	public function __serialize(): array
	{
		throw new LogicException('MCP credential stores must not be serialized.');
	}

	/** @return void Reject path fragments and terminal-control names. @since 0.1.0 */
	private function validateName(string $name): void
	{
		if (!preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/D', $name))
		{
			throw new InvalidArgumentException('Use a site name of 1-64 ASCII letters, digits, underscores or hyphens, starting with a letter.');
		}
	}

	/**
	 * Check every ancestor before creating the private leaf directory.
	 *
	 * Shared temporary directories must be root-owned and sticky. All other
	 * ancestors must be owned by this user or root and deny group/other writes.
	 *
	 * @return void
	 * @since 0.1.0
	 */
	private function prepareDirectory(): void
	{
		$current = '';

		foreach (explode('/', ltrim($this->directory, '/')) as $segment)
		{
			$current .= '/' . $segment;
			clearstatcache(true, $current);
			$stat = @lstat($current);

			if ($stat === false)
			{
				$mask = umask(0077);

				try
				{
					$created = @mkdir($current, 0700);
				}
				finally
				{
					umask($mask);
				}

				clearstatcache(true, $current);
				$stat = @lstat($current);

				if (!$created && $stat === false)
				{
					throw new RuntimeException('Unable to create the private MCP configuration directory.');
				}
			}

			if ($stat === false || ($stat['mode'] & 0170000) !== 0040000
				|| !in_array($stat['uid'], [0, $this->owner], true))
			{
				throw new RuntimeException('MCP configuration paths must be owned directories without symlinks.');
			}

			if ($current === $this->directory)
			{
				if ($stat['uid'] !== $this->owner || ($stat['mode'] & 07777) !== 0700)
				{
					throw new RuntimeException('The MCP configuration directory must be owned by the current user with mode 0700.');
				}
			}
			elseif (($stat['mode'] & 0022) !== 0
				&& !($stat['uid'] === 0 && ($stat['mode'] & 01000) !== 0))
			{
				throw new RuntimeException('An MCP configuration parent directory permits unsafe shared writes.');
			}
		}
	}

	/** @return array|null Validated file metadata, or null when absent. @since 0.1.0 */
	private function inspect(string $path): ?array
	{
		clearstatcache(true, $path);
		$stat = @lstat($path);

		if ($stat === false)
		{
			return null;
		}

		if (($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== $this->owner
			|| ($stat['mode'] & 07777) !== 0600 || $stat['nlink'] !== 1)
		{
			throw new RuntimeException('MCP configuration files must be private, singly linked files owned by the current user with mode 0600.');
		}

		return $stat;
	}

	/** @return resource Open a file only after verifying its path and descriptor. @since 0.1.0 */
	private function open(string $path, bool $create = false)
	{
		$before = $this->inspect($path);

		if ($before === null && !$create)
		{
			throw new RuntimeException('The MCP configuration file disappeared.');
		}

		$mask = umask(0077);

		try
		{
			$handle = @fopen($path, $before === null ? 'x+b' : 'r+b');
		}
		finally
		{
			umask($mask);
		}

		if ($handle === false)
		{
			// Another configuring process may have created the shared lock first.
			if ($before === null && $create && $this->inspect($path) !== null)
			{
				return $this->open($path);
			}

			throw new RuntimeException('Unable to open the private MCP configuration file.');
		}

		try
		{
			$after = $this->inspect($path);
			$descriptor = fstat($handle);

			if ($after === null || $descriptor === false || $descriptor['ino'] !== $after['ino']
				|| $descriptor['dev'] !== $after['dev'] || ($before !== null
					&& ($before['ino'] !== $after['ino'] || $before['dev'] !== $after['dev'])))
			{
				throw new RuntimeException('The MCP configuration file changed while opening it.');
			}
		}
		catch (\Throwable $error)
		{
			fclose($handle);
			throw $error;
		}

		return $handle;
	}

	/** @return resource Exclusive lock with a bounded acquisition time. @since 0.1.0 */
	private function lock()
	{
		$this->prepareDirectory();
		$handle = $this->open($this->directory . '/.lock', true);
		$deadline = hrtime(true) + 2000000000;

		do
		{
			if (flock($handle, LOCK_EX | LOCK_NB))
			{
				return $handle;
			}

			usleep(10000);
		}
		while (hrtime(true) < $deadline);

		fclose($handle);
		throw new RuntimeException('The MCP configuration is busy; try again after the other configuration command finishes.');
	}

	/** @param resource $handle Acquired lock. @return void @since 0.1.0 */
	private function unlock($handle): void
	{
		flock($handle, LOCK_UN);
		fclose($handle);
	}

	/** @return array<string,array{site:string,token:string}> Validated records. @since 0.1.0 */
	private function read(): array
	{
		$path = $this->directory . '/sites.json';
		$stat = $this->inspect($path);

		if ($stat === null)
		{
			return [];
		}

		if ($stat['size'] < 1 || $stat['size'] > self::MAXIMUM_BYTES)
		{
			throw new RuntimeException('The MCP configuration file has an invalid size.');
		}

		$handle = $this->open($path);

		try
		{
			$encoded = stream_get_contents($handle, self::MAXIMUM_BYTES + 1);
		}
		finally
		{
			fclose($handle);
		}

		if (!is_string($encoded) || strlen($encoded) > self::MAXIMUM_BYTES)
		{
			throw new RuntimeException('Unable to read the bounded MCP configuration file.');
		}

		try
		{
			$data = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);

			if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['sites'] ?? null))
			{
				throw new RuntimeException('Unsupported configuration schema.');
			}

			foreach ($data['sites'] as $name => $entry)
			{
				if (!is_string($name) || !is_array($entry) || !is_string($entry['site'] ?? null)
					|| !is_string($entry['token'] ?? null))
				{
					throw new RuntimeException('Invalid configuration entry.');
				}

				$this->validateName($name);
				new Connection($entry['site'], $entry['token']);
			}
		}
		catch (\Throwable)
		{
			// Do not attach the parser exception or its credential-bearing arguments.
			throw new RuntimeException('The MCP configuration is invalid; its contents were not changed.');
		}

		return $data['sites'];
	}

	/** @return void Replace the complete configuration under the caller's lock. @since 0.1.0 */
	private function write(#[SensitiveParameter] array $sites): void
	{
		try
		{
			$encoded = json_encode(['version' => 1, 'sites' => (object) $sites], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
		}
		catch (\JsonException)
		{
			throw new RuntimeException('MCP configuration must contain valid UTF-8 text.');
		}

		if (strlen($encoded) > self::MAXIMUM_BYTES)
		{
			throw new RuntimeException('The MCP configuration exceeds its maximum size.');
		}

		$path = $this->directory . '/sites.json';
		$temporary = $this->directory . '/.sites-' . bin2hex(random_bytes(16)) . '.tmp';
		$handle = $this->open($temporary, true);

		try
		{
			$offset = 0;
			$length = strlen($encoded);

			while ($offset < $length)
			{
				$written = @fwrite($handle, substr($encoded, $offset));

				if ($written === false || $written === 0)
				{
					throw new RuntimeException('Unable to write the MCP configuration.');
				}

				$offset += $written;
			}

			if (!fflush($handle) || !fsync($handle))
			{
				throw new RuntimeException('Unable to flush the MCP configuration.');
			}

			$this->inspect($path);

			if (!@rename($temporary, $path))
			{
				throw new RuntimeException('Unable to atomically replace the MCP configuration.');
			}
		}
		finally
		{
			fclose($handle);

			if (is_file($temporary))
			{
				@unlink($temporary);
			}
		}
	}
}
