=== TheGridIndex RSS Importer ===
Contributors: fifthavesupport, fifthavenuephotographic
Tags: rss, importer, feeds, syndication, news
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.74
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import RSS feeds into WordPress as posts. Includes a 47-feed curated catalog, per-feed scheduling, dedupe, and feed health monitoring.

== Description ==

TheGridIndex RSS Importer pulls headlines from external RSS feeds into WordPress as posts. Designed to pair with The Grid Index theme but works as a standalone importer with any theme.

> **About the name.** "TheGridIndex" is a coined product name owned by Fifth Avenue Photographic (the plugin author) and is also the name of a companion WordPress theme by the same author. The plugin is not affiliated with any unrelated project, dataset, or service that happens to share the words "grid" or "index" in its name. Use of the companion theme is optional — the plugin works as a standalone importer with any theme.

**Features:**

* 47-feed curated catalog (News, World, Tech, Business, Science) — toggle on with one click
* Custom feeds — paste any RSS URL on the Feeds tab
* Per-feed check interval (5 min / 15 min / 30 min / hourly)
* Configurable post status (publish / draft / pending)
* Granular post categories — feeds map to News/World/Tech/Business/Science alongside the catch-all RSS category
* Featured image extraction from feed enclosures, media:thumbnail, media:content, or first content image
* Minimum image width filter
* Persistent GUID dedupe ledger — re-runs never create duplicates, even after posts are deleted
* Duplicate detector with bulk merge tool
* Feed health monitoring — flags feeds that fetch successfully but import nothing (silent failures)
* Embedded knowledge base with 15 FAQ entries
* Per-post source attribution meta for theme integration

When The Grid Index theme is active, imported posts automatically display:

* Source attribution chip in the article hero
* "Read at [Source]" CTA button below the hero
* The "Hide comments on imported RSS posts" Theme Option (if enabled) takes effect

== External services ==

This plugin is an RSS feed importer. To do its job, it must connect to external (third-party) RSS feed URLs that you choose to enable. **No feed is fetched until you explicitly enable it** — either by toggling a feed on from the Catalog tab or by pasting a custom RSS URL on the Feeds tab. If you enable no feeds, the plugin makes no outbound requests.

**What is sent, when, and why:**

