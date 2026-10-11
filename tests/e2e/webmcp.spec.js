const { test, expect } = require( './fixtures' );

const WEBMCP = '/wp-admin/tools.php?page=mcp-connect&tab=webmcp';

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

test( 'WebMCP settings and visibility persist and block execution', async ( { adminPage, request } ) => {
	await adminPage.goto( WEBMCP );
	const row = adminPage.locator( '.wmcp tr[data-ability="demo/list-trips"]' );
	const eye = row.locator( '.wmcp-eye' );
	await expect( adminPage.locator( '.wmcp tr[data-ability="demo/opted-out"] .wmcp-eye' ) ).toBeDisabled();
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

	await adminPage.locator( '#wmcp_enabled' ).uncheck();
	await adminPage.locator( '#submit' ).click();
	await expect( adminPage.locator( '#wmcp_enabled' ) ).not.toBeChecked();
	expect( ( await request.get( '/wp-json/webmcp/v1/tools' ) ).status() ).toBe( 404 );
	await adminPage.locator( '#wmcp_enabled' ).check();
	await adminPage.locator( '#submit' ).click();
	await expect( adminPage.locator( '#wmcp_enabled' ) ).toBeChecked();
} );

test( 'public discovery only exposes public abilities and input validation remains enforced', async ( { adminPage, page } ) => {
	await adminPage.goto( WEBMCP );
	await adminPage.locator( '#wmcp_discovery_public' ).check();
	await adminPage.locator( '#submit' ).click();
	await mockWebMCP( page );
	await page.goto( '/' );
	await expect.poll( () => page.evaluate( () => Object.keys( window.webmcpTools ) ) ).toContain( 'wp_search-posts' );
	expect( await page.evaluate( () => Object.keys( window.webmcpTools ) ) ).not.toContain( 'demo_list-trips' );
	const result = await page.evaluate( () => window.webmcpTools[ 'wp_search-posts' ].execute( { query: 'Hello' } ) );
	expect( Array.isArray( JSON.parse( result.content[ 0 ].text ) ) ).toBe( true );
	const invalid = await page.evaluate( async () => {
		try { await window.webmcpTools[ 'wp_search-posts' ].execute( {} ); return null; } catch ( error ) { return error.message; }
	} );
	expect( invalid ).toBeTruthy();
	await adminPage.locator( '#wmcp_discovery_public' ).uncheck();
	await adminPage.locator( '#submit' ).click();
	// Previously discovered definitions must not be registered after logging out or disabling discovery.
	const deniedDiscovery = page.waitForResponse( ( response ) => response.url().includes( 'webmcp/v1/tools' ) );
	await page.reload();
	expect( ( await deniedDiscovery ).status() ).toBe( 401 );
	expect( await page.evaluate( () => Object.keys( window.webmcpTools ) ) ).toEqual( [] );
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
