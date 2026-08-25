// Discovery, registration, token exchange and bearer authentication.
const { test, expect, REDIRECT_URI } = require( './fixtures' );

test( 'discovery documents are served on the well-known paths', async ( { request, oauth, baseURL } ) => {
	const pr = await request.get( oauth.url( '/.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server' ) );
	expect( pr.status() ).toBe( 200 );
	expect( await pr.json() ).toMatchObject( { resource: oauth.mcpUrl, authorization_servers: [ baseURL ], scopes_supported: [ 'mcp' ] } );

	const append = await request.get( oauth.url( '/.well-known/oauth-protected-resource' ) );
	expect( ( await append.json() ).resource ).toBe( oauth.mcpUrl );

	for ( const path of [ '/.well-known/oauth-authorization-server', '/.well-known/openid-configuration' ] ) {
		const as = await request.get( oauth.url( path ) );
		expect( as.status(), path ).toBe( 200 );
		expect( await as.json() ).toMatchObject( {
			issuer: baseURL,
			authorization_endpoint: oauth.url( '/wp-login.php?action=mcp-oauth-authorize' ),
			token_endpoint: oauth.url( '/wp-json/mcp-oauth/v1/token' ),
			registration_endpoint: oauth.url( '/wp-json/mcp-oauth/v1/register' ),
			code_challenge_methods_supported: [ 'S256' ],
		} );
	}
} );

