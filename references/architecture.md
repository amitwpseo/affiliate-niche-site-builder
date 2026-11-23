# Architecture: child theme + engine plugin + SEO companion plugin

Three separately versioned packages so the user can update one without touching the others. `<p>` = the site prefix (e.g. `abc`), `<P>` = upper-case.

## 1. Child theme `<p>-child` (parent: Hello Elementor)
- `style.css` header (Template: hello-elementor), `functions.php` loads `inc/*.php`.
- Design tokens as CSS custom properties (colors, radius, spacing, fonts) in one place; light, readable, mobile-first; system or one Google font.
- Header/footer templates: logo, primary menu, footer menus (legal pages), social icons read from an option, site-wide affiliate disclosure line.
- Template helpers: breadcrumbs (print BreadcrumbList schema only when Rank Math breadcrumbs are off — provide a filter so the SEO plugin can switch the theme's schema off), reading time, table of contents.
- Keep theme presentational; all data/logic lives in the engine plugin so a theme switch never loses content.

## 2. Engine plugin `<p>-engine`
Classes (`<P>_Name`, file `includes/class-name.php`), each with a static `init()` hooked on `plugins_loaded`:

| Class | Job |
|---|---|
| Settings | One option array (tracking ID, store domain, API keys, feature switches: product schema, FAQ schema, niche schema). `get( $key )` helper. Settings page under Settings menu. |
| Products | CPT `<p>_product` (not public archive), meta: ASIN, short title, best for, verdict, score (only if your own scale, labelled), pros/cons, category, stock, last checked. Admin columns. Import by ASIN when the API is active. |
| Api | Amazon PA-API / Creators API client with caching (transients), rate limiting and clear errors ("account not eligible yet" is normal before qualifying sales). |
| Render | Product card / comparison table / button HTML. Builds links `https://www.amazon.<store>/dp/<ASIN>?tag=<tag>` with `rel="sponsored nofollow noopener"`. No Amazon images unless API-delivered. |
| Picks | Admin screen to choose featured products per section (e.g. home "Top picks" slots) with optional per-section tracking ID for measuring. |
| AI add (optional) | "Add product by link" tab: user pastes an Amazon URL + their own notes; a free-tier LLM (user's own key, e.g. Gemini) drafts title/best-for/verdict/pros/cons JSON; user reviews and saves. Fallback models on 404/503; never auto-publish. |
| Shortcodes + Elementor widgets | Same renderers exposed as shortcodes and Elementor widgets (product box, comparison table, picks grid, FAQ, CTA, video facade). Register every widget class in the widget list — a missing class silently renders an empty section. |
| FAQ | Registry: each FAQ block registers its Q&A while rendering; one FAQPage JSON-LD printed in `wp_footer` from what was actually printed. Source data in `includes/faq-data.json` built from the FAQ source file. Exclude TOC `<summary>` text when counting questions. |
| Niche content type (optional) | E.g. reviews, guides, recipes, plans: CPT + meta box + card + schema of the right type (Review/Product only with real data, Recipe, HowTo-free). |
| Schema pages | Hooks `rank_math/json_ld` to add page types (AboutPage, ContactPage, CollectionPage + ItemList on hubs). Add to Rank Math's graph instead of printing a second block — duplicates confuse validators. |
| Demo / builder | Creates the planned pages as Elementor data (`_elementor_data`, `_elementor_edit_mode = builder`) from a content array, marks them `_<p>_made = 1`, and saves a plain-text `post_content` snapshot so Rank Math's analysis reads real text. Never overwrites a page the user edited. |
| Compliance | Footer disclosure, checks that links carry the tag, warns about Amazon image URLs in content. |
| Cron | Daily product refresh when the API is active; marks `gone` products. |
| Fixes | One-time migrations per version behind a flag option. |

Guard every own-SEO feature (titles, meta, schema graph) with "is another SEO plugin active?" so Rank Math wins and nothing is printed twice.

## 3. SEO companion plugin `<p>-seo` (Rank Math companion)
Start from the template (`scripts/make_seo_plugin.py`). Included modules:
- **Settings** — tracking IDs, post types to check, product CPT + meta keys, expert words to flag.
- **Redirects** — runs on `template_redirect` priority 0, only when WordPress would 404; built-in rules from `includes/redirect-map.php` + user rules textarea (`/old/ => /new/` or `=> 410`); never redirects to unpublished targets; 410 page with `X-Robots-Tag: noindex`.
- **Monitor** — tables `<p>_seo_urls` (aggregated hits per path/code, Googlebot/Bingbot counts) and `<p>_seo_bots` (recent verified hits, pruned); bots verified by reverse + forward DNS, cached 12 h, no IP stored; 404 log with Fix (adds a rule); redirect tester (manual hop-following, chains/loops/dead ends); sitemap health (200, indexable, self-canonical; daily cron); temporary `/old-urls.xml` for 45 days after the move date; 180-day "keep redirects" card; CSV exports.
- **Audit** — AJAX batches (4 pages/request) fetch each published page as a visitor and check SEO basics, schema validity, FAQ visibility, affiliate rules, honesty rules, plus an internal link graph (orphans, links to redirects/404/unpublished). Niche checks plug in through `<p>_seo_audit_checks` (see niche-modules.md).
- **Reviews** — named reviewer meta box (all three fields or nothing), visible line + `reviewedBy`/`lastReviewed` on the WebPage node, freshness list (180 days) with "Mark checked today".
- **Tools** — AI crawler robots.txt choices (none blocked by default; warn if a physical robots.txt exists), Rank Math module status (IndexNow worth turning on; 404 Monitor/Redirections duplicate this plugin), weekly e-mail summary (off by default), product health, tracking IDs, change log.

Add per site: Rank Math one-click setup (titles/meta/focus keyword per planned page from a `data.php`, global schema settings, breadcrumbs, social profiles, backups before writing, export/import), per-page schema meta box (choose Article type or add custom JSON-LD validated before save).

## Known pitfalls (learned the hard way)
- **Page cache** (LiteSpeed, Cloudflare): cached pages never run PHP, so bot logging only sees uncached responses (redirects, 404, 410). Purge cache after data changes (`do_action( 'litespeed_purge_all' )`). After a domain move, stale cache can briefly show old pages.
- **Theme button styles** (Hello/Elementor kits) override plugin buttons: use higher-specificity selectors (`figure.x button.x__btn`) rather than fighting with generic classes.
- **Video embeds**: use a lightweight facade (poster + play button that loads the iframe on click); check the poster renders, use `i.ytimg.com/vi/<id>/hqdefault.jpg`.
- **Patch scripts**: when editing big PHP files with scripts, assert every anchor exists (`assert a in s`) — a silent no-match ships a broken release. Regex anchors must tolerate indentation.
- **Shell quoting** on Windows: write helper scripts to files and run them instead of long inline heredocs.
- **Validators**: H1 counting regex must be `<h1\b`; FAQ counts must exclude TOC summaries; Rich Results "missing image" on Recipe/Product = set a featured image (critical), optional fields are just warnings.
- **Domain move**: MCP/REST connectors and hard-coded staging URLs must be updated; search content for the staging host before launch.
