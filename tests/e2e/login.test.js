// The sign-in and consent flow on wp-login.php.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const h = require( './helpers' );

test.after( h.closeBrowser );

test( 'a signed-out visitor gets the login form and returns to the authorization request afterwards', async () => {
	const client = await h.registerClient();
	const { challenge } = h.pkce();
	const url = h.authorizeUrl( client.client_id, challenge );

	const ctx = await h.anonContext();
	const page = await ctx.newPage();
	await page.goto( url );

	assert.ok( page.url().startsWith( h.BASE + '/wp-login.php?redirect_to=' ), 'redirected to the login form: ' + page.url() );
	assert.ok( await page.$( '#loginform' ), 'login form is shown' );
	const redirectTo = new URL( page.url() ).searchParams.get( 'redirect_to' );
	assert.equal( redirectTo, url, 'the whole authorization request, including the encoded redirect_uri, is carried through' );

	await page.fill( '#user_login', h.ADMIN.user );
	await page.fill( '#user_pass', h.ADMIN.pass );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

	assert.equal( page.url(), url, 'back on the authorization request after signing in' );
	assert.ok( await page.$( 'form.mcp-oauth-consent' ), 'consent form is shown' );
	assert.match( await page.textContent( 'form.mcp-oauth-consent h2' ), /Claude wants to connect to this site/ );
	assert.match( await page.textContent( 'form.mcp-oauth-consent' ), /admin/ );
	assert.match( await page.textContent( 'form.mcp-oauth-consent' ), /mcp-adapter-default-server/ );
	assert.ok( await page.$( '#login h1 a' ), 'drawn with the login page chrome' );
	assert.match( await page.getAttribute( '#nav a', 'href' ), /action=logout/, 'offers to switch accounts' );
	await ctx.close();
} );

test( 'approving returns a code and the state to the registered redirect URI', async () => {
	const { ctx, page } = await h.adminContext();
	const client = await h.registerClient();
	const { challenge } = h.pkce();
	const url = h.authorizeUrl( client.client_id, challenge, { state: 'xyz 123' } );

	const { status, location } = await h.consent( ctx, page, url, true );
	assert.equal( status, 302 );
	const target = new URL( location );
	assert.equal( target.origin + target.pathname, h.REDIRECT_URI );
	assert.match( target.searchParams.get( 'code' ), /^[0-9a-f]{64}$/ );
	assert.equal( target.searchParams.get( 'state' ), 'xyz 123' );
	await ctx.close();
} );

test( 'denying sends access_denied back to the client', async () => {
	const { ctx, page } = await h.adminContext();
	const client = await h.registerClient();
	const { challenge } = h.pkce();
	const { status, location } = await h.consent( ctx, page, h.authorizeUrl( client.client_id, challenge, { state: 's1' } ), false );
	assert.equal( status, 302 );
	const target = new URL( location );
	assert.equal( target.searchParams.get( 'error' ), 'access_denied' );
	assert.equal( target.searchParams.get( 'state' ), 's1' );
	assert.equal( target.searchParams.get( 'code' ), null );
	await ctx.close();
} );

test( 'a consent token can only be used once', async () => {
	const { ctx, page } = await h.adminContext();
	const client = await h.registerClient();
	const { challenge } = h.pkce();
	const url = h.authorizeUrl( client.client_id, challenge );
	await page.goto( url );
	const form = { _wpnonce: await page.inputValue( 'input[name=_wpnonce]' ), pending: await page.inputValue( 'input[name=pending]' ), approve: '1' };
	const first = await ctx.request.post( h.BASE + '/wp-login.php?action=mcp-oauth-authorize', { form, maxRedirects: 0 } );
	assert.equal( first.status(), 302 );
	const second = await ctx.request.post( h.BASE + '/wp-login.php?action=mcp-oauth-authorize', { form, maxRedirects: 0 } );
	assert.equal( second.status(), 400, 'replaying the consent form fails' );
	assert.match( await second.text(), /expired/ );
	await ctx.close();
} );

test( 'a forged consent form without a valid nonce is refused', async () => {
	const { ctx, page } = await h.adminContext();
	const client = await h.registerClient();
	const { challenge } = h.pkce();
	await page.goto( h.authorizeUrl( client.client_id, challenge ) );
	const pending = await page.inputValue( 'input[name=pending]' );
	const r = await ctx.request.post( h.BASE + '/wp-login.php?action=mcp-oauth-authorize', { form: { _wpnonce: 'bogus', pending, approve: '1' }, maxRedirects: 0 } );
	assert.equal( r.status(), 403 );
	await ctx.close();
} );

test( 'the folded "?action=…?params" URL some clients build still works', async () => {
	const { ctx, page } = await h.adminContext();
	const client = await h.registerClient();
	const { challenge } = h.pkce();
	const folded = h.authorizeUrl( client.client_id, challenge, { state: 'folded' } ).replace( 'action=mcp-oauth-authorize&', 'action=mcp-oauth-authorize?' );
	const { status, location } = await h.consent( ctx, page, folded, true );
	assert.equal( status, 302 );
	assert.equal( new URL( location ).searchParams.get( 'state' ), 'folded' );
	await ctx.close();
} );

test( 'an unregistered redirect URI is refused without redirecting', async () => {
	const { ctx } = await h.adminContext();
	const client = await h.registerClient();
	const { challenge } = h.pkce();
	const r = await ctx.request.get( h.authorizeUrl( client.client_id, challenge, { redirect_uri: 'https://attacker.example/cb' } ), { maxRedirects: 0 } );
	assert.equal( r.status(), 400 );
	assert.match( await r.text(), /redirect URI is not registered/ );
	await ctx.close();
} );

test( 'an unknown client is refused without redirecting', async () => {
	const { ctx } = await h.adminContext();
	const { challenge } = h.pkce();
	const r = await ctx.request.get( h.authorizeUrl( 'nope', challenge ), { maxRedirects: 0 } );
	assert.equal( r.status(), 400 );
	assert.match( await r.text(), /Unknown client/ );
	await ctx.close();
} );

test( 'protocol errors are reported to the client through the redirect URI', async () => {
	const { ctx } = await h.adminContext();
	const client = await h.registerClient();
	const { challenge } = h.pkce();
	const cases = [
		[ { code_challenge: null }, 'invalid_request' ],
		[ { code_challenge_method: 'plain' }, 'invalid_request' ],
		[ { response_type: 'token' }, 'unsupported_response_type' ],
		[ { scope: 'mcp admin' }, 'invalid_scope' ],
		[ { resource: 'https://other.example/wp-json/mcp/x' }, 'invalid_target' ],
	];
	for ( const [ extra, error ] of cases ) {
		const r = await ctx.request.get( h.authorizeUrl( client.client_id, challenge, { state: 's', ...extra } ), { maxRedirects: 0 } );
		assert.equal( r.status(), 302, JSON.stringify( extra ) );
		const target = new URL( r.headers()[ 'location' ] );
		assert.equal( target.origin + target.pathname, h.REDIRECT_URI );
		assert.equal( target.searchParams.get( 'error' ), error, JSON.stringify( extra ) );
		assert.equal( target.searchParams.get( 'state' ), 's' );
	}
	await ctx.close();
} );

test( 'opening the endpoint without an authorization request is an error, not a blank page', async () => {
	const { ctx } = await h.adminContext();
	const r = await ctx.request.get( h.BASE + '/wp-login.php?action=mcp-oauth-authorize', { maxRedirects: 0 } );
	assert.equal( r.status(), 400 );
	await ctx.close();
} );
