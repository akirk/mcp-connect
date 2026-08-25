// The MCP Connect page, ability visibility and Site Health.
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const h = require( './helpers' );

test.after( h.closeBrowser );

const CONNECT = h.BASE + '/wp-admin/tools.php?page=mcp-oauth';

test( 'the Connect page shows the endpoint and a tab per client', async () => {
	const { ctx, page } = await h.adminContext();
	await page.goto( CONNECT );
	assert.equal( ( await page.textContent( '#mcp-oauth-url' ) ).trim(), h.MCP_URL );
	const tabs = await page.$$eval( '.mcp-oauth-tab', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
	for ( const name of [ 'Claude.ai', 'Claude Desktop', 'Claude Code', 'ChatGPT', 'Cursor' ] ) {
		assert.ok( tabs.includes( name ), name );
	}
	await page.click( '.mcp-oauth-tab:has-text("Claude.ai")' );
	const link = await page.getAttribute( '#mcp-oauth-client-claude-ai a.button-primary', 'href' );
	assert.ok( link.startsWith( 'https://claude.ai/customize/connectors?modal=add-custom-connector' ) );
	assert.ok( link.includes( encodeURIComponent( h.MCP_URL ) ) );
	await ctx.close();
} );

test( 'connections are listed and can be revoked', async () => {
	const admin = await h.adminContext();
	const { tokens } = await h.connectedClient( admin );
	const { ctx, page } = admin;
	await page.goto( CONNECT );
	const row = page.locator( 'table.widefat tbody tr', { hasText: 'Claude' } ).first();
	assert.ok( await row.count(), 'the connection appears' );
	await Promise.all( [ page.waitForNavigation(), row.locator( 'button:has-text("Revoke")' ).click() ] );
	assert.match( await page.textContent( '.notice-success' ), /Access revoked/ );
	assert.equal( ( await h.initialize( h.mcpClient( tokens.access_token ) ) ).status, 401 );
	await ctx.close();
} );

test( 'abilities are exposed by default and can be hidden with the eye toggle', async () => {
	const admin = await h.adminContext();
	const { tokens, ctx, page } = await h.connectedClient( admin );
	assert.ok( ( await h.discoverAbilities( tokens.access_token ) ).includes( 'demo/list-trips' ), 'a non-"public" ability is discoverable' );
	assert.ok( ! ( await h.discoverAbilities( tokens.access_token ) ).includes( 'demo/opted-out' ), 'meta.mcp.public=false stays hidden' );

	await page.goto( CONNECT );
	const eye = page.locator( 'tr[data-ability="demo/list-trips"] .mcp-oauth-eye' );
	assert.equal( await page.locator( 'tr[data-ability="demo/opted-out"] .mcp-oauth-eye' ).count(), 0, 'opted-out abilities have no toggle' );
	const before = Number( await page.textContent( '#mcp-oauth-visible-count' ) );

	const response = page.waitForResponse( ( r ) => r.url().includes( 'abilities/visibility' ) );
	await eye.click();
	assert.equal( ( await response ).status(), 200 );
	await page.waitForFunction( () => document.querySelector( 'tr[data-ability="demo/list-trips"] .mcp-oauth-eye' ).dataset.visible === '0' );
	assert.equal( Number( await page.textContent( '#mcp-oauth-visible-count' ) ), before - 1 );
	assert.equal( ( await page.textContent( 'tr[data-ability="demo/list-trips"] .mcp-oauth-reason' ) ).trim(), 'hidden here' );
	assert.ok( await page.locator( 'tr[data-ability="demo/list-trips"] .mcp-oauth-reason' ).evaluate( ( e ) => e.classList.contains( 'is-override' ) ) );
	assert.ok( ! ( await h.discoverAbilities( tokens.access_token ) ).includes( 'demo/list-trips' ), 'hidden from clients' );

	const response2 = page.waitForResponse( ( r ) => r.url().includes( 'abilities/visibility' ) );
	await eye.click();
	await response2;
	await page.waitForFunction( () => document.querySelector( 'tr[data-ability="demo/list-trips"] .mcp-oauth-eye' ).dataset.visible === '1' );
	assert.ok( ( await h.discoverAbilities( tokens.access_token ) ).includes( 'demo/list-trips' ), 'visible again' );

	const anon = await h.fetchJson( h.BASE + '/wp-json/mcp-oauth/v1/abilities/visibility', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { ability: 'demo/list-trips', hide: true } ) } );
	assert.equal( anon.status, 401, 'anonymous callers cannot toggle' );
	await ctx.close();
} );

test( 'the Site Health test passes', async () => {
	const { ctx, page } = await h.adminContext();
	const r = await ctx.request.get( h.BASE + '/wp-json/mcp-oauth/v1/health', { headers: { 'X-WP-Nonce': await nonce( page ) } } );
	assert.equal( r.status(), 200 );
	const result = await r.json();
	assert.equal( result.status, 'good', JSON.stringify( result ) );
	assert.equal( result.test, 'mcp_oauth' );
	assert.match( result.description, /Protected-resource metadata/ );

	await page.goto( h.BASE + '/wp-admin/site-health.php' );
	await page.waitForSelector( 'button[aria-controls="health-check-accordion-block-mcp_oauth"]', { state: 'attached', timeout: 60000 } );
	const section = await page.evaluate( () => document.querySelector( 'button[aria-controls="health-check-accordion-block-mcp_oauth"]' ).closest( '.health-check-accordion' ).parentElement.id );
	assert.equal( section, 'health-check-issues-good' );
	await ctx.close();
} );

async function nonce( page ) {
	await page.goto( h.BASE + '/wp-admin/' );
	return page.evaluate( () => window.wpApiSettings.nonce );
}
