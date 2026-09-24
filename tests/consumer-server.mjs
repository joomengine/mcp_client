/** HTTPS protocol fixture for an independently installed Composer consumer. */
import https from 'node:https';
import { appendFileSync, readFileSync } from 'node:fs';

const sessions = new Map();
const endpoint = '/nested/api/index.php/v1/joomengine-mcp';
let nextSession = 0;
const server = https.createServer({
	key: readFileSync(process.argv[2]), cert: readFileSync(process.argv[3]),
}, (request, response) => {
	let body = '';
	request.on('data', chunk => {
		body += chunk;
		if (body.length > 1048576) request.destroy();
	});
	request.on('end', () => {
		let payload;
		try { payload = body === '' ? {} : JSON.parse(body); }
		catch { response.writeHead(400).end(); return; }
		const session = request.headers['mcp-session-id'];
		const authorized = request.headers['x-joomla-token'] === 'consumer-fixture-token';
		appendFileSync(process.argv[4], JSON.stringify({
			http: request.method, path: request.url, method: payload.method,
			session: session ?? null, protocol: request.headers['mcp-protocol-version'] ?? null,
			negotiated: sessions.get(session) ?? null,
			authorized, name: payload.params?.name ?? null,
		}) + '\n');
		if (request.url !== endpoint) { response.writeHead(404).end(); return; }
		if (request.headers['x-joomla-token'] === 'consumer-redirect-token') {
			response.writeHead(302, { Location: `https://127.0.0.1:${server.address().port}/capture` }).end();
			return;
		}
		if (!authorized) {
			response.writeHead(401, { 'Content-Type': 'text/plain' }).end('private-fixture-diagnostic');
			return;
		}
		const reply = (result, sessionId = session) => {
			response.writeHead(200, { 'Content-Type': 'application/json', 'Mcp-Session-Id': sessionId });
			response.end(JSON.stringify({ jsonrpc: '2.0', id: payload.id, result }));
		};
		if (payload.method === 'initialize') {
			const id = `consumer-session-${++nextSession}`;
			sessions.set(id, payload.params.protocolVersion);
			reply({ protocolVersion: payload.params.protocolVersion,
				capabilities: { tools: {}, resources: {}, prompts: {} },
				serverInfo: { name: 'packagist-consumer-fixture', version: '1.0.0' },
				instructions: 'Fixture capabilities are discovered from the remote server.',
			}, id);
			return;
		}
		if (!sessions.has(session)) { response.writeHead(404).end(); return; }
		if (request.method === 'DELETE') {
			sessions.delete(session);
			response.writeHead(204).end();
			return;
		}
		// Match the component's SDK middleware: legacy missing headers are accepted.
		// A supplied revision must still agree with this fixture's negotiated session.
		if (request.headers['mcp-protocol-version'] !== undefined
			&& request.headers['mcp-protocol-version'] !== sessions.get(session)) {
			response.writeHead(400).end(); return;
		}
		if (request.method !== 'POST') { response.writeHead(405).end(); return; }
		if (!Object.hasOwn(payload, 'id')) { response.writeHead(202).end(); return; }
		const secondPage = payload.params?.cursor === 'opaque-second-page';
		const page = (key, first, second) => secondPage
			? { [key]: [second] } : { [key]: [first], nextCursor: 'opaque-second-page' };
		switch (payload.method) {
			case 'ping': reply({}); return;
			case 'tools/list': reply(page('tools',
				{ name: 'extension.dynamic_echo', inputSchema: { type: 'object' } },
				{ name: 'extension.dynamic_error', inputSchema: { type: 'object' } })); return;
			case 'tools/call':
				reply({ content: [{ type: 'text', text: JSON.stringify(payload.params) }],
					structuredContent: { name: payload.params.name, arguments: payload.params.arguments },
					isError: payload.params.name === 'extension.dynamic_error' }); return;
			case 'resources/list': reply(page('resources',
				{ name: 'First', uri: 'fixture://data/first', mimeType: 'application/json' },
				{ name: 'Second', uri: 'fixture://data/second', mimeType: 'application/json' })); return;
			case 'resources/templates/list':
				reply({ resourceTemplates: [{ name: 'Dynamic', uriTemplate: 'fixture://data/{id}' }] }); return;
			case 'resources/read': reply({ contents: [{ uri: payload.params.uri,
				mimeType: 'application/json', text: JSON.stringify({ from: 'remote', value: 7 }) }] }); return;
			case 'prompts/list': reply(page('prompts',
				{ name: 'dynamic-first' }, { name: 'dynamic-second' })); return;
			case 'prompts/get': reply({ messages: [{ role: 'user', content: {
				type: 'text', text: `Remote instructions for ${payload.params.arguments?.topic}.`,
			} }] }); return;
			default:
				response.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({
					jsonrpc: '2.0', id: payload.id, error: { code: -32601, message: 'Unknown fixture method.' },
				}));
		}
	});
});
server.on('tlsClientError', () => {});
server.listen(0, '127.0.0.1', () => process.stdout.write(String(server.address().port) + '\n'));
