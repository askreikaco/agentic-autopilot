=== REIKA Site Kit ===
Contributors: reika
Tags: performance, speculation rules, prerender, jetpack
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Small, opt-in site-wide features: instant navigation (prerender on hover) and Jetpack "Monitor only".

== Description ==

Every feature is off until you switch it on in Settings → REIKA Site Kit.

* **Instant navigation** — configures the Speculation Rules built into WordPress 6.8+ so the next page is prerendered when a visitor hovers a link (or on click, or eagerly). Admin, login, query-string, nofollow and `no-prerender` links stay excluded by WordPress; you can add your own path patterns (PDFs and ZIPs by default). Logged-in users are left alone, as in core.
* **Jetpack: Monitor only** — keeps the Downtime Monitor and makes every other Jetpack module unavailable, so stats, forms and other modules cannot load scripts on the site.

No tracking, no external requests on the front end. The plugin checks GitHub for new releases from the admin only.

== Changelog ==

= 1.0.0 =
* Instant navigation (Speculation Rules: mode, eagerness, extra exclusions).
* Jetpack: Monitor only.
* Updates from GitHub releases (Update URI).
