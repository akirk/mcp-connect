// Shared helpers for the Playground-backed end-to-end tests.
//
// The tests talk to a WordPress booted by `tests/e2e/run.js` (wp-playground-cli)
// at PLAYGROUND_URL. They drive the login/consent flow in a real browser
// (Playwright, Chromium) and the OAuth/MCP endpoints with plain HTTP.

const crypto = require( 'node:crypto' );
const { chromium } = require( 'playwright' );

const BASE = ( process.env.PLAYGROUND_URL || 'http://127.0.0.1:9400' ).replace( /\/$/, '' );
const MCP_URL = BASE + '/wp-json/mcp/mcp-adapter-default-server';
const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';
const ADMIN = { user: 'admin', pass: 'password' };

let browser;
async function getBrowser() {
	if ( ! browser ) {
		browser = await chromium.launch();
	}
	return browser;
}
async function closeBrowser() {
	if ( browser ) {
		await browser.close();
		browser = null;
	}
}

/** A fresh, cookie-less browser context (what an AI client's backend looks like). */
async function anonContext() {
	return ( await getBrowser() ).newContext();
}

/** A browser context with a page signed in as the Playground admin. */
async function adminContext() {
	const ctx = await ( await getBrowser() ).newContext();
	const page = await ctx.newPage();
	await login( page, ADMIN );
	return { ctx, page };
}

async function login( page, { user, pass } ) {
	await page.goto( BASE + '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
}

async function fetchJson( url, options = {} ) {
	const res = await fetch( url, options );
	let body = null;
	try {
		body = await res.json();
	} catch ( e ) {}
	return { status: res.status, headers: res.headers, body };
}

/** Register a public client via dynamic client registration. */
async function registerClient( overrides = {} ) {
	const { status, body } = await fetchJson( BASE + '/wp-json/mcp-oauth/v1/register', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { client_name: 'Claude', client_uri: 'https://claude.ai', redirect_uris: [ REDIRECT_URI ], ...overrides } ),
	} );
	if ( status !== 201 ) {
		throw new Error( 'registration failed: ' + status + ' ' + JSON.stringify( body ) );
	}
	return body;
}

function pkce() {
	const verifier = crypto.randomBytes( 48 ).toString( 'base64url' );
	const challenge = crypto.createHash( 'sha256' ).update( verifier ).digest( 'base64url' );
	return { verifier, challenge };
}

/** The authorization URL a client would open. */
function authorizeUrl( clientId, challenge, extra = {} ) {
	const params = new URLSearchParams( {
		response_type: 'code',
		client_id: clientId,
		redirect_uri: REDIRECT_URI,
		code_challenge: challenge,
		code_challenge_method: 'S256',
		scope: 'mcp',
		state: 'state-' + crypto.randomBytes( 6 ).toString( 'hex' ),
		...extra,
	} );
	for ( const [ k, v ] of Object.entries( extra ) ) {
		if ( v === null ) {
			params.delete( k );
		}
	}
	return BASE + '/wp-login.php?action=mcp-oauth-authorize&' + params.toString();
}

/**
 * Open the authorization URL in a signed-in page and submit the consent form.
 * Returns the redirect the client would receive (status + Location), without following it.
 */
async function consent( ctx, page, url, approve = true ) {
	await page.goto( url );
	const nonce = await page.inputValue( 'input[name=_wpnonce]' );
	const pending = await page.inputValue( 'input[name=pending]' );
	const form = { _wpnonce: nonce, pending };
	form[ approve ? 'approve' : 'deny' ] = '1';
	const r = await ctx.request.post( BASE + '/wp-login.php?action=mcp-oauth-authorize', { form, maxRedirects: 0 } );
	return { status: r.status(), location: r.headers()[ 'location' ] || '' };
}

/** Exchange an authorization code for tokens (cookie-less, like a real client). */
async function exchange( clientId, code, verifier, extra = {} ) {
	return fetchJson( BASE + '/wp-json/mcp-oauth/v1/token', {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams( { grant_type: 'authorization_code', code, code_verifier: verifier, client_id: clientId, ...extra } ),
	} );
}

async function refresh( clientId, refreshToken ) {
	return fetchJson( BASE + '/wp-json/mcp-oauth/v1/token', {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams( { grant_type: 'refresh_token', refresh_token: refreshToken, client_id: clientId } ),
	} );
}

/** Full happy path: register, authorize as admin, exchange. Returns tokens and the client. */
async function connectedClient( ctxAndPage ) {
	const { ctx, page } = ctxAndPage || ( await adminContext() );
	const client = await registerClient();
	const { verifier, challenge } = pkce();
	const { location } = await consent( ctx, page, authorizeUrl( client.client_id, challenge ) );
	const code = new URL( location ).searchParams.get( 'code' );
	const { body } = await exchange( client.client_id, code, verifier );
	return { client, tokens: body, ctx, page };
}

/** JSON-RPC call against the MCP endpoint with a bearer token; keeps a session. */
function mcpClient( accessToken ) {
	let session = null;
	return async function call( method, params = {}, id = 1 ) {
		const headers = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + accessToken };
		if ( session ) {
			headers[ 'Mcp-Session-Id' ] = session;
		}
		const res = await fetch( MCP_URL, { method: 'POST', headers, body: JSON.stringify( { jsonrpc: '2.0', id, method, params } ) } );
		session = res.headers.get( 'mcp-session-id' ) || session;
		let body = null;
		try {
			body = await res.json();
		} catch ( e ) {}
		return { status: res.status, headers: res.headers, body };
	};
}

async function initialize( call ) {
	return call( 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e', version: '1' } } );
}

async function discoverAbilities( accessToken ) {
	const call = mcpClient( accessToken );
	await initialize( call );
	const { body } = await call( 'tools/call', { name: 'mcp-adapter-discover-abilities', arguments: {} }, 2 );
	return JSON.parse( body.result.content[ 0 ].text ).abilities.map( ( a ) => a.name );
}

module.exports = { BASE, MCP_URL, REDIRECT_URI, ADMIN, getBrowser, closeBrowser, anonContext, adminContext, login, fetchJson, registerClient, pkce, authorizeUrl, consent, exchange, refresh, connectedClient, mcpClient, initialize, discoverAbilities };
