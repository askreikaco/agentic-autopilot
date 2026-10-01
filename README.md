# Agentic Autopilot

Free, open-source WordPress plugin that makes WordPress instant and ready for AI agents. Built and used by [REIKA](https://reika.co); GPL-2.0-or-later, contributions welcome.

| Feature | What it does |
|---|---|
| llms.txt for AI agents | Publishes a machine-readable site index at `/llms.txt` for AI search engines and LLM agents to discover your site's structure and content. See [llmstxt.org](https://llmstxt.org). |
| MCP Adapter | One-click download and activation of the official WordPress MCP Adapter, so AI agents (Claude, ChatGPT, Cursor…) can use this site's abilities over the Model Context Protocol. Automatically kept updated from GitHub until WordPress.org adoption. |
| Instant navigation | Configures WordPress' built-in Speculation Rules (6.8+): prerender on hover, on click, or eagerly; extra excluded paths (`/*.pdf`, `/*.zip` by default). |
| Jetpack: Monitor only | Keeps Jetpack's Downtime Monitor and makes every other module unavailable. |

Everything is off until enabled in **Settings → Agentic Autopilot**.

## Install / update

Download the latest release zip and upload it in **Plugins → Add New → Upload**. After that, new GitHub releases show up in **Dashboard → Updates** like any other plugin (core `Update URI` mechanism).

## AI agents (MCP)

The official WordPress MCP Adapter plugin lets AI agents (Claude, ChatGPT, Cursor…) discover and use WordPress' native capabilities over the Model Context Protocol. Agentic Autopilot includes a one-click button to download and activate the adapter from its [official GitHub releases](https://github.com/WordPress/mcp-adapter).

**How it works:**
1. In Settings → Agentic Autopilot, the MCP Adapter section shows the current status.
2. Click **Download & activate MCP Adapter** to fetch and enable it.
3. The adapter is kept automatically updated from GitHub releases (disabled by default, toggle to enable).
4. AI clients sign in as a WordPress user with an [Application Password](https://make.wordpress.org/core/2020/12/15/application-passwords-integration-guide/).
5. Only abilities marked `public` are exposed, and WordPress permission checks apply.
6. The default MCP endpoint is `{site}/wp-json/mcp/mcp-adapter-default-server`.

**No code is bundled.** The MCP Adapter is downloaded from its official releases. Once it lands in the WordPress.org plugin directory, the core plugin updater takes over and the automation here is disabled.

Requires WordPress 6.9+ (the Abilities API). If WordPress core ships MCP built-in, the button disappears and nothing is installed.

## Develop

- One feature per class in `includes/`; each reads its toggle from `Agentic_Autopilot_Settings::get()` and adds nothing when off.
- Prefer configuring WordPress core or the existing plugin over shipping new front-end code.
- Release: bump `Version` in `agentic-autopilot.php`, `AGENTIC_AUTOPILOT_VERSION`, `Stable tag` + changelog in `readme.txt`, then publish a GitHub release tagged `vX.Y.Z` with an attached `agentic-autopilot.zip` (folder `agentic-autopilot/`).

Planned: submission to the WordPress.org plugin directory (the GitHub updater is removed for that build).

## Compatibility promise (zero-break updates)

Updates must never break a site. Every change follows these rules:

- **Settings are forever.** Option keys are never renamed or removed. New settings get a default, and saved settings are always merged over the defaults. Renames ship with an automatic migration that is safe to run many times.
- **Hooks are forever.** Public filters and actions (`agentic_autopilot_*`) keep working. A replaced hook keeps calling the old one with a deprecation notice for at least one major version.
- **Every feature is opt-in and isolated.** Each module boots separately. An unexpected error in one is logged and the rest of the site keeps working.
- **Updates check requirements.** Update offers carry the release's own "Requires at least" and "Requires PHP", so WordPress refuses an update the site cannot run. WordPress also rolls back an automatic update that causes a fatal error.
- **Third-party code updates carefully.** The MCP Adapter auto-updates only for bug-fix releases (same major.minor). Bigger jumps wait for an admin.
- **CI proves it.** Every pull request is linted on PHP 7.4 and the newest PHP. It also upgrades the latest release to the new code on a real WordPress, on the oldest and newest supported versions, and checks that settings, pages, `/llms.txt` and the settings screen still work.
- **Semantic versioning.** Patch = fixes, minor = new opt-in features, major = only with a migration path. Nothing is removed without a deprecation period.
