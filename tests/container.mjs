/** Exercise the packaged Compose service, never substitute it with a host PHP process. */
import { spawn, spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { request } from 'node:https';
import { createInterface } from 'node:readline';

const compose = ['compose', ...process.argv.slice(3), 'run', '--rm', '--no-deps', '-T'];
let passed = 0;
const check = (value, name) => {
	if (!value) throw new Error('FAIL ' + name);
	passed++;
	console.log('PASS ' + name);
};
const inspect = spawnSync('docker', [...compose, '--entrypoint', 'php', 'mcp', '-r', `
require '/app/vendor/autoload.php';
$valid = posix_geteuid() === 10001;
foreach (['curl', 'json', 'fileinfo', 'posix', 'pcntl'] as $extension) { $valid = $valid && extension_loaded($extension); }
$readOnly = false;
foreach (file('/proc/mounts') as $mount) {
	$fields = explode(' ', $mount);
	if ($fields[1] === '/') { $readOnly = in_array('ro', explode(',', $fields[3]), true); }
}
exit($valid && $readOnly && !is_file('/usr/local/bin/composer') && !is_dir('/app/tests') ? 0 : 1);
`], { encoding: 'utf8', timeout: 30000, maxBuffer: 65536 });
check(inspect.status === 0, 'production image has dependencies, non-root identity and a read-only filesystem');

for (const variable of ['JOOMENGINE_MCP_URL', 'JOOMENGINE_MCP_TOKEN']) {
	const environment = { ...process.env };
	delete environment[variable];
	const result = spawnSync('docker', [...compose, 'mcp'], {
		env: environment, input: '', encoding: 'utf8', timeout: 30000, maxBuffer: 65536,
	});
	check(result.status === (variable === 'JOOMENGINE_MCP_URL' ? 2 : 1)
		&& result.stdout === '' && !result.stderr.includes('private-fixture-token'), variable
			+ ' is required without credential output (status ' + result.status + ', stdout bytes ' + result.stdout.length + ')');
}

const deadline = async (promise, milliseconds) => {
	let timer;
	try {
		return await Promise.race([promise, new Promise((_, reject) => {
			timer = setTimeout(() => reject(new Error('Container exchange exceeded its test deadline.')), milliseconds);
		})]);
	} finally { clearTimeout(timer); }
};

const exercise = async token => {
	const bridge = spawn('docker', [...compose, 'mcp'], {
		env: { ...process.env, JOOMENGINE_MCP_TOKEN: token }, stdio: ['pipe', 'pipe', 'pipe'],
	});
	const closed = new Promise((resolve, reject) => {
		bridge.once('error', reject);
		bridge.once('close', code => resolve(code));
	});
	// Attach a rejection handler immediately, including before the first read.
	closed.catch(() => {});
	let errors = '';
	let outputBytes = 0;
	const messages = [];
	let receiver = null;
	let framingError = false;
	const reader = createInterface({ input: bridge.stdout, crlfDelay: Infinity });
	reader.on('line', line => {
		outputBytes += Buffer.byteLength(line);
		try {
			if (outputBytes > 1048576) throw new Error();
			const message = JSON.parse(line);
			if (message.jsonrpc !== '2.0') throw new Error();
			if (receiver) { const resolve = receiver; receiver = null; resolve(message); }
			else messages.push(message);
		} catch { framingError = true; bridge.kill('SIGTERM'); }
	});
	bridge.stderr.on('data', chunk => {
		errors += chunk;
		if (errors.length > 65536) bridge.kill('SIGTERM');
	});
	bridge.stdin.on('error', () => {});
	const read = () => messages.length > 0 ? Promise.resolve(messages.shift())
		: deadline(new Promise(resolve => { receiver = resolve; }), 30000);
	const send = message => bridge.stdin.write(JSON.stringify({ jsonrpc: '2.0', ...message }) + '\n');
	try {
		send({ id: 'container-init', method: 'initialize', params: {
			protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 'compose-acceptance', version: '1' },
		} });
		const initialized = await read();
		if (token === 'private-fixture-token') {
			check(initialized.id === 'container-init' && initialized.result?.serverInfo?.name === 'loopback-tls-fixture',
				'Compose stdin initializes the authenticated remote HTTPS endpoint');
			send({ method: 'notifications/initialized' });
			send({ id: 2, method: 'tools/list' });
			const tools = await read();
			check(tools.id === 2 && tools.result?.tools?.[0]?.name === 'remote_fixture',
				'Compose stdout contains the server-discovered catalogue');
		} else {
			check(initialized.id === 'container-init' && initialized.error?.code === -32000,
				'Compose forwards authentication failure as a safe protocol error');
		}
		bridge.stdin.end();
		const code = await deadline(closed, 20000);
		check(code === 0 && !framingError && messages.length === 0
			&& !errors.includes(token), 'Compose closes on EOF with protocol-only stdout and no credential output');
	} finally {
		reader.close();
		if (bridge.exitCode === null) bridge.kill('SIGTERM');
	}
};

try {
	await exercise('private-fixture-token');
	await exercise('invalid-container-token');
	const stats = await deadline(new Promise((resolve, reject) => {
		const exchange = request(process.env.JOOMENGINE_MCP_URL + '/api/index.php/v1/joomengine-mcp', {
			method: 'POST', ca: readFileSync(process.argv[2]), headers: { 'Content-Type': 'application/json' },
		}, response => {
			let data = '';
			response.on('data', chunk => { data += chunk; });
			response.on('end', () => {
				try { resolve(JSON.parse(data)); } catch (error) { reject(error); }
			});
		});
		exchange.on('error', reject);
		exchange.setTimeout(5000, () => exchange.destroy(new Error('Fixture timeout.')));
		exchange.end('{"mode":"stats"}');
	}), 6000);
	check(stats.counts.DELETE === 1 && stats.counts.initialize === 2 && stats.redirected === 0,
		'container closes its authenticated session without redirects or replay');
	console.log(JSON.stringify({ passed, failed: 0, fixture: 'built Docker Compose client and real TLS; not installed Joomla' }));
} catch (error) {
	console.error(error.message);
	process.exitCode = 1;
}
