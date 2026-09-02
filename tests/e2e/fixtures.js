// Playwright fixtures and OAuth helpers shared by the e2e specs.
//
// `adminPage` is a page signed in as the Playground admin (the browser side of
// the flow). `oauth` is what an AI client's backend does: register, build the
// authorization URL, exchange codes, call the MCP endpoint — all cookie-less.

const crypto = require( 'node:crypto' );
const base = require( 'playwright/test' );

const ADMIN = { user: 'admin', pass: 'password' };
const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

function pkce() {
	const verifier = crypto.randomBytes( 48 ).toString( 'base64url' );
	const challenge = crypto.createHash( 'sha256' ).update( verifier ).digest( 'base64url' );
	return { verifier, challenge };
}

async function login( page, { user, pass } = ADMIN ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
}

/** OAuth/MCP helpers bound to a base URL and a cookie-less request context. */
function oauthHelpers( baseURL, request ) {
	const url = ( path ) => baseURL + path;
	const mcpUrl = url( '/wp-json/mcp/mcp-adapter-default-server' );

	async function json( response ) {
		try {
			return await response.json();
		} catch ( e ) {
			return null;
		}
	}

	async function registerClient( overrides = {} ) {
		const r = await request.post( url( '/wp-json/mcp-oauth/v1/register' ), {
			data: { client_name: 'Claude', client_uri: 'https://claude.ai', redirect_uris: [ REDIRECT_URI ], ...overrides },
		} );
		if ( r.status() !== 201 ) {
			throw new Error( 'registration failed: ' + r.status() + ' ' + ( await r.text() ) );
		}
		return r.json();
	}

	/** The authorization URL a client would open. `null` removes a parameter. */
	function authorizeUrl( clientId, challenge, extra = {} ) {
		const params = new URLSearchParams( {
			response_type: 'code',
			client_id: clientId,
			redirect_uri: REDIRECT_URI,
			code_challenge: challenge,
			code_challenge_method: 'S256',
			scope: 'mcp',
			state: 'state-' + crypto.randomBytes( 6 ).toString( 'hex' ),
		} );
		for ( const [ k, v ] of Object.entries( extra ) ) {
			if ( v === null ) {
				params.delete( k );
			} else {
				params.set( k, v );
			}
		}
		return url( '/wp-login.php?action=mcp-oauth-authorize&' + params.toString() );
	}

	/**
	 * Open an authorization URL in a signed-in page and submit the consent form
	 * through the page's own cookies; returns the redirect the client would get.
	 */
	async function consent( page, authorizationUrl, approve = true ) {
		await page.goto( authorizationUrl );
		const form = { _wpnonce: await page.inputValue( 'input[name=_wpnonce]' ), pending: await page.inputValue( 'input[name=pending]' ) };
		form[ approve ? 'approve' : 'deny' ] = '1';
		const r = await page.request.post( url( '/wp-login.php?action=mcp-oauth-authorize' ), { form, maxRedirects: 0 } );
		return { status: r.status(), location: r.headers()[ 'location' ] || '', text: () => r.text() };
	}

	async function tokenRequest( form ) {
		const r = await request.post( url( '/wp-json/mcp-oauth/v1/token' ), { form } );
		return { status: r.status(), headers: r.headers(), body: await json( r ) };
	}
	const exchange = ( clientId, code, verifier, extra = {} ) => tokenRequest( { grant_type: 'authorization_code', code, code_verifier: verifier, client_id: clientId, ...extra } );
	const refresh = ( clientId, refreshToken ) => tokenRequest( { grant_type: 'refresh_token', refresh_token: refreshToken, client_id: clientId } );

	/** Register, authorize in the given signed-in page, exchange. */
	async function connect( page ) {
		const client = await registerClient();
		const { verifier, challenge } = pkce();
		const { location } = await consent( page, authorizeUrl( client.client_id, challenge ) );
		const code = new URL( location ).searchParams.get( 'code' );
		const { body: tokens } = await exchange( client.client_id, code, verifier );
		return { client, tokens };
	}

	/** JSON-RPC calls against the MCP endpoint with a bearer token; keeps the session id. */
	function mcp( accessToken ) {
		let session = null;
		const call = async ( method, params = {}, id = 1 ) => {
			const headers = { Authorization: 'Bearer ' + accessToken };
			if ( session ) {
				headers[ 'Mcp-Session-Id' ] = session;
			}
			const r = await request.post( mcpUrl, { headers, data: { jsonrpc: '2.0', id, method, params } } );
			session = r.headers()[ 'mcp-session-id' ] || session;
			return { status: r.status(), headers: r.headers(), body: await json( r ) };
		};
		call.initialize = () => call( 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e', version: '1' } } );
		return call;
	}

	/** The tool names the server advertises. A promoted `travel/x` arrives as `travel-x`. */
	async function listTools( accessToken ) {
		const call = mcp( accessToken );
		await call.initialize();
		const { body } = await call( 'tools/list', {}, 2 );
		return body.result.tools.map( ( t ) => t.name );
	}

	/** The ability names reachable through the adapter's discover meta-tool. */
	async function discoverAbilities( accessToken ) {
		const call = mcp( accessToken );
		await call.initialize();
		const { body } = await call( 'tools/call', { name: 'mcp-adapter-discover-abilities', arguments: {} }, 2 );
		return JSON.parse( body.result.content[ 0 ].text ).abilities.map( ( a ) => a.name );
	}

	return { url, mcpUrl, registerClient, pkce, authorizeUrl, consent, exchange, refresh, connect, mcp, listTools, discoverAbilities };
}

const test = base.test.extend( {
	// A page signed in as the site administrator.
	adminPage: async ( { browser }, use ) => {
		const context = await browser.newContext();
		const page = await context.newPage();
		await login( page );
		await use( page );
		await context.close();
	},
	// Cookie-less OAuth/MCP helpers, like an AI client's backend.
	oauth: async ( { baseURL, playwright }, use ) => {
		const request = await playwright.request.newContext();
		await use( oauthHelpers( baseURL, request ) );
		await request.dispose();
	},
} );

module.exports = { test, expect: base.expect, ADMIN, REDIRECT_URI, login, pkce };
