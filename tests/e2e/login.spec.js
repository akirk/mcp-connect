// The sign-in and consent flow on wp-login.php.
const { test, expect, ADMIN, REDIRECT_URI } = require( './fixtures' );

test( 'a signed-out visitor gets the login form and returns to the authorization request afterwards', async ( { page, oauth, baseURL } ) => {
	const client = await oauth.registerClient();
	const url = oauth.authorizeUrl( client.client_id, oauth.pkce().challenge );

	await page.goto( url );
	expect( page.url(), 'redirected to the login form' ).toMatch( new RegExp( '^' + baseURL + '/wp-login.php\\?redirect_to=' ) );
	await expect( page.locator( '#loginform' ) ).toBeVisible();
	expect( new URL( page.url() ).searchParams.get( 'redirect_to' ), 'the whole request, including the encoded redirect_uri, is carried through' ).toBe( url );

	await page.fill( '#user_login', ADMIN.user );
	await page.fill( '#user_pass', ADMIN.pass );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

	expect( page.url(), 'back on the authorization request after signing in' ).toBe( url );
	const form = page.locator( 'form.mcp-oauth-consent' );
	await expect( form ).toBeVisible();
	await expect( form.locator( 'h2' ) ).toHaveText( /Claude wants to connect to this site/ );
	await expect( form ).toContainText( 'admin' );
	await expect( form ).toContainText( 'mcp-adapter-default-server' );
	await expect( page.locator( '#login h1 a' ), 'drawn with the login page chrome' ).toBeAttached();
	await expect( page.locator( '#nav a' ), 'offers to switch accounts' ).toHaveAttribute( 'href', /action=logout/ );
} );

test( 'approving returns a code and the state to the registered redirect URI', async ( { adminPage, oauth } ) => {
	const client = await oauth.registerClient();
	const { status, location } = await oauth.consent( adminPage, oauth.authorizeUrl( client.client_id, oauth.pkce().challenge, { state: 'xyz 123' } ) );
	expect( status ).toBe( 302 );
	const target = new URL( location );
	expect( target.origin + target.pathname ).toBe( REDIRECT_URI );
	expect( target.searchParams.get( 'code' ) ).toMatch( /^[0-9a-f]{64}$/ );
	expect( target.searchParams.get( 'state' ) ).toBe( 'xyz 123' );
} );

test( 'denying sends access_denied back to the client', async ( { adminPage, oauth } ) => {
	const client = await oauth.registerClient();
	const { status, location } = await oauth.consent( adminPage, oauth.authorizeUrl( client.client_id, oauth.pkce().challenge, { state: 's1' } ), false );
	expect( status ).toBe( 302 );
	const target = new URL( location );
	expect( target.searchParams.get( 'error' ) ).toBe( 'access_denied' );
	expect( target.searchParams.get( 'state' ) ).toBe( 's1' );
	expect( target.searchParams.get( 'code' ) ).toBeNull();
} );

test( 'a consent token can only be used once', async ( { adminPage, oauth } ) => {
	const client = await oauth.registerClient();
	await adminPage.goto( oauth.authorizeUrl( client.client_id, oauth.pkce().challenge ) );
	const form = { _wpnonce: await adminPage.inputValue( 'input[name=_wpnonce]' ), pending: await adminPage.inputValue( 'input[name=pending]' ), approve: '1' };
	const endpoint = oauth.url( '/wp-login.php?action=mcp-oauth-authorize' );
	expect( ( await adminPage.request.post( endpoint, { form, maxRedirects: 0 } ) ).status() ).toBe( 302 );
	const replay = await adminPage.request.post( endpoint, { form, maxRedirects: 0 } );
	expect( replay.status(), 'replaying the consent form fails' ).toBe( 400 );
	expect( await replay.text() ).toMatch( /expired/ );
} );

test( 'a forged consent form without a valid nonce is refused', async ( { adminPage, oauth } ) => {
	const client = await oauth.registerClient();
	await adminPage.goto( oauth.authorizeUrl( client.client_id, oauth.pkce().challenge ) );
	const pending = await adminPage.inputValue( 'input[name=pending]' );
	const r = await adminPage.request.post( oauth.url( '/wp-login.php?action=mcp-oauth-authorize' ), { form: { _wpnonce: 'bogus', pending, approve: '1' }, maxRedirects: 0 } );
	expect( r.status() ).toBe( 403 );
} );

test( 'the folded "?action=…?params" URL some clients build still works', async ( { adminPage, oauth } ) => {
	const client = await oauth.registerClient();
	const folded = oauth.authorizeUrl( client.client_id, oauth.pkce().challenge, { state: 'folded' } ).replace( 'action=mcp-oauth-authorize&', 'action=mcp-oauth-authorize?' );
	const { status, location } = await oauth.consent( adminPage, folded );
	expect( status ).toBe( 302 );
	expect( new URL( location ).searchParams.get( 'state' ) ).toBe( 'folded' );
} );

test( 'an unregistered redirect URI is refused without redirecting', async ( { adminPage, oauth } ) => {
	const client = await oauth.registerClient();
	const r = await adminPage.request.get( oauth.authorizeUrl( client.client_id, oauth.pkce().challenge, { redirect_uri: 'https://attacker.example/cb' } ), { maxRedirects: 0 } );
	expect( r.status() ).toBe( 400 );
	expect( await r.text() ).toMatch( /redirect URI is not registered/ );
} );

test( 'an unknown client is refused without redirecting', async ( { adminPage, oauth } ) => {
	const r = await adminPage.request.get( oauth.authorizeUrl( 'nope', oauth.pkce().challenge ), { maxRedirects: 0 } );
	expect( r.status() ).toBe( 400 );
	expect( await r.text() ).toMatch( /Unknown client/ );
} );

test( 'protocol errors are reported to the client through the redirect URI', async ( { adminPage, oauth } ) => {
	const client = await oauth.registerClient();
	const { challenge } = oauth.pkce();
	const cases = [
		[ { code_challenge: null }, 'invalid_request' ],
		[ { code_challenge_method: 'plain' }, 'invalid_request' ],
		[ { response_type: 'token' }, 'unsupported_response_type' ],
		[ { scope: 'mcp admin' }, 'invalid_scope' ],
		[ { resource: 'https://other.example/wp-json/mcp/x' }, 'invalid_target' ],
	];
	for ( const [ extra, error ] of cases ) {
		const r = await adminPage.request.get( oauth.authorizeUrl( client.client_id, challenge, { state: 's', ...extra } ), { maxRedirects: 0 } );
		expect( r.status(), JSON.stringify( extra ) ).toBe( 302 );
		const target = new URL( r.headers()[ 'location' ] );
		expect( target.origin + target.pathname ).toBe( REDIRECT_URI );
		expect( target.searchParams.get( 'error' ), JSON.stringify( extra ) ).toBe( error );
		expect( target.searchParams.get( 'state' ) ).toBe( 's' );
	}
} );

test( 'opening the endpoint without an authorization request is an error, not a blank page', async ( { adminPage, oauth } ) => {
	const r = await adminPage.request.get( oauth.url( '/wp-login.php?action=mcp-oauth-authorize' ), { maxRedirects: 0 } );
	expect( r.status() ).toBe( 400 );
} );
