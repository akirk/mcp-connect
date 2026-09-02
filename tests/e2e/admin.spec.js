// The MCP Connect page, ability visibility and Site Health.
const { test, expect } = require( './fixtures' );

const CONNECT = '/wp-admin/tools.php?page=mcp-connect';
const ABILITIES = CONNECT + '&tab=abilities';
const TOOLS = CONNECT + '&tab=tools';
const CONNECTIONS = CONNECT + '&tab=connections';

test( 'the Connect page shows the endpoint and a tab per client', async ( { adminPage, oauth } ) => {
	await adminPage.goto( CONNECT );
	await expect( adminPage.locator( '.nav-tab-active' ) ).toHaveText( 'Connect' );
	await expect( adminPage.locator( '.mcp-oauth-playground-notice' ), 'the suite runs in Playground, which is detected' ).toContainText( 'WordPress Playground' );
	await expect( adminPage.locator( '.mcp-oauth-playground-notice a' ).first() ).toHaveAttribute( 'href', /make\.wordpress\.org\/playground\/.*mcp/ );
	await expect( adminPage.locator( '.mcp-oauth-playground-notice a' ).last() ).toHaveAttribute( 'href', 'https://developer.wordpress.org/playground/developers/apis/query-api/' );
	await expect( adminPage.locator( '.mcp-oauth-playground-notice + .nav-tab-wrapper, .mcp-oauth-playground-notice ~ .nav-tab-wrapper' ), 'the notice sits above the tabs' ).toBeVisible();
	await adminPage.click( '.mcp-oauth-tab:has-text("Any other MCP client")' );
	await expect( adminPage.locator( '#mcp-oauth-url' ) ).toHaveText( oauth.mcpUrl );
	const tabs = await adminPage.locator( '.mcp-oauth-tab' ).allTextContents();
	for ( const name of [ 'Claude.ai', 'Claude Desktop', 'Claude Code', 'ChatGPT', 'Cursor' ] ) {
		expect( tabs.map( ( t ) => t.trim() ) ).toContain( name );
	}
	await adminPage.click( '.mcp-oauth-tab:has-text("Claude.ai")' );
	const link = adminPage.locator( '#mcp-oauth-client-claude-ai a.button-primary' );
	await expect( link ).toHaveAttribute( 'href', /^https:\/\/claude\.ai\/customize\/connectors\?modal=add-custom-connector/ );
	expect( await link.getAttribute( 'href' ) ).toContain( encodeURIComponent( oauth.mcpUrl ) );
} );

test( 'connections are listed and can be revoked', async ( { adminPage, oauth } ) => {
	const { tokens } = await oauth.connect( adminPage );
	await adminPage.goto( CONNECTIONS );
	const row = adminPage.locator( 'table.widefat tbody tr', { hasText: 'Claude' } ).first();
	await expect( row, 'the connection appears' ).toBeVisible();
	await Promise.all( [ adminPage.waitForNavigation(), row.locator( 'button:has-text("Revoke")' ).click() ] );
	await expect( adminPage.locator( '.notice-success' ) ).toContainText( 'Access revoked' );
	await expect( adminPage.locator( '.nav-tab-active' ), 'stays on the Connections tab' ).toHaveText( 'Connections' );
	expect( ( await oauth.mcp( tokens.access_token ).initialize() ).status ).toBe( 401 );
} );

test( 'abilities are exposed by default and can be hidden with the eye toggle', async ( { adminPage, oauth, request } ) => {
	const { tokens } = await oauth.connect( adminPage );
	expect( await oauth.discoverAbilities( tokens.access_token ), 'a non-"public" ability is discoverable' ).toContain( 'demo/list-trips' );
	expect( await oauth.discoverAbilities( tokens.access_token ), 'meta.mcp.public=false stays hidden' ).not.toContain( 'demo/opted-out' );

	await adminPage.goto( ABILITIES );
	await expect( adminPage.locator( 'tr[data-ability="mcp-adapter/discover-abilities"]' ), 'the adapter’s own abilities are listed even when the registry initialized before the adapter' ).toBeVisible();
	const row = adminPage.locator( 'tr[data-ability="demo/list-trips"]' );
	const eye = row.locator( '.mcp-oauth-eye' );
	await expect( adminPage.locator( 'tr[data-ability="demo/opted-out"] .mcp-oauth-eye' ), 'opted-out abilities have no toggle' ).toHaveCount( 0 );
	const before = Number( await adminPage.textContent( '#mcp-oauth-visible-count' ) );

	const hide = adminPage.waitForResponse( ( r ) => r.url().includes( 'abilities/visibility' ) );
	await eye.click();
	expect( ( await hide ).status() ).toBe( 200 );
	await expect( eye ).toHaveAttribute( 'data-visible', '0' );
	await expect( adminPage.locator( '#mcp-oauth-visible-count' ) ).toHaveText( String( before - 1 ) );
	await expect( row.locator( '.mcp-oauth-reason' ) ).toHaveText( 'hidden here' );
	await expect( row.locator( '.mcp-oauth-reason' ) ).toHaveClass( /is-override/ );
	expect( await oauth.discoverAbilities( tokens.access_token ), 'hidden from clients' ).not.toContain( 'demo/list-trips' );

	const show = adminPage.waitForResponse( ( r ) => r.url().includes( 'abilities/visibility' ) );
	await eye.click();
	await show;
	await expect( eye ).toHaveAttribute( 'data-visible', '1' );
	expect( await oauth.discoverAbilities( tokens.access_token ), 'visible again' ).toContain( 'demo/list-trips' );

	const anon = await request.post( oauth.url( '/wp-json/mcp-oauth/v1/abilities/visibility' ), { data: { ability: 'demo/list-trips', hide: true } } );
	expect( anon.status(), 'anonymous callers cannot toggle' ).toBe( 401 );
} );

