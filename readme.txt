=== Agentic Autopilot ===
Contributors: reika
Tags: performance, speculation rules, llms.txt, mcp, ai
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make WordPress feel instant and ready for AI agents: llms.txt for AI search, speculative prerender on hover, Jetpack Monitor-only mode, one-click MCP Adapter installation, and GitHub auto-updates.

== Description ==

Every feature is off until you switch it on in Settings → Agentic Autopilot.

* **llms.txt for AI agents** — publishes a machine-readable site index at `/llms.txt` for AI search engines and LLM agents to discover your site's structure and content. See [llmstxt.org](https://llmstxt.org).
* **MCP Adapter** — one-click download and activation of the official WordPress MCP Adapter plugin, so AI agents (Claude, ChatGPT, Cursor…) can use this site's abilities over the Model Context Protocol. Automatically kept updated from GitHub until it's on WordPress.org.
* **Instant navigation** — configures the Speculation Rules built into WordPress 6.8+ so the next page is prerendered when a visitor hovers a link (or on click, or eagerly). Admin, login, query-string, nofollow and `no-prerender` links stay excluded by WordPress; you can add your own path patterns (PDFs and ZIPs by default). Logged-in users are left alone, as in core.
* **Jetpack: Monitor only** — keeps the Downtime Monitor and makes every other Jetpack module unavailable, so stats, forms and other modules cannot load scripts on the site.

No tracking, no external requests on the front end. The plugin checks GitHub for new releases from the admin only. The MCP Adapter code itself is not bundled; it is downloaded from its official GitHub releases.

== Changelog ==

= 1.3.0 =
* New: one-click download and activation of the official WordPress MCP Adapter, kept updated from its GitHub releases.

= 1.2.0 =
* Renamed to Agentic Autopilot.
* New: llms.txt for AI agents and AI search.

= 1.1.0 =
* Renamed from REIKA Site Kit to WP Autopilot (settings are migrated automatically).
* Settings now under Settings → WP Autopilot.

= 1.0.0 =
* Instant navigation (Speculation Rules: mode, eagerness, extra exclusions).
* Jetpack: Monitor only.
* Updates from GitHub releases (Update URI).

== Upgrade Notice ==

= 1.2.0 =
Renamed from WP Autopilot. Settings are migrated automatically.
