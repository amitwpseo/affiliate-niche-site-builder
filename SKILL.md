---
name: affiliate-niche-site-builder
description: Plan, build, check and launch a complete Amazon-affiliate niche website on WordPress (Hello Elementor child theme + a site "engine" plugin for products, Amazon links, widgets, FAQ and schema + a Rank Math companion SEO plugin with redirects, Googlebot monitor, site audit, reviews and tools), for any niche. Use this whenever the user wants a new affiliate site, niche site, product-review or buying-guide site, says "new site for [niche]", "do the same for another niche", wants to rebuild or migrate an existing affiliate site, needs keyword research + page plan + WordPress plugins/theme for an affiliate site, or asks for a go-live / SEO / schema / compliance check of such a site — even if they don't say "skill" or name every part.
---

# Affiliate niche site builder

Builds an honest, fast, search-ready Amazon-affiliate site for whatever niche the user picks: research → plan → theme and plugins → content → checks → launch. You produce the files (zips, content, reports); the user uploads them and does the account steps. Work in phases and stop for the user's go-ahead at each gate, because they upload by hand and a wrong build costs them real time.

## Before you start: collect inputs

Ask only for what is missing (offer sensible defaults, and accept "use your recommendations"):

- Niche and audience (country/store: amazon.com, .co.uk, ...), and the one thing the site helps people do.
- Domain (or staging URL), brand name, a 2-6 letter **prefix** for code (e.g. `abc`), site language.
- Amazon tracking ID (looks like `name-20`). Never invent one.
- Existing site to replace? Then its sitemap URL (for the redirect map).
- Hosting/caching (LiteSpeed, Cloudflare…), page builder (default Hello Elementor + Elementor free), SEO plugin (default Rank Math free).
- Who the site speaks as: a brand/organization by default. Do not use the user's personal name as author or in schema unless they ask; never put personal details (name, email, address) into code, content or outside requests.
- Any real expert who reviews content (name + credentials). If none, the site makes no expert claims.

## Phases and gates

Keep a task list. After each phase, report briefly and wait for "go" (the user may say "do all phases" — then continue, but still report at the end).

1. **Research and plan** → read `references/content-seo.md`. Keyword research and clusters, page list with slugs/titles/meta/intent, internal-link map, product categories, schema per page type, FAQ source file. Deliver a plan file in `build/reports/`. Gate.
2. **Niche rules** → read `references/niche-modules.md`. Research what is unsafe or misleading in this niche (health, food, finance, child or electrical safety…), write the niche rules list and the audit checks. Gate (combine with phase 1 if the user prefers).
3. **Build the code** → read `references/architecture.md` and `references/rules.md`.
   - SEO companion plugin: run `python scripts/make_seo_plugin.py --prefix <p> --name "<Brand> SEO" --out <project>/build` — gives Redirects, Monitor, Audit, Reviews, Tools, Settings out of the box. Add the site-specific parts (Rank Math one-click setup data, page-type schema, niche audit checks via the `<p>_seo_audit_checks` filter).
   - Engine plugin and child theme: write them for this site following the architecture reference (products CPT, Amazon link builder, widgets, FAQ registry + schema, page builder demo content, settings).
   - Lint every PHP folder: `cd scripts/phplint && npm install` (first time), then `node scripts/phplint/lint.js <folder>`. There is usually no PHP runtime; say so honestly — lint is syntax only, not a WordPress test.
   - Zip as `build/dist/<slug>-<version>.zip` (the folder inside the zip = plugin slug). Never overwrite an existing version; bump the version and add a readme changelog line.
4. **Content** → write pages from the plan: unique, useful, first-hand where possible, internal links, disclosure, FAQ only from visible questions. Honesty rules in `references/rules.md` apply to every sentence.
5. **Check** → run `python scripts/site_check.py --base <url> --sitemap --tag <tracking-id> --out build/reports/site-check-<date>.md` on staging, then fix and re-run until clean. Also run the plugin's own Audit tab once installed.
6. **Launch** → read `references/launch.md`: redirects from the old site (`scripts/redirect_map.py`, reviewed with the user), Rank Math wizard and modules, Search Console sitemaps, old-address sitemap, 180-day redirect window, Site Kit, AdSense readiness. Then hand the user `references/user-checklist.md`, filled in.

## Working rules (short version — details in references/rules.md)

- **Back up before every change** to an existing plugin/theme: zip the current source to `build/backups/<slug>-<old-version>-source-backup.zip`, then edit, then bump the version.
- **Don't change things the user said to wait on.** If they say "don't change, just tell me", only answer.
- **Honesty:** no fake ratings, reviews, prices, stock or "tested by us"; no "<expert> approved" unless a named person really did it; no Amazon-hosted images or copied product photos (unless delivered by the official API); every Amazon link `rel="sponsored nofollow"` with the tracking ID; disclosure on every page with affiliate links.
- **Credentials:** never ask the user to paste passwords or keys in chat; they enter keys in WordPress settings themselves.
- **Verify, don't assume:** after the user installs a release, re-check the live site (status, schema, redirects) and report what you actually saw.
- **Explain in plain words.** Many users are not developers; keep reports short: what changed, what to upload, what to click, what is not verified.

## Bundled resources

- `scripts/make_seo_plugin.py` — copies the generic SEO companion plugin template (`assets/seo-plugin/acme-seo/`) with the site's prefix and name.
- `scripts/site_check.py` — live crawl check (SEO basics, schema, duplicate schema, FAQ visibility, affiliate rules, honesty rules). Exit 1 when something must be fixed.
- `scripts/redirect_map.py` — proposes old→new redirects from an old sitemap (review before use).
- `scripts/phplint/lint.js` — PHP syntax check without PHP (needs Node + `npm install`).
- `references/architecture.md` — theme/engine/SEO plugin design, hooks, options, known pitfalls.
- `references/content-seo.md` — research, page set, on-page targets, FAQ, schema, internal links, images.
- `references/rules.md` — honesty, Amazon Associates essentials, privacy, versioning.
- `references/niche-modules.md` — how to add niche safety/accuracy checks (with examples).
- `references/launch.md` — go-live, domain move, Search Console, Rank Math, Site Kit, AdSense.
- `references/user-checklist.md` — the manual steps only the user can do.
