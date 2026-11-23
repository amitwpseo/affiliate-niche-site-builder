# Launch, domain move and the Google side

## Before go-live (staging)
1. `site_check.py --base <staging> --sitemap --tag <id>` until clean; plugin Audit tab clean or explained.
2. Search all content and options for the staging host name; replace with the live domain (Search Replace DB / WP-CLI by the host).
3. Featured image on every post, logo uploaded (square ≥112 px), social image 1200×630, favicon (Site Icon in Customizer — don't reference raw upload URLs).
4. Decide publish status of every page; drafts must not be linked from published pages.
5. Privacy policy covers analytics, cookies and ads; disclosure present.

## Moving from an old site
- Build the map: `python scripts/redirect_map.py --old-sitemap <old>/sitemap_index.xml --new-paths new_paths.txt --out build/reports/redirect-map-<date>.md`. Review every line with the user: 301 to the closest real equivalent, 410 only for content that is truly gone, never mass-redirect everything to the home page.
- Old assets (`/wp-content/uploads/...` images) normally need no rules. Never 410 a file the new site still uses (logo, favicon) — restore the file instead.
- Old WordPress core sitemaps (`/wp-sitemap*.xml`, `/post-type-*-sitemap-1.xml`) can 301 to `/sitemap_index.xml`.
- After launch: Monitor tab → "Test all redirects" (no chains, one 301 to a 200), then watch the 404 log weekly and Fix real ones.
- Keep redirects at least 180 days (better a year). Publish `/old-urls.xml` (temporary sitemap of old addresses) for ~45 days so Google re-crawls them; "redirecting URL" warnings for it are expected. Then remove it.
- Change of Address tool in Search Console only for a domain change, not for a redesign on the same domain.

## Search Console
- Property: Domain property if DNS access, else URL prefix with https.
- Sitemaps: submit `/sitemap_index.xml`; remove old child-sitemap entries that now redirect; submit `/old-urls.xml` temporarily if moving.
- URL Inspection → Request indexing for the home page and the main hubs/money pages (not all pages).
- Watch Pages (indexing) and Enhancements; fix critical schema errors (e.g. missing image), ignore optional-field warnings unless the data is real.

## Rank Math setup choices
- Wizard "This site is a…": Small Business Site / Other Business Website (affiliate business); Business type Organization; name = brand; alternate name optional; logo square ≥112 px; default social image 1200×630. Not "Personal Blog" unless it really speaks as a person.
- Modules: Sitemap, Schema, Link Counter, SEO Analysis, Image SEO, Analytics (optional) on. Instant Indexing (IndexNow: Bing/Yandex, not Google) on. 404 Monitor and Redirections off when the companion plugin handles them (one tool per job). LLMs Txt optional (no ranking effect).
- Titles & Meta: noindex thin archives (tags, date, author if single-author), keep category/hub archives indexable only if they have intro text.

## Google Site Kit
- Goal: "Monetize content" (affiliate links). Connect Search Console + Analytics (GA4). Consent mode/cookie banner if EU/UK visitors.

## AdSense (optional, later)
Apply when the site has real original content, working trust pages, a privacy policy mentioning ad cookies, and some traffic. Approval is Google's decision (days to weeks; rejections for "low value content" are common on new affiliate sites). Keep ads away from affiliate buttons and never make them look alike. Add `ads.txt` after approval.

## After launch (first weeks)
- Re-run `site_check.py` on the live domain; verify redirects, schema, sitemap health.
- Weekly: Monitor 404s, Search Console coverage, product links still valid.
- Every ~6 months: Reviews tab freshness list; update facts, prices disclaimers, models.