test( 'abilities can be promoted to tools of their own with the Tool checkbox', async ( { adminPage, oauth } ) => {
	const { tokens } = await oauth.connect( adminPage );
	expect( await oauth.listTools( tokens.access_token ), 'nothing is promoted by default' ).toEqual( [
		'mcp-adapter-discover-abilities',
		'mcp-adapter-get-ability-info',
		'mcp-adapter-execute-ability',
	] );

	await adminPage.goto( ABILITIES );
	await expect( adminPage.locator( '#mcp-oauth-direct-count' ) ).toHaveText( '0' );
	await expect( adminPage.locator( 'tr[data-ability="demo/opted-out"] .mcp-oauth-tool' ), 'a hidden ability cannot be a tool' ).toBeDisabled();

	const tick = adminPage.locator( 'tr[data-ability="demo/list-trips"] .mcp-oauth-tool' );
	const promote = adminPage.waitForResponse( ( r ) => r.url().includes( 'abilities/tool' ) );
	await tick.check();
	expect( ( await promote ).status() ).toBe( 200 );
	await expect( adminPage.locator( '#mcp-oauth-direct-count' ) ).toHaveText( '1' );
	expect( await oauth.listTools( tokens.access_token ), 'the promoted ability is a tool of its own' ).toContain( 'demo-list-trips' );
	expect( await oauth.listTools( tokens.access_token ), 'the meta-tools stay' ).toContain( 'mcp-adapter-execute-ability' );

	const demote = adminPage.waitForResponse( ( r ) => r.url().includes( 'abilities/tool' ) );
	await tick.uncheck();
	await demote;
	await expect( adminPage.locator( '#mcp-oauth-direct-count' ) ).toHaveText( '0' );
	expect( await oauth.listTools( tokens.access_token ), 'back to the meta-tools alone' ).not.toContain( 'demo-list-trips' );
} );

test( 'the Tools tab shows what the server hands to a client', async ( { adminPage } ) => {
	await adminPage.goto( TOOLS );
	for ( const meta of [ 'discover-abilities', 'get-ability-info', 'execute-ability' ] ) {
		const row = adminPage.locator( `tr[data-tool="mcp-adapter-${ meta }"]` );
		await expect( row, 'the meta-tools are always listed' ).toContainText( `mcp-adapter/${ meta }` );
	}
	await expect( adminPage.locator( 'tr[data-tool="demo-list-trips"]' ), 'nothing is promoted yet' ).toHaveCount( 0 );

	await adminPage.goto( ABILITIES );
	const promote = adminPage.waitForResponse( ( r ) => r.url().includes( 'abilities/tool' ) );
	await adminPage.locator( 'tr[data-ability="demo/list-trips"] .mcp-oauth-tool' ).check();
	await promote;

	await adminPage.goto( TOOLS );
	const row = adminPage.locator( 'tr[data-tool="demo-list-trips"]' );
	await expect( row, 'the ability behind the tool is named' ).toContainText( 'demo/list-trips' );
	await expect( row, 'and its description comes along' ).toContainText( 'Lists demo trips.' );

	await adminPage.goto( ABILITIES );
	const demote = adminPage.waitForResponse( ( r ) => r.url().includes( 'abilities/tool' ) );
	await adminPage.locator( 'tr[data-ability="demo/list-trips"] .mcp-oauth-tool' ).uncheck();
	await demote;
} );

test( 'the Site Health test passes', async ( { adminPage, oauth } ) => {
	await adminPage.goto( '/wp-admin/' );
	const nonce = await adminPage.evaluate( () => window.wpApiSettings.nonce );
	const r = await adminPage.request.get( oauth.url( '/wp-json/mcp-oauth/v1/health' ), { headers: { 'X-WP-Nonce': nonce } } );
	expect( r.status() ).toBe( 200 );
	const result = await r.json();
	expect( result, JSON.stringify( result ) ).toMatchObject( { status: 'good', test: 'mcp_oauth' } );
	expect( result.description ).toMatch( /Protected-resource metadata/ );

	await adminPage.goto( '/wp-admin/site-health.php' );
	const trigger = adminPage.locator( 'button[aria-controls="health-check-accordion-block-mcp_oauth"]' );
	await expect( trigger ).toBeAttached( { timeout: 60000 } );
	expect( await trigger.evaluate( ( el ) => el.closest( '.health-check-accordion' ).parentElement.id ) ).toBe( 'health-check-issues-good' );
} );
