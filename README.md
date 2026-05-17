# TheGridIndex RSS Importer

A WordPress plugin that imports RSS feeds as posts, with a curated 47-feed catalog, per-feed scheduling, dedupe, and feed health monitoring.

**WordPress.org plugin page:** https://wordpress.org/plugins/thegridindex-rss-importer/

## Repository structure

```
.
├── .github/workflows/      # GitHub Actions for auto-deploy to WordPress.org
├── .wordpress-org/         # Banner, icon, screenshots for the plugin page
├── assets/                 # Admin-side CSS/JS (part of the plugin itself)
├── thegridindex-rss-importer.php   # Main plugin file
├── readme.txt              # WordPress.org plugin readme
├── changelog.txt
├── uninstall.php
└── .distignore             # Files excluded from SVN deploy
```

## How deployment works

This repo deploys to WordPress.org SVN automatically via GitHub Actions:

- **Push to `main`** → syncs `.wordpress-org/` to WordPress.org `/assets/` (banner, icon, screenshots)
- **Publish a GitHub Release** → deploys plugin code to WordPress.org `/trunk/` and creates a new `/tags/` version

You never need to touch SVN directly.

## Releasing a new version

1. Bump version in `thegridindex-rss-importer.php` (header) and `readme.txt` (`Stable tag:`)
2. Add changelog entry in `readme.txt` and `changelog.txt`
3. Commit and push to `main`
4. On GitHub: **Releases → Draft a new release → Tag = the new version number → Publish**
5. The deploy workflow runs automatically

See `SETUP.md` for one-time setup instructions.
