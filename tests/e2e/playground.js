#!/usr/bin/env node
// Boot a disposable WordPress with wp-playground-cli for the e2e suite.
// Started (and stopped) by Playwright's `webServer` setting; can also be run by hand.
//
//   MCP_ADAPTER_DIR=../mcp-adapter   mount a local MCP Adapter checkout (offline)
//   MCP_ADAPTER_ZIP=<url>            install a specific release zip instead of the latest
//   PLAYGROUND_PORT / PLAYGROUND_PHP  port (9400) and PHP version (8.3)

const { spawn } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const ROOT = path.resolve( __dirname, '..', '..' );
const PORT = Number( process.env.PLAYGROUND_PORT || 9400 );

// Mount only the plugin files, not node_modules/vendor/tests.
const stage = fs.mkdtempSync( path.join( os.tmpdir(), 'mcp-connect-e2e-' ) );
const plugin = path.join( stage, 'mcp-connect' );
fs.mkdirSync( plugin );
for ( const entry of [ 'mcp-connect.php', 'uninstall.php', 'includes' ] ) {
	fs.cpSync( path.join( ROOT, entry ), path.join( plugin, entry ), { recursive: true } );
}

const steps = [
	{ step: 'defineWpConfigConsts', consts: { WP_ENVIRONMENT_TYPE: 'local', WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false } },
	{ step: 'setSiteOptions', options: { blogname: 'Example Site', permalink_structure: '/%postname%/' } },
];
const mounts = [
	plugin + ':/wordpress/wp-content/plugins/mcp-connect',
	path.join( __dirname, 'mu-plugins' ) + ':/wordpress/wp-content/mu-plugins',
];
if ( process.env.MCP_ADAPTER_DIR ) {
	mounts.push( path.resolve( process.env.MCP_ADAPTER_DIR ) + ':/wordpress/wp-content/plugins/mcp-adapter' );
} else {
	// The MCP Adapter is not on wordpress.org; its GitHub releases ship a built zip (with vendor/).
	const zip = process.env.MCP_ADAPTER_ZIP || 'https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip';
	steps.push( { step: 'installPlugin', pluginData: { resource: 'url', url: zip }, options: { activate: false, targetFolderName: 'mcp-adapter' } } );
}
steps.push(
	{ step: 'activatePlugin', pluginPath: 'mcp-adapter/mcp-adapter.php' },
	{ step: 'activatePlugin', pluginPath: 'mcp-connect/mcp-connect.php' },
	{ step: 'runPHP', code: "<?php require '/wordpress/wp-load.php'; flush_rewrite_rules();" }
);
const blueprint = path.join( stage, 'blueprint.json' );
fs.writeFileSync( blueprint, JSON.stringify( { landingPage: '/', login: false, steps }, null, '\t' ) );

const local = path.join( ROOT, 'node_modules', '.bin', 'wp-playground-cli' );
const bin = fs.existsSync( local ) ? local : 'wp-playground-cli';
const args = [ 'server', '--port=' + PORT, '--php=' + ( process.env.PLAYGROUND_PHP || '8.3' ), '--blueprint=' + blueprint, ...mounts.map( ( m ) => '--mount=' + m ) ];
console.log( '> ' + bin + ' ' + args.join( ' ' ) );

const server = spawn( bin, args, { stdio: 'inherit' } );

function cleanup() {
	fs.rmSync( stage, { recursive: true, force: true } );
}
for ( const signal of [ 'SIGINT', 'SIGTERM', 'SIGHUP' ] ) {
	process.on( signal, () => {
		server.kill( 'SIGKILL' );
		cleanup();
		process.exit( 0 );
	} );
}
server.on( 'exit', ( code ) => {
	cleanup();
	process.exit( code ?? 1 );
} );
