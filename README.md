# MCP Connect

**Contributors:** akirk
**Tags:** mcp, ai, oauth, claude, chatgpt
**Requires at least:** 6.9
**Tested up to:** 7.1
**Requires PHP:** 7.4
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html
**Stable tag:** 0.1.0

Connect Claude.ai, Claude Code, ChatGPT, Codex, Cursor and other AI clients to your site's MCP servers with a normal sign-in.

[Try MCP Connect in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/mcp-connect/main/blueprint.json)

## Description

The [MCP Adapter](https://github.com/WordPress/mcp-adapter) exposes WordPress abilities as an MCP server, but it only accepts cookie or Application Password authentication. AI clients such as Claude.ai expect the OAuth 2.1 flow described by the MCP specification: they discover the authorization server, register themselves, and send the user to the site to sign in.

This plugin adds exactly that:

- RFC 9728 protected-resource metadata and RFC 8414 authorization-server metadata on the `/.well-known/` paths.
- Dynamic client registration (RFC 7591), rate limited.
- An authorization endpoint on `wp-login.php` with a consent screen in the login page's own style, so signing in works even where REST cookie authentication is disabled.
- Authorization-code grant with mandatory PKCE (S256), refresh-token rotation, and revocation (RFC 7009).
- Bearer-token authentication on the MCP routes — and only there. Tokens are bound to the MCP server they were issued for.
- A **Tools → MCP Connect** page with a one-click "Add to Claude.ai" link and ready-made commands or config snippets for Claude Desktop, Claude Code, ChatGPT, Codex, Cursor, VS Code, Windsurf, Gemini CLI, Antigravity, Zed, Cline, Roo Code and OpenCode, plus a **Tools** tab listing what the server actually hands to a client, the list of connected clients with a Revoke button, and a list of every registered ability with an eye icon to hide it from clients and a checkbox to expose it as a tool of its own.
- A Site Health test that verifies, from the server itself, that the discovery documents are served and the MCP endpoint issues the sign-in challenge.

Tokens are opaque random strings stored only as SHA-256 hashes. No signing keys, no external services.

### The MCP Adapter is bundled

The MCP Adapter is not in the WordPress.org plugin directory, so this plugin ships a copy of it: activating MCP Connect is enough to get an MCP server.

If the MCP Adapter is also installed as a plugin of its own, nothing collides and nothing needs to be turned off — the standalone plugin takes over, and the bundled copy stands down.

From MCP Adapter 0.6.1 on, both copies register their classes with the [Jetpack autoloader](https://github.com/Automattic/jetpack-autoloader), which loads the newer of the two once, for both plugins. An older standalone plugin brings a plain Composer autoloader that is registered first, so it serves its own classes throughout. Either way only one set of `WP\MCP\*` classes is ever loaded, and the adapter's singletons make the second boot a no-op.

Update or remove the standalone plugin at will — MCP Connect keeps working either way. Tools → Site Health → Info → MCP Connect names the copy actually in use.

### Which abilities do clients see?

All of them, unless an ability opts out with `meta.mcp.public = false`. The MCP Adapter on its own only lists abilities marked `meta.public`, but that flag does not protect anything: the MCP endpoint is only reachable by a signed-in user, and each ability enforces its own permission callback for that user. An administrator can hide individual abilities from clients on the MCP Connect page by clicking the eye icon.

### How do clients see them?

By default the way the MCP Adapter presents them: through the three meta-tools its default server registers — `discover-abilities`, `get-ability-info` and `execute-ability` — so a client names an ability in a string and gets to it in two calls. That keeps the tool list short on a site with a hundred abilities, at the cost of a round trip and a schema the client never sees.

Tick **Expose as MCP tool** next to an ability on the MCP Connect page to also register it as a tool in its own right, with its own name, description and input schema, so a client calls it in a single step. The meta-tools stay either way, so nothing becomes unreachable — promotion is for the handful of abilities worth spending a client's tool budget on. The `mcp_oauth_direct_tools` filter sets the promoted list in code, and `mcp_oauth_tools` has the final say over what the server registers.

The OAuth endpoints are only exposed on HTTPS sites (and local environments).

## Filters

- `mcp_oauth_authorize_capability` — capability a user needs to connect a client (default `read`, the MCP Adapter's transport default).
- `mcp_oauth_redirect_uri_schemes` — custom URL schemes accepted as redirect URIs for native apps.
- `mcp_oauth_transport_allowed` — override the HTTPS requirement.
- `mcp_oauth_hidden_abilities` — ability names hidden from clients.
- `mcp_oauth_direct_tools` — ability names exposed as tools of their own.
- `mcp_oauth_tools` — the final tool list of the default MCP server, given the adapter's meta-tools and the promoted abilities as further arguments.
- `mcp_oauth_registration_limits` — per-address limits on anonymous client registration (`per_hour`, `max_clients`).

## Development

```
composer install          # the bundled MCP Adapter, PHPUnit and WordPress coding standards
composer test             # unit tests (no WordPress needed; tests/bootstrap.php stubs it)
composer lint             # phpcs
npm install && npx playwright install chromium
npm run test:e2e          # Playwright Test; boots WordPress with wp-playground-cli (tests/e2e/playground.js)
```

`vendor/` holds the bundled MCP Adapter and is not committed, so a checkout needs `composer install` before it runs. What ships is built by CI: every push produces a `dist/<branch>` branch with the production dependencies installed (`.github/workflows/build-dist.yml`), which is what the Playground link above installs and what a release would be built from.

The e2e suite runs against the bundled adapter. Set `MCP_ADAPTER_PLUGIN=1` to also install the standalone MCP Adapter plugin from its latest GitHub release and exercise the two side by side — `MCP_ADAPTER_ZIP=<url>` picks another release, `MCP_ADAPTER_DIR=/path/to/mcp-adapter` mounts a local checkout instead (works offline). Set `PLAYGROUND_URL` to run the specs against a site that is already running (`npx playwright test --ui` works too). All of it runs in GitHub Actions on every push, with the e2e suite covering both the bundled and the standalone adapter.

## Changelog

### 0.1.0
- Initial release.
- Bundles the MCP Adapter, so no separate install is needed; a separately installed one takes over automatically.
