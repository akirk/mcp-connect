const { test, expect } = require( './fixtures' );

const WEBMCP = '/wp-admin/tools.php?page=mcp-connect&tab=webmcp';
const ABILITIES = '/wp-admin/tools.php?page=mcp-connect&tab=abilities';

async function mockWebMCP( page, legacy = false ) {
	await page.addInitScript( ( legacy ) => {
		window.webmcpTools = {};
		const context = legacy ? {
			provideContext( { tools } ) { for ( const tool of tools ) { window.webmcpTools[ tool.name ] = tool; } },
		} : {
			async registerTool( tool ) { window.webmcpTools[ tool.name ] = tool; },
		};
		Object.defineProperty( legacy ? navigator : document, 'modelContext', { value: context } );
	}, legacy );
}

for ( const legacy of [ false, true ] ) {
	test( `WebMCP registers and executes abilities through ${ legacy ? 'provideContext' : 'registerTool' }`, async ( { adminPage } ) => {
		await mockWebMCP( adminPage, legacy );
		await adminPage.goto( WEBMCP );
		await expect.poll( () => adminPage.evaluate( () => Object.keys( window.webmcpTools ) ) ).toContain( 'demo_list-trips' );
		const names = await adminPage.evaluate( () => Object.keys( window.webmcpTools ) );
		expect( names ).not.toContain( 'demo_opted-out' );
		expect( names.some( ( name ) => name.startsWith( 'mcp-adapter_' ) ) ).toBe( false );
		expect( names ).toContain( 'wp_search-posts' );
		const result = await adminPage.evaluate( () => window.webmcpTools[ 'demo_list-trips' ].execute( {} ) );
		expect( JSON.parse( result.content[ 0 ].text ) ).toEqual( { trips: [ 'Vienna', 'Lisbon' ] } );
	} );
}

test( 'the shared Abilities list uses one visibility control for MCP and WebMCP', async ( { adminPage, request } ) => {
	await adminPage.goto( WEBMCP );
	await expect( adminPage.locator( 'tr[data-ability]' ), 'WebMCP settings do not repeat the ability list' ).toHaveCount( 0 );
	await adminPage.goto( ABILITIES );
	await expect( adminPage.locator( '#mcp-oauth-abilities-table thead th' ) ).toHaveText( [ 'Ability', 'What it does' ] );
	await adminPage.locator( '#mcp-oauth-ability-search' ).fill( 'list trips' );
	await expect( adminPage.locator( '.mcp-oauth-ability:visible' ) ).toHaveCount( 1 );
	await adminPage.locator( '#mcp-oauth-ability-search' ).fill( 'no-such-ability' );
	await expect( adminPage.locator( '#mcp-oauth-no-abilities' ) ).toBeVisible();
	await adminPage.locator( '#mcp-oauth-ability-search' ).fill( '' );
	await adminPage.locator( '#mcp-oauth-ability-filter' ).selectOption( 'hidden' );
	await expect( adminPage.locator( '.mcp-oauth-ability:visible' ) ).toHaveCount( 1 );
	await adminPage.locator( '#mcp-oauth-ability-filter' ).selectOption( 'all' );
	const row = adminPage.locator( 'tr[data-ability="demo/list-trips"]' );
	await expect( row.locator( '.mcp-oauth-ability-description' ) ).not.toBeEmpty();
	await expect( row.locator( 'select' ) ).toHaveCount( 0 );
	await expect( row.locator( '.mcp-oauth-tool' ) ).not.toBeVisible();
	const eye = row.locator( '.mcp-oauth-eye' );
	await eye.click();
	await expect( eye ).toHaveAttribute( 'data-visible', '0' );
	await adminPage.reload();
	await expect( eye ).toHaveAttribute( 'data-visible', '0' );
	const config = await adminPage.evaluate( () => window.wmcpBridge );
	const hidden = await adminPage.request.post( '/wp-json/webmcp/v1/execute/demo%2Flist-trips', {
		headers: { 'X-WP-Nonce': config.restNonce, 'X-WMCP-Nonce': config.nonce }, data: {},
	} );
	expect( hidden.status() ).toBe( 404 );
	await eye.click();
	await expect( eye ).toHaveAttribute( 'data-visible', '1' );
	const anonymous = await request.get( '/wp-json/webmcp/v1/tools' );
	expect( anonymous.status() ).toBe( 401 );
	const noNonce = await adminPage.request.post( '/wp-json/webmcp/v1/execute/demo/list-trips', {
		headers: { 'X-WP-Nonce': config.restNonce }, data: {},
	} );
	expect( noNonce.status() ).toBe( 403 );
	const deniedToggle = await request.post( '/wp-json/webmcp/v1/abilities/visibility', { data: { ability: 'demo/list-trips', hide: true } } );
	expect( deniedToggle.status() ).toBe( 401 );

	await adminPage.goto( WEBMCP );
	await adminPage.locator( '#wmcp_enabled' ).uncheck();
	await adminPage.locator( '#submit' ).click();
	await expect( adminPage.locator( '#wmcp_enabled' ) ).not.toBeChecked();
	expect( ( await request.get( '/wp-json/webmcp/v1/tools' ) ).status() ).toBe( 404 );
	await adminPage.locator( '#wmcp_enabled' ).check();
	await adminPage.locator( '#submit' ).click();
	await expect( adminPage.locator( '#wmcp_enabled' ) ).toBeChecked();
} );

