// The MCP Connect page, ability visibility and Site Health.
const { test, expect } = require( './fixtures' );

const CONNECT = '/wp-admin/tools.php?page=mcp-connect';

test( 'the Connect page shows the endpoint and a tab per client', async ( { adminPage, oauth } ) => {
	await adminPage.goto( CONNECT );
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
	await adminPage.goto( CONNECT );
	const row = adminPage.locator( 'table.widefat tbody tr', { hasText: 'Claude' } ).first();
	await expect( row, 'the connection appears' ).toBeVisible();
	await Promise.all( [ adminPage.waitForNavigation(), row.locator( 'button:has-text("Revoke")' ).click() ] );
	await expect( adminPage.locator( '.notice-success' ) ).toContainText( 'Access revoked' );
	expect( ( await oauth.mcp( tokens.access_token ).initialize() ).status ).toBe( 401 );
} );

test( 'abilities are exposed by default and can be hidden with the eye toggle', async ( { adminPage, oauth, request } ) => {
	const { tokens } = await oauth.connect( adminPage );
	expect( await oauth.discoverAbilities( tokens.access_token ), 'a non-"public" ability is discoverable' ).toContain( 'demo/list-trips' );
	expect( await oauth.discoverAbilities( tokens.access_token ), 'meta.mcp.public=false stays hidden' ).not.toContain( 'demo/opted-out' );

	await adminPage.goto( CONNECT );
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
