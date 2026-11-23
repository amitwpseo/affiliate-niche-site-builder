# Working and honesty rules

## Why these rules exist
An affiliate site lives on trust (readers), on the Amazon Associates agreement (income), and on Google's helpful-content and spam policies (traffic). One fake rating or one copied image can cost all three. The user also uploads every release by hand, so a careless build wastes their time and a bad overwrite can lose work.

## Honesty (applies to code, content and schema)
- No invented ratings, review counts, prices, stock, "best seller" badges, test results or "we tested" claims. Show prices only via the official API (Product Advertising API / Creators API) with its timestamp, or not at all.
- `aggregateRating` / `review` schema only from real, visible reviews. Never to "fill a Rich Results warning".
- No "<expert> approved / recommended / formulated" unless a named, real person with that credential reviewed the page. The "Reviewed by" line and `reviewedBy` schema appear only when name, credentials and date are filled in.
- Product images: never hotlink or copy Amazon or manufacturer photos. Use API-delivered images when the API is active, otherwise a neutral product card (icon, title, short verdict, button) or the user's own photos / licensed stock / AI images clearly not depicting the real product as if it were.
- Your own opinion lines ("Our verdict", "Best for") are drafts the user approves; mark them as opinion.
- FAQ schema only for questions printed on the page. HowTo rich results are retired; FAQ rich results are limited — keep FAQ for users, not for snippets.
- Health, safety, money, legal topics: cite authoritative sources (government, universities, professional bodies), add a "talk to a professional" note, and keep claims within what the sources say.

## Amazon Associates essentials (check current Operating Agreement for the user's store)
- Disclosure near the top of every page with links, and site-wide: "As an Amazon Associate I earn from qualifying purchases." (or the store's required wording).
- Every Amazon link: tracking ID present, `rel="sponsored nofollow"` (plus `noopener` when `target="_blank"`).
- No links in emails/PDFs/offline, no link cloaking that hides amazon.com, no prices without API data, no "click here to support us" incentives.
- API access needs qualifying sales (commonly 3 within 180 days, then ongoing sales). Until then, build products from user-supplied data (ASIN, title, notes) and keep an "add product by link / AI draft" helper, not scraping.

## Privacy and credentials
- Never put the user's name, email, address or account IDs into code, content, schema, commit messages or outside requests unless they ask.
- Never ask for passwords, API keys or tokens in chat. The user enters them in WordPress settings. Plugins store keys server-side and never print them back.
- Contact forms/newsletter: name the provider; a privacy policy must cover cookies, analytics and ads (third-party vendors including Google use cookies to serve ads).

## Versioning and backups
- Before editing an existing plugin/theme: zip its current source to `build/backups/<slug>-<version>-source-backup.zip`.
- Bump the version in the plugin header, the version constant and `readme.txt` (Stable tag + changelog line).
- Output `build/dist/<slug>-<version>.zip`; refuse to overwrite an existing zip.
- One-time data fixes on update: run once on `admin_init` behind a version flag option, never touch text the user edited, purge cache afterwards.

## How to talk to the user
- Plain words, short. Lead with the result: what's built, where the zip is, what to upload/click, what's not verified.
- When the user says "don't change, just tell me": answer and stop. When they say "go"/"use your recommendations": proceed without more questions.
- Report failures honestly (lint errors, live check problems, things you couldn't test).