test( 'logged-out discovery has no admin switch', async ( { adminPage, request } ) => {
	await adminPage.goto( WEBMCP );
	await expect( adminPage.locator( '#wmcp_discovery_public' ) ).toHaveCount( 0 );
	await expect( adminPage.locator( 'a[href="https://github.com/code-atlantic/webmcp-abilities"]' ) ).toHaveCount( 0 );
	await expect( adminPage.locator( '.mcp-oauth' ) ).not.toContainText( 'Registered abilities:' );
	expect( ( await request.get( '/wp-json/webmcp/v1/tools' ) ).status() ).toBe( 401 );
	expect( ( await request.post( '/wp-json/webmcp/v1/abilities/anonymous', { data: { ability: 'wp/search-posts', anonymous: true } } ) ).status() ).toBe( 404 );
} );

test( 'the anonymous filter enables both protocols while ability permissions still apply', async ( { request } ) => {
	const headers = { 'X-Demo-Anonymous': '1' };
	const discovery = await request.get( '/wp-json/webmcp/v1/tools', { headers } );
	expect( discovery.status() ).toBe( 200 );
	const names = ( await discovery.json() ).tools.map( ( tool ) => tool.name );
	expect( names ).toContain( 'wp/search-posts' );
	expect( names ).not.toContain( 'demo/list-trips' );
	const endpoint = '/wp-json/mcp/mcp-adapter-default-server';
	const init = await request.post( endpoint, { headers, data: { jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'anonymous-test', version: '1' } } } } );
	expect( init.status() ).toBe( 200 );
	const session = init.headers()[ 'mcp-session-id' ];
	const rpcHeaders = { ...headers, ...( session ? { 'Mcp-Session-Id': session } : {} ) };
	const call = async ( name, args ) => request.post( endpoint, { headers: rpcHeaders, data: { jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name, arguments: args } } } );
	const allowed = await call( 'wp-search-posts', { query: 'Hello' } );
	expect( allowed.status() ).toBe( 200 );
	expect( ( await allowed.json() ).result.isError ).not.toBe( true );
	const denied = await call( 'demo-list-trips', {} );
	expect( ( await denied.json() ).result.isError ).toBe( true );
	const invalid = await request.post( endpoint, { headers: { ...headers, Authorization: 'Bearer invalid-token' }, data: { jsonrpc: '2.0', id: 3, method: 'tools/list' } } );
	expect( invalid.status() ).toBe( 401 );
} );

test( 'the standalone WebMCP plugin owns registration when both are active', async ( { adminPage } ) => {
	test.skip( ! process.env.WEBMCP_PLUGIN_DIR, 'Requires the standalone WebMCP plugin fixture.' );
	await adminPage.goto( WEBMCP );
	await expect( adminPage.locator( '.nav-tab-active' ) ).toHaveText( 'WebMCP' );
	await expect( adminPage.locator( 'script[src*="/webmcp-abilities/dist/webmcp-abilities.js"]' ) ).toHaveCount( 1 );
	await expect( adminPage.locator( 'script[src*="/mcp-connect/assets/webmcp.js"]' ) ).toHaveCount( 0 );
	const config = await adminPage.evaluate( () => window.wmcpBridge );
	const discovery = await adminPage.request.get( '/wp-json/webmcp/v1/tools', { headers: { 'X-WP-Nonce': config.restNonce } } );
	expect( discovery.status() ).toBe( 200 );
	const names = ( await discovery.json() ).tools.map( ( tool ) => tool.name );
	expect( names.filter( ( name ) => name === 'wp/search-posts' ) ).toHaveLength( 1 );
} );
