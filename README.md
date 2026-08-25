# MCP Connect

**Contributors:** akirk
**Tags:** mcp, ai, oauth, claude, chatgpt
**Requires at least:** 6.9
**Tested up to:** 7.1
**Requires PHP:** 7.4
**Requires Plugins:** mcp-adapter
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html
**Stable tag:** 0.1.0

Connect Claude.ai, Claude Code, ChatGPT, Codex, Cursor and other AI clients to your site's MCP servers with a normal sign-in.

## Description

The [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) exposes WordPress abilities as an MCP server, but it only accepts cookie or Application Password authentication. AI clients such as Claude.ai expect the OAuth 2.1 flow described by the MCP specification: they discover the authorization server, register themselves, and send the user to the site to sign in.

This plugin adds exactly that:

- RFC 9728 protected-resource metadata and RFC 8414 authorization-server metadata on the `/.well-known/` paths.
- Dynamic client registration (RFC 7591), rate limited.
- An authorization endpoint on `wp-login.php` with a consent screen in the login page's own style, so signing in works even where REST cookie authentication is disabled.
- Authorization-code grant with mandatory PKCE (S256), refresh-token rotation, and revocation (RFC 7009).
- Bearer-token authentication on the MCP routes — and only there. Tokens are bound to the MCP server they were issued for.
- A **Tools → MCP Connect** page with a one-click "Add to Claude.ai" link and ready-made commands or config snippets for Claude Desktop, Claude Code, ChatGPT, Codex, Cursor, VS Code, Windsurf, Gemini CLI, Antigravity, Zed, Cline, Roo Code and OpenCode, plus the list of connected clients with a Revoke button and a list of every registered ability with an eye icon to hide it from clients or show it again.
- A Site Health test that verifies, from the server itself, that the discovery documents are served and the MCP endpoint issues the sign-in challenge.

Tokens are opaque random strings stored only as SHA-256 hashes. No signing keys, no external dependencies.

### Which abilities do clients see?

All of them, unless an ability opts out with `meta.mcp.public = false`. The MCP Adapter on its own only lists abilities marked `meta.public`, but that flag does not protect anything: the MCP endpoint is only reachable by a signed-in user, and each ability enforces its own permission callback for that user. An administrator can hide individual abilities from clients on the MCP Connect page by clicking the eye icon.

The OAuth endpoints are only exposed on HTTPS sites (and local environments).

## Filters

- `mcp_oauth_authorize_capability` — capability a user needs to connect a client (default `read`, the MCP Adapter's transport default).
- `mcp_oauth_redirect_uri_schemes` — custom URL schemes accepted as redirect URIs for native apps.
- `mcp_oauth_transport_allowed` — override the HTTPS requirement.
- `mcp_oauth_hidden_abilities` — ability names hidden from clients.

## Changelog

### 0.1.0
- Initial release.
