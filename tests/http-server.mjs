/** Local TLS fixture: protocol simulation only, never an installed Joomla test. */
import https from 'node:https';
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const counts = {};
let redirected = 0;
const server = https.createServer({
	key: readFileSync(process.argv[2]),
	cert: readFileSync(process.argv[3]),
}, (request, response) => {
	if (request.url !== '/nested/api/index.php/v1/joomengine-mcp') {
		redirected++;
		response.writeHead(404).end();
		return;
	}
	let body = '';
	request.on('data', chunk => { body += chunk; });
	request.on('end', () => {
		let payload;
		try { payload = body === '' ? {} : JSON.parse(body); }
		catch { response.writeHead(400).end(); return; }
		const mode = payload.mode ?? payload.method ?? 'empty';
		counts[mode] = (counts[mode] ?? 0) + 1;
		const json = data => {
			response.writeHead(200, { 'Content-Type': 'application/json', 'Mcp-Session-Id': 'fixture-session' });
			response.end(JSON.stringify(data));
		};
		if (request.method === 'DELETE') {
			counts.DELETE = (counts.DELETE ?? 0) + 1;
			response.writeHead(204).end();
			return;
		}
		switch (mode) {
			case 'slow': setTimeout(() => json({ mode }), 350); return;
			case 'timeout': setTimeout(() => json({ mode }), 1800); return;
			case 'overflow': response.writeHead(200).end('x'.repeat(2048)); return;
			case 'compressed':
				response.writeHead(200, { 'Content-Encoding': 'gzip' }).end(gzipSync('x'.repeat(2048))); return;
			case 'headers':
				for (let i = 0; i < 80; i++) response.setHeader('X-Fixture-' + i, 'x'.repeat(1024));
				response.writeHead(200).end('{}'); return;
			case 'redirect':
				response.writeHead(302, { Location: 'https://127.0.0.1:' + server.address().port + '/redirected' }).end(); return;
			case 'drop': request.socket.destroy(); return;
			case 'stats': json({ counts, redirected }); return;
			case 'unauthorized': response.writeHead(401, { 'Content-Type': 'text/plain' }).end('private-upstream-diagnostic'); return;
			case 'initialize':
				if (request.headers['x-joomla-token'] !== 'private-fixture-token') {
					response.writeHead(401).end(); return;
				}
				json({ jsonrpc: '2.0', id: payload.id, result: {
					protocolVersion: payload.params.protocolVersion,
					capabilities: { tools: {} }, serverInfo: { name: 'loopback-tls-fixture', version: '1.0' },
				} }); return;
			case 'notifications/initialized': response.writeHead(202).end(); return;
			case 'tools/list':
				json({ jsonrpc: '2.0', id: payload.id, result: {
					tools: [{ name: 'remote_fixture', inputSchema: { type: 'object' } }],
				} }); return;
			default: json({ mode, path: request.url, token: request.headers['x-joomla-token'],
				session: request.headers['mcp-session-id'], protocol: request.headers['mcp-protocol-version'] });
		}
	});
});
server.on('tlsClientError', () => {});
server.listen(0, '127.0.0.1', () => process.stdout.write(String(server.address().port) + '\n'));