test( 'anonymous requests to the MCP endpoint get a 401 with a resource_metadata challenge', async ( { request, oauth } ) => {
	const r = await request.post( oauth.mcpUrl, { data: { jsonrpc: '2.0', id: 1, method: 'ping' } } );
	expect( r.status() ).toBe( 401 );
	const challenge = r.headers()[ 'www-authenticate' ];
	expect( challenge ).toMatch( /^Bearer resource_metadata="/ );
	expect( challenge ).toContain( oauth.url( '/.well-known/oauth-protected-resource/wp-json/mcp/mcp-adapter-default-server' ) );
	expect( challenge, 'no error parameter when no credentials were sent' ).not.toMatch( /error=/ );
} );

test( 'dynamic client registration issues public clients and validates redirect URIs', async ( { request, oauth } ) => {
	const client = await oauth.registerClient( { client_name: 'Claude <b>x</b>' } );
	expect( client.client_id ).toMatch( /^[0-9a-f]{32}$/ );
	expect( client.token_endpoint_auth_method ).toBe( 'none' );
	expect( client.client_name, 'client name is sanitized' ).toBe( 'Claude x' );

	const bad = await request.post( oauth.url( '/wp-json/mcp-oauth/v1/register' ), { data: { client_name: 'x', redirect_uris: [ 'http://evil.example/cb' ] } } );
	expect( bad.status() ).toBe( 400 );
	expect( ( await bad.json() ).error ).toBe( 'invalid_redirect_uri' );
} );

test( 'the code exchange requires the right client and verifier and works once', async ( { adminPage, oauth } ) => {
	const client = await oauth.registerClient();
	const { verifier, challenge } = oauth.pkce();
	const { location } = await oauth.consent( adminPage, oauth.authorizeUrl( client.client_id, challenge ) );
	const code = new URL( location ).searchParams.get( 'code' );

	const wrongClient = await oauth.exchange( 'other', code, verifier );
	expect( wrongClient.status ).toBe( 401 );
	expect( wrongClient.body.error ).toBe( 'invalid_client' );

	const ok = await oauth.exchange( client.client_id, code, verifier, { redirect_uri: REDIRECT_URI } );
	expect( ok.status, JSON.stringify( ok.body ) ).toBe( 200 );
	expect( ok.body ).toMatchObject( { token_type: 'Bearer', scope: 'mcp', expires_in: 3600 } );
	expect( ok.body.access_token ).toMatch( /^[0-9a-f]{64}$/ );
	expect( ok.body.refresh_token ).toMatch( /^[0-9a-f]{64}$/ );
	expect( ok.headers[ 'cache-control' ] ).toBe( 'no-store' );

	const replay = await oauth.exchange( client.client_id, code, verifier );
	expect( replay.status ).toBe( 400 );
	expect( replay.body.error ).toBe( 'invalid_grant' );
	expect( ( await oauth.mcp( ok.body.access_token ).initialize() ).status, 'replaying the code revokes the tokens it issued' ).toBe( 401 );
} );

test( 'a wrong PKCE verifier is refused', async ( { adminPage, oauth } ) => {
	const client = await oauth.registerClient();
	const { location } = await oauth.consent( adminPage, oauth.authorizeUrl( client.client_id, oauth.pkce().challenge ) );
	const code = new URL( location ).searchParams.get( 'code' );
	const r = await oauth.exchange( client.client_id, code, oauth.pkce().verifier );
	expect( r.status ).toBe( 400 );
	expect( r.body.error ).toBe( 'invalid_grant' );
} );

test( 'a bearer token runs an MCP session as the user, and only on the MCP endpoint', async ( { adminPage, oauth, request } ) => {
	const { tokens } = await oauth.connect( adminPage );
	const call = oauth.mcp( tokens.access_token );
	const init = await call.initialize();
	expect( init.status, JSON.stringify( init.body ) ).toBe( 200 );
	expect( init.body.result.serverInfo.name ).toBe( 'MCP Adapter Default Server' );
	const tools = await call( 'tools/list', {}, 2 );
	expect( tools.body.result.tools.map( ( t ) => t.name ) ).toContain( 'mcp-adapter-discover-abilities' );

	const me = await request.get( oauth.url( '/wp-json/wp/v2/users/me' ), { headers: { Authorization: 'Bearer ' + tokens.access_token } } );
	expect( me.status() ).toBe( 403 );
	expect( ( await me.json() ).code ).toBe( 'rest_oauth_route_forbidden' );

	const bogus = await request.post( oauth.mcpUrl, { headers: { Authorization: 'Bearer nope' }, data: { jsonrpc: '2.0', id: 1, method: 'ping' } } );
	expect( bogus.status() ).toBe( 401 );
	expect( bogus.headers()[ 'www-authenticate' ] ).toMatch( /error="invalid_token"/ );
} );

test( 'refresh tokens rotate and old ones die', async ( { adminPage, oauth } ) => {
	const { client, tokens } = await oauth.connect( adminPage );
	const rotated = await oauth.refresh( client.client_id, tokens.refresh_token );
	expect( rotated.status, JSON.stringify( rotated.body ) ).toBe( 200 );
	expect( rotated.body.access_token ).not.toBe( tokens.access_token );
	expect( rotated.body.refresh_token ).not.toBe( tokens.refresh_token );

	expect( ( await oauth.refresh( client.client_id, tokens.refresh_token ) ).body.error ).toBe( 'invalid_grant' );
	expect( ( await oauth.mcp( tokens.access_token ).initialize() ).status, 'the access token paired with a rotated refresh token is revoked' ).toBe( 401 );
	expect( ( await oauth.mcp( rotated.body.access_token ).initialize() ).status ).toBe( 200 );
} );

test( 'revocation takes down the whole grant', async ( { adminPage, oauth, request } ) => {
	const { client, tokens } = await oauth.connect( adminPage );
	const r = await request.post( oauth.url( '/wp-json/mcp-oauth/v1/revoke' ), { form: { token: tokens.refresh_token, client_id: client.client_id } } );
	expect( r.status() ).toBe( 200 );
	expect( ( await oauth.mcp( tokens.access_token ).initialize() ).status ).toBe( 401 );
	expect( ( await oauth.refresh( client.client_id, tokens.refresh_token ) ).body.error ).toBe( 'invalid_grant' );
} );
