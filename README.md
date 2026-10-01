# REIKA Site Kit

Small, open-source WordPress plugin with opt-in, site-wide features that page builders do not cover. Used on the websites [REIKA](https://reika.co) builds and maintains; free for anyone (GPL-2.0-or-later).

| Feature | What it does |
|---|---|
| Instant navigation | Configures WordPress' built-in Speculation Rules (6.8+): prerender on hover, on click, or eagerly; extra excluded paths (`/*.pdf`, `/*.zip` by default). |
| Jetpack: Monitor only | Keeps Jetpack's Downtime Monitor and makes every other module unavailable. |

Everything is off until enabled in **Settings → REIKA Site Kit**.

## Install / update

Download the latest release zip and upload it in **Plugins → Add New → Upload**. After that, new GitHub releases show up in **Dashboard → Updates** like any other plugin (core `Update URI` mechanism).

## Develop

- One feature per class in `includes/`; each reads its toggle from `Reika_Site_Kit_Settings::get()` and adds nothing when off.
- Prefer configuring WordPress core or the existing plugin over shipping new front-end code.
- Release: bump `Version` in `reika-site-kit.php`, `REIKA_SITE_KIT_VERSION`, `Stable tag` + changelog in `readme.txt`, then publish a GitHub release tagged `vX.Y.Z` with an attached `reika-site-kit.zip` (folder `reika-site-kit/`).

Planned: submission to the WordPress.org plugin directory (the GitHub updater is removed for that build).