When a feed you have enabled is due for a check (per the global cron and that feed's individual interval — 5 min / 15 min / 30 min / hourly), or when you click "Import Now," "Force re-import 24h," or a per-row "Fetch" button, the plugin makes a standard HTTP GET request from your WordPress server to the feed's URL. That request includes:

* The feed URL you enabled (target of the request).
* A standard browser-style User-Agent header (used because some publishers' WAFs reject WordPress's default User-Agent).
* Standard HTTP headers (Accept, Accept-Encoding) added by the WordPress HTTP API.
* Your server's outbound IP address (visible to the publisher in their access logs, as with any HTTP request).

**No personal data about your site visitors is sent.** No analytics, no telemetry, no user-identifying information. The plugin does not phone home to the plugin author or to any service the author controls.

**Outbound caching.** Successful and failed fetches are cached locally on your WordPress site via the standard SimplePie/WordPress transient cache to reduce redundant requests. No fetched content is sent off your server.

**Custom feeds you add.** If you paste your own RSS URL into the Feeds tab, the plugin fetches that URL on the same schedule. You are responsible for ensuring you have the right to import from that source and for reviewing that source's terms.

**Curated catalog publishers.** The plugin ships with a catalog of 47 RSS feeds offered as a convenience. Each publisher operates independently of this plugin and its author. Use of each publisher's feed is governed by that publisher's own terms of service and privacy policy.

**How to reach each publisher's current legal pages.** Each publisher operates their own legal pages on their own domain. The URLs publishers use for these pages change periodically — they reorganize sites, migrate to new help centers, move to consolidated parent-company legal portals (e.g. Condé Nast, Yahoo, NBCUniversal, Dow Jones, Disney/Paramount). Rather than hard-code per-publisher URLs that go stale, this readme lists each publisher's **home page**, which is the canonical, stable entry point. Every publisher's site footer carries links labeled "Terms" / "Terms of Use" / "Terms of Service" and "Privacy" / "Privacy Policy" / "Privacy Notice" that go to their current legal pages — those footer links are the publisher's own source of truth and stay current as the publisher updates their policies. If you want to review a publisher's terms before enabling their feed, open that publisher's home page and use the footer links. **Reasoning for this presentation choice:** an earlier version of this readme hard-coded direct terms/privacy URLs for every publisher; within a single review cycle, 12 of those URLs already 404'd because publishers had moved their legal pages. Pointing to home pages eliminates this rot for both users reading this readme today and for any future reviewer.

The table below lists every publisher the plugin can connect to (catalog feeds plus the activation-time starter feeds), grouped by category:

**News**

* **The New York Times** — https://www.nytimes.com/
* **BBC** — https://www.bbc.com/
* **The Guardian** — https://www.theguardian.com/
* **NPR** — https://www.npr.org/
* **Al Jazeera** — https://www.aljazeera.com/
* **Google News** — https://news.google.com/
* **USA Today** — https://www.usatoday.com/
* **The Washington Post** — https://www.washingtonpost.com/
* **ABC News (US)** — https://abcnews.go.com/ (operated by Disney; legal pages are reached via the Disney/ABC site footer)
* **CBS News** — https://www.cbsnews.com/ (operated by Paramount; legal pages are reached via the CBS/Paramount site footer)
* **Politico** — https://www.politico.com/
* **NBC News** — https://www.nbcnews.com/ (operated by NBCUniversal; legal pages are reached via the NBC/NBCUniversal site footer)
* **The Hill** — https://thehill.com/
* **ProPublica** — https://www.propublica.org/
* **Time** — https://time.com/
* **Bloomberg** (Politics and Technology feeds) — https://www.bloomberg.com/
* **LA Times** — https://www.latimes.com/

**Tech**

* **TechCrunch** — https://techcrunch.com/
* **The Verge** (Vox Media) — https://www.theverge.com/ (legal pages governed by Vox Media; reached via the Verge site footer)
* **Ars Technica** (Condé Nast) — https://arstechnica.com/ (legal pages governed by Condé Nast; reached via the Ars Technica site footer)
* **Wired** (Condé Nast) — https://www.wired.com/ (legal pages governed by Condé Nast; reached via the Wired site footer)
* **Engadget** (Yahoo) — https://www.engadget.com/ (legal pages governed by Yahoo; reached via the Engadget site footer)
* **Hacker News** — https://news.ycombinator.com/ (feed served via the hnrss.org community bridge — bridge home: https://hnrss.github.io/)
* **9to5Mac** — https://9to5mac.com/
* **MIT Technology Review** — https://www.technologyreview.com/
* **ZDNet** (Red Ventures) — https://www.zdnet.com/ (legal pages governed by Red Ventures; reached via the ZDNet site footer)

**Business**

* **Financial Times** — https://www.ft.com/
* **Harvard Business Review** — https://hbr.org/
* **Fast Company** — https://www.fastcompany.com/ (legal pages governed by Mansueto Ventures; reached via the Fast Company site footer)
* **Forbes** — https://www.forbes.com/
* **Wall Street Journal** (Dow Jones; feed publishes headlines, full articles paywalled) — https://www.wsj.com/ (legal pages governed by Dow Jones; reached via the WSJ site footer)
* **MarketWatch** (Dow Jones) — https://www.marketwatch.com/ (legal pages governed by Dow Jones; reached via the MarketWatch site footer)
* **CNBC** (NBCUniversal) — https://www.cnbc.com/ (legal pages governed by NBCUniversal; reached via the CNBC site footer)

**Science**

* **Science Daily** — https://www.sciencedaily.com/
* **NASA** (US government, public domain content) — https://www.nasa.gov/

**World**

* **Deutsche Welle** — https://www.dw.com/
* **France 24** — https://www.france24.com/
* **CBC News (Canada)** — https://www.cbc.ca/news
* **ABC News (Australia)** — https://www.abc.net.au/news

**AI / Vendor blogs** (starter feeds, not in the curated 47-feed catalog)

* **OpenAI** — https://openai.com/
* **Google AI Blog** (Alphabet) — https://blog.google/
* **Hugging Face** — https://huggingface.co/

Listing above does not imply endorsement or affiliation. If a publisher you want to use is not in the catalog, you can add their RSS URL manually via the Feeds tab; the same disclosure (HTTP GET with browser-style User-Agent) applies.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/thegridindex-rss-importer/` or install via the WordPress plugin uploader.
2. Activate the plugin via the Plugins menu.
3. Find the plugin under **Grid Index → Grid RSS** (when The Grid Index theme is active) or **Settings → Grid RSS** (when the theme is not active).
4. Go to the Catalog tab and toggle on the feeds you want, or paste custom RSS URLs on the Feeds tab.
5. Click "Import Now" or wait for the next cron tick.

== Frequently Asked Questions ==

= Will the plugin keep my data if I uninstall it? =

No, not by default. Uninstalling removes the feed list, settings, and dedupe history. Your already-imported posts are kept either way — those are your content. If you want to preserve plugin data across delete-and-reinstall, check "Keep my data if I uninstall this plugin" on the Settings tab BEFORE uninstalling.

= Why are some feeds showing red status dots? =

Red means the most recent fetch returned an error. Hover the dot for the message, or check Diagnostics → Last import log. Failed feeds are backed off for 10 minutes before being retried.

= Why is a feed green but importing nothing? =

Green means the HTTP fetch succeeded, but the feed may be returning empty or stale data — typically because the publisher deprecated their RSS without taking the URL offline. The "Feed health check" card on the Diagnostics tab flags feeds with this pattern.

= How does deduplication work? =

Each imported item's GUID is hashed and recorded in both postmeta and a persistent ledger table. The ledger survives post deletion, so trashed or permanently-deleted posts are not re-imported on the next fetch.

= Does the plugin require The Grid Index theme? =

No. It works as a standalone importer with any theme. Pairing with The Grid Index theme adds source-attribution display and a "Read at Source" CTA.

= Can I add a feed not in the catalog? =

Yes. Feeds tab → "+ Add Feed" → paste the RSS URL. Form auto-saves about 800ms after you stop typing.

== Screenshots ==

1. The plugin's admin page — feeds you've enabled, their fetch intervals, and live status. Designed to pair with The Grid Index theme but works as a standalone importer with any theme.
2. Browse a curated catalog of 47 RSS feeds from independent publishers (NYT, BBC, Guardian, NPR, TechCrunch, Bloomberg, Politico, NASA, more). One click toggles a feed on. Each carries a recommended fetch interval based on the publisher's update cadence.
3. Add any RSS URL manually. Set a fetch interval per feed (5 min for wire services, 15 min for general news, 30 min or hourly for slower-changing sources). Per-row "Fetch now" button for immediate import.
4. Granular import settings — post status (publish vs draft for review), minimum-width image filter, attachment sideloading, posts-per-feed cap, and a "keep my data on uninstall" opt-in.
5. Per-feed health diagnostics — last fetch time, posts imported in the lookback window, and status verdict (OK / stale / failing). Plus duplicate detection across imported posts and a one-click merge tool.
6. Imported posts land as standard WordPress posts in the auto-created "RSS" category, tagged with their source name and the original article URL. Edit, schedule, or delete them like any other post.
7. When The Grid Index theme is active, imported posts automatically display a source-attribution chip and a "Read at Source" link to the original article. Standalone importer in any theme; enhanced presentation in The Grid Index.
8. Light mode and dark mode both supported. Color mode follows your WordPress admin preference.

== Changelog ==

= 1.0.74 =
* Readme — rewrote the `== Screenshots ==` captions to match the planned 8-shot WP.org screenshot package: hero/Feeds tab, Catalog browsing, custom-URL feed addition, Settings, Diagnostics health table, imported posts in the WP editor, front-end view with The Grid Index theme active, and a light-mode shot. No code changes; readme-only.

= 1.0.73 =
* Renamed the main PHP class from `Grid_Index_RSS_Importer` to `TheGridIndex_RSS_Importer` and the version constant from `GRID_INDEX_RSS_IMPORTER_VERSION` to `THEGRIDINDEX_RSS_IMPORTER_VERSION` so all class- and constant-level identifiers carry the new slug prefix. WP.org Plugin Check (PrefixAllGlobals.NonPrefixedClassFound and NonPrefixedConstantFound) flagged the legacy names from the v1.0.67 trademark-clearance rename. Unlike the `gip_` runtime prefix — which is referenced by option keys, cron hook names, the custom database table, action hooks, and per-post meta and therefore must remain stable to preserve existing installs' data — the class name and version constant are static identifiers only ever called from within this plugin file; renaming them touches zero database state and has no upgrade-path concern. Updated all 6 internal call sites of the class name (instance creation, activation-hook callback, type-hint docblock, `@package` doc tag in `uninstall.php`) and all 7 internal references to the version constant (definition, version-badge display, asset enqueue cache-busting, etc.).

= 1.0.72 =
* External services disclosure — restructured the per-publisher table in the readme to point at each publisher's home page only, instead of hard-coded direct terms-of-service and privacy-policy URLs. WP.org review correctly flagged 12 dead URLs in the v1.0.71 readme (Al Jazeera, CBS/Paramount, Time, 9to5Mac, ZDNet/Red Ventures × 2, Financial Times × 2, Deutsche Welle × 2, France 24 × 2 — all 404 or 503). Direct legal-page URLs at third-party publishers are inherently unstable: publishers reorganize their help centers, migrate to parent-company legal portals, and change CMS paths regularly. Hard-coding 86 such URLs across 43 publishers would create permanent maintenance debt that no solo developer can keep up with, and the next round of rot would surface another 12 dead links a few months from now. The home-page-only structure is structurally correct: every publisher's site footer carries "Terms" and "Privacy" links to their current legal pages, those footer links are the publisher's own source of truth, and home-page URLs do not 404. The readme intro to the publisher table now explicitly walks the reader through this pattern (open the publisher's home, use the footer links) and names parent companies (Condé Nast, Dow Jones, NBCUniversal, Vox Media, Disney/Paramount, Yahoo, Mansueto Ventures, Red Ventures) where the publisher's legal pages live on the parent's domain. WP.org's example pattern in the External services guideline shows direct links for a single-service plugin; this plugin connects to 43 independent third-party publishers, which is a structurally different case.

= 1.0.71 =
* Final PHPCS cleanup: converted the last remaining single-line `phpcs:ignore` directive on the `DROP TABLE` query in `uninstall.php` to a paired `phpcs:disable` / `phpcs:enable` block, matching the pattern used for every other direct `$wpdb` call in the codebase. The previous v1.0.70 directive listed five rule codes on one line, three of which silenced correctly but two (`DirectQuery`, `NoCaching`) still fired in the most recent Plugin Check scan. Wrapping the statement in a disable/enable pair instead of relying on single-line scope makes the suppression deterministic. No functional code changes.

= 1.0.70 =
* PHPCS follow-up after the v1.0.66 pass: WordPress Plugin Check still surfaced 10 remaining issues because the suppression comments from the v1.0.66 pass used the single-line `phpcs:ignore` directive — which only silences the immediately-next line. Several of the affected statements were multi-line (a multi-line SQL string in `$wpdb->query()`, a multi-line `get_posts()` array argument, a multi-line `$wpdb->delete()` call), so the warnings still fired on the inner lines. Converted those six call sites to use paired `phpcs:disable` / `phpcs:enable` directives that bracket the entire statement, matching the WP.org rule semantics. Also moved three `/* translators: */` block comments to sit immediately above their `_n()` / `esc_html__()` calls (the linter requires the comment to be on the line directly preceding the translation function — previously some were one line too far up because they were placed above the outer `esc_html()` or `printf()` wrapper). The net effect: every issue the previous Plugin Check scan reported should now be properly silenced, with no functional changes to the code.


For versions prior to 1.0.70, see `changelog.txt` shipped in the plugin folder.
