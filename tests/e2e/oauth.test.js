// Discovery, registration, token exchange and bearer authentication.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const h = require( './helpers' );

test.after( h.closeBrowser );

test( 'discovery documents are served on the well-known paths', async () => {
	const pr = await h.fetchJson( h.BASE + '/.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server' );
	assert.equal( pr.status, 200 );
	assert.equal( pr.body.resource, h.MCP_URL );
	assert.deepEqual( pr.body.authorization_servers, [ h.BASE ] );

	const append = await h.fetchJson( h.BASE + '/.well-known/oauth-protected-resource' );
	assert.equal( append.body.resource, h.MCP_URL );

	for ( const path of [ '/.well-known/oauth-authorization-server', '/.well-known/openid-configuration' ] ) {
		const as = await h.fetchJson( h.BASE + path );
		assert.equal( as.status, 200, path );
		assert.equal( as.body.issuer, h.BASE );
		assert.equal( as.body.authorization_endpoint, h.BASE + '/wp-login.php?action=mcp-oauth-authorize' );
		assert.equal( as.body.token_endpoint, h.BASE + '/wp-json/mcp-oauth/v1/token' );
		assert.equal( as.body.registration_endpoint, h.BASE + '/wp-json/mcp-oauth/v1/register' );
		assert.deepEqual( as.body.code_challenge_methods_supported, [ 'S256' ] );
	}
} );

test( 'anonymous requests to the MCP endpoint get a 401 with a resource_metadata challenge', async () => {
	const res = await fetch( h.MCP_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"jsonrpc":"2.0","id":1,"method":"ping"}' } );
	assert.equal( res.status, 401 );
	const challenge = res.headers.get( 'www-authenticate' );
	assert.match( challenge, /^Bearer resource_metadata="/ );
	assert.ok( challenge.includes( h.BASE + '/.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server' ) );
	assert.ok( ! /error=/.test( challenge ), 'no error parameter when no credentials were sent' );
} );

test( 'dynamic client registration issues public clients and validates redirect URIs', async () => {
	const client = await h.registerClient( { client_name: 'Claude <b>x</b>' } );
	assert.match( client.client_id, /^[0-9a-f]{32}$/ );
	assert.equal( client.token_endpoint_auth_method, 'none' );
	assert.equal( client.client_name, 'Claude x', 'client name is sanitized' );

	const bad = await h.fetchJson( h.BASE + '/wp-json/mcp-oauth/v1/register', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { client_name: 'x', redirect_uris: [ 'http://evil.example/cb' ] } ) } );
	assert.equal( bad.status, 400 );
	assert.equal( bad.body.error, 'invalid_redirect_uri' );
} );

test( 'the code exchange requires the right verifier and works once', async () => {
	const { ctx, page } = await h.adminContext();
	const client = await h.registerClient();
	const { verifier, challenge } = h.pkce();
	const { location } = await h.consent( ctx, page, h.authorizeUrl( client.client_id, challenge ) );
	const code = new URL( location ).searchParams.get( 'code' );

	const wrongClient = await h.exchange( 'other', code, verifier );
	assert.equal( wrongClient.status, 401 );
	assert.equal( wrongClient.body.error, 'invalid_client' );

	const ok = await h.exchange( client.client_id, code, verifier, { redirect_uri: h.REDIRECT_URI } );
	assert.equal( ok.status, 200, JSON.stringify( ok.body ) );
	assert.equal( ok.body.token_type, 'Bearer' );
	assert.equal( ok.body.scope, 'mcp' );
	assert.match( ok.body.access_token, /^[0-9a-f]{64}$/ );
	assert.match( ok.body.refresh_token, /^[0-9a-f]{64}$/ );
	assert.equal( ok.headers.get( 'cache-control' ), 'no-store' );

	const replay = await h.exchange( client.client_id, code, verifier );
	assert.equal( replay.status, 400 );
	assert.equal( replay.body.error, 'invalid_grant' );

	const call = h.mcpClient( ok.body.access_token );
	const after = await h.initialize( call );
	assert.equal( after.status, 401, 'replaying the code revokes the tokens it issued' );
	await ctx.close();
} );

test( 'a wrong PKCE verifier is refused', async () => {
	const { ctx, page } = await h.adminContext();
	const client = await h.registerClient();
	const { challenge } = h.pkce();
	const { location } = await h.consent( ctx, page, h.authorizeUrl( client.client_id, challenge ) );
	const code = new URL( location ).searchParams.get( 'code' );
	const r = await h.exchange( client.client_id, code, h.pkce().verifier );
	assert.equal( r.status, 400 );
	assert.equal( r.body.error, 'invalid_grant' );
	await ctx.close();
} );

test( 'a bearer token runs an MCP session as the user, and only on the MCP endpoint', async () => {
	const { tokens, ctx } = await h.connectedClient();
	const call = h.mcpClient( tokens.access_token );
	const init = await h.initialize( call );
	assert.equal( init.status, 200, JSON.stringify( init.body ) );
	assert.equal( init.body.result.serverInfo.name, 'MCP Adapter Default Server' );

	const tools = await call( 'tools/list', {}, 2 );
	assert.ok( tools.body.result.tools.some( ( t ) => t.name === 'mcp-adapter-discover-abilities' ) );

	const me = await h.fetchJson( h.BASE + '/wp-json/wp/v2/users/me', { headers: { Authorization: 'Bearer ' + tokens.access_token } } );
	assert.equal( me.status, 403 );
	assert.equal( me.body.code, 'rest_oauth_route_forbidden' );

	const bogus = await fetch( h.MCP_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer nope' }, body: '{"jsonrpc":"2.0","id":1,"method":"ping"}' } );
	assert.equal( bogus.status, 401 );
	assert.match( bogus.headers.get( 'www-authenticate' ), /error="invalid_token"/ );
	await ctx.close();
} );

test( 'refresh tokens rotate and old ones die', async () => {
	const { client, tokens, ctx } = await h.connectedClient();
	const r1 = await h.refresh( client.client_id, tokens.refresh_token );
	assert.equal( r1.status, 200, JSON.stringify( r1.body ) );
	assert.notEqual( r1.body.access_token, tokens.access_token );
	assert.notEqual( r1.body.refresh_token, tokens.refresh_token );

	const reuse = await h.refresh( client.client_id, tokens.refresh_token );
	assert.equal( reuse.body.error, 'invalid_grant' );

	const oldAccess = await h.initialize( h.mcpClient( tokens.access_token ) );
	assert.equal( oldAccess.status, 401, 'the access token paired with a rotated refresh token is revoked' );
	const newAccess = await h.initialize( h.mcpClient( r1.body.access_token ) );
	assert.equal( newAccess.status, 200 );
	await ctx.close();
} );

test( 'revocation takes down the whole grant', async () => {
	const { client, tokens, ctx } = await h.connectedClient();
	const r = await fetch( h.BASE + '/wp-json/mcp-oauth/v1/revoke', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams( { token: tokens.refresh_token, client_id: client.client_id } ) } );
	assert.equal( r.status, 200 );
	assert.equal( ( await h.initialize( h.mcpClient( tokens.access_token ) ) ).status, 401 );
	assert.equal( ( await h.refresh( client.client_id, tokens.refresh_token ) ).body.error, 'invalid_grant' );
	await ctx.close();
} );
