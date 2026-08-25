#!/usr/bin/env node
// Boot a disposable WordPress with wp-playground-cli, run the e2e tests against it, shut it down.
//
//   npm run test:e2e                       # mcp-adapter is installed from its latest GitHub release
//   MCP_ADAPTER_ZIP=<url> npm run test:e2e # …from a specific release zip
//   MCP_ADAPTER_DIR=../mcp-adapter npm run test:e2e   # …or mounted from a local checkout (offline)
//   PLAYGROUND_URL=http://127.0.0.1:9400 node --test tests/e2e   # against an already running site

const { spawn, spawnSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const ROOT = path.resolve( __dirname, '..', '..' );
const PORT = Number( process.env.PLAYGROUND_PORT || 9400 );
const URL_ = 'http://127.0.0.1:' + PORT;

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

const bin = fs.existsSync( path.join( ROOT, 'node_modules', '.bin', 'wp-playground-cli' ) ) ? path.join( ROOT, 'node_modules', '.bin', 'wp-playground-cli' ) : 'wp-playground-cli';
const args = [ 'server', '--port=' + PORT, '--php=' + ( process.env.PLAYGROUND_PHP || '8.3' ), '--blueprint=' + blueprint, ...mounts.map( ( m ) => '--mount=' + m ) ];
console.log( '> ' + bin + ' ' + args.join( ' ' ) );
// Own process group, so shutdown() can take the CLI and its PHP workers down together.
const server = spawn( bin, args, { stdio: [ 'ignore', 'pipe', 'pipe' ], detached: process.platform !== 'win32' } );
let log = '';
server.stdout.on( 'data', ( d ) => { log += d; if ( process.env.VERBOSE ) process.stdout.write( d ); } );
server.stderr.on( 'data', ( d ) => { log += d; if ( process.env.VERBOSE ) process.stderr.write( d ); } );

async function waitReady( timeoutMs ) {
	const started = Date.now();
	while ( Date.now() - started < timeoutMs ) {
		if ( server.exitCode !== null ) {
			throw new Error( 'Playground exited early:\n' + log );
		}
		try {
			const res = await fetch( URL_ + '/.well-known/oauth-authorization-server' );
			if ( res.status === 200 ) {
				return;
			}
		} catch ( e ) {}
		await new Promise( ( r ) => setTimeout( r, 2000 ) );
	}
	throw new Error( 'Playground did not become ready in time:\n' + log );
}

function shutdown() {
	if ( server.exitCode === null ) {
		try {
			process.platform === 'win32' ? server.kill() : process.kill( -server.pid, 'SIGKILL' );
		} catch ( e ) {
			server.kill( 'SIGKILL' );
		}
	}
	fs.rmSync( stage, { recursive: true, force: true } );
}
process.on( 'SIGINT', () => { shutdown(); process.exit( 130 ); } );
process.on( 'SIGTERM', () => { shutdown(); process.exit( 143 ); } );

( async () => {
	await waitReady( Number( process.env.PLAYGROUND_TIMEOUT || 300000 ) );
	console.log( 'WordPress is up at ' + URL_ );
	// Explicit file list: a directory argument is not accepted by every Node version.
	const files = fs.readdirSync( __dirname ).filter( ( f ) => f.endsWith( '.test.js' ) ).sort().map( ( f ) => path.join( __dirname, f ) );
	const result = spawnSync( process.execPath, [ '--test', '--test-concurrency=1', ...files ], {
		stdio: 'inherit',
		env: { ...process.env, PLAYGROUND_URL: URL_ },
	} );
	shutdown();
	process.exit( result.status ?? 1 );
} )().catch( ( e ) => {
	console.error( e.message || e );
	shutdown();
	process.exit( 1 );
} );
