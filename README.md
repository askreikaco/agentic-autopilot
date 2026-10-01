# WP Autopilot

Free, open-source WordPress plugin that puts routine site speed and housekeeping on autopilot. Built and used by [REIKA](https://reika.co); GPL-2.0-or-later, contributions welcome.

| Feature | What it does |
|---|---|
| Instant navigation | Configures WordPress' built-in Speculation Rules (6.8+): prerender on hover, on click, or eagerly; extra excluded paths (`/*.pdf`, `/*.zip` by default). |
| Jetpack: Monitor only | Keeps Jetpack's Downtime Monitor and makes every other module unavailable. |

Everything is off until enabled in **Settings → WP Autopilot**.

## Install / update

Download the latest release zip and upload it in **Plugins → Add New → Upload**. After that, new GitHub releases show up in **Dashboard → Updates** like any other plugin (core `Update URI` mechanism).

## Develop

- One feature per class in `includes/`; each reads its toggle from `WPAutopilot_Settings::get()` and adds nothing when off.
- Prefer configuring WordPress core or the existing plugin over shipping new front-end code.
- Release: bump `Version` in `wp-autopilot.php`, `WPAUTOPILOT_VERSION`, `Stable tag` + changelog in `readme.txt`, then publish a GitHub release tagged `vX.Y.Z` with an attached `wp-autopilot.zip` (folder `wp-autopilot/`).

Planned: submission to the WordPress.org plugin directory (the GitHub updater is removed for that build).
