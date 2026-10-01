# Agentic Autopilot

Free, open-source WordPress plugin that makes WordPress instant and ready for AI agents. Built and used by [REIKA](https://reika.co); GPL-2.0-or-later, contributions welcome.

| Feature | What it does |
|---|---|
| **Blueprint** | Connect any GitHub repo to install and update a set of plugins and themes. Useful for setting up new sites quickly from a base set. See the [Blueprint repo](#blueprint-repo) section below. |
| llms.txt for AI agents | Publishes a machine-readable site index at `/llms.txt` for AI search engines and LLM agents to discover your site's structure and content. See [llmstxt.org](https://llmstxt.org). |
| MCP Adapter | One-click download and activation of the official WordPress MCP Adapter, so AI agents (Claude, ChatGPT, Cursor…) can use this site's abilities over the Model Context Protocol. Automatically kept updated from GitHub until WordPress.org adoption. |
| Instant navigation | Configures WordPress' built-in Speculation Rules (6.8+): prerender on hover, on click, or eagerly; extra excluded paths (`/*.pdf`, `/*.zip` by default). |
| Jetpack: Monitor only | Keeps Jetpack's Downtime Monitor and makes every other module unavailable. |

Everything is off until enabled in **Settings → Agentic Autopilot**.

## Blueprint repo

**Blueprint** is a gateway that connects a WordPress site to any GitHub repo to install and keep updated a set of plugins and themes. Use it to bootstrap new sites quickly from a pre-configured base.

**Blueprint does not import settings itself.** Config import is handled by each plugin's own import tool, a script, or an AI agent.

### How it works

1. Point Blueprint to a GitHub repo (yours or anyone's public repo).
2. The repo contains a `catalog.json` at the root (or a custom path) listing plugins and themes:
   ```json
   [
     {
       "type": "plugins",
       "slug": "acf",
       "name": "ACF",
       "version": "6.8.0",
       "main_file": "acf.php",
       "location": "plugins/acf.zip"
     },
     {
       "type": "config",
       "slug": "yoast-settings",
       "name": "Yoast SEO settings",
       "location": "config/yoast.json",
       "for": "wordpress-seo",
       "notes": "Import in Yoast > Tools > Import"
     }
   ]
   ```
3. In **Settings → Blueprint**, connect a repo. Each package is a standard WordPress .zip (plugin or theme with top folder = slug).
4. Optionally enable auto-install (daily) or auto-update. Exclude individual items as needed.
5. Blueprint injected items into WordPress' update checks; the Plugins/Themes screens show updates normally.

### Configuration

- **Repository**: GitHub repo (owner/name), public or private (with token).
- **Branch/Ref**: Optional; uses the repo's default branch if empty.
- **Catalog Path**: Relative path to catalog.json (default: `catalog.json`).
- **Auto Install**: Automatically install missing items daily.
- **Activate After Install**: Activate plugins upon install (themes are never auto-activated).
- **Auto Update**: Automatically update managed items if a new version is available.
- **AI Agents**: Expose Blueprint abilities to MCP clients (Claude, ChatGPT…) so agents can list, install, and fetch config files.

### Pause guard

If the legacy **REIKA Blueprint** plugin is active, Agentic Autopilot's Blueprint pauses automatically to prevent two updaters fighting over the same items. Deactivate the REIKA plugin to let Agentic Autopilot take over.

### Catalog format (catalog.json)

Each item in the JSON array:

- **type** (required): `"plugins"`, `"themes"`, or `"config"`.
- **slug** (required): Item slug (alphanumeric, dashes, dots, underscores).
- **name** (required): Human-readable name.
- **version** (required for plugins/themes): Semantic version (e.g., `"1.0.0"`).
- **main_file** (required for plugins): Main plugin file (e.g., `"acf.php"`).
- **location** (required): Relative path in the repo (must be `.zip` for plugins/themes).
- **for** (optional, config only): Which plugin this config is for.
- **notes** (optional): Import instructions.

**Validation**: Blueprint validates every item and skips invalid ones, showing warnings on the admin page.

### AI agent abilities

When enabled, Blueprint exposes three abilities for AI agents:

- **agentic-autopilot/blueprint-list**: List all items and config files.
- **agentic-autopilot/blueprint-install**: Install a plugin or theme.
- **agentic-autopilot/blueprint-get-config**: Fetch a config file for import.

Requires the Abilities API (WordPress 6.9+).

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
