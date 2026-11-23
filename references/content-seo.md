# Research, content and on-page SEO

## 1. Keyword research
Sources: Search Console exports (if a site exists), Google autocomplete/People Also Ask, competitor pages that rank, Amazon category and "customers also bought" structure, Reddit/forums for real questions. If a keyword tool MCP (e.g. DataForSEO) is available, use it for volume/difficulty; otherwise state that volumes are estimates.
Group by intent:
- **Commercial** ("best X for Y", "X vs Y", "X review") → buying guides, comparisons, top-picks hub.
- **Informational** ("how to", "can I", "why") → guides that link to the buying guides.
- **Navigational/brand** → usually skip.
Build clusters (hub + spokes). One primary keyword per page; avoid two pages chasing the same query (cannibalization).

## 2. Page set (adapt to the niche)
- Home (what the site helps with, top picks, start-here links, latest guides).
- Start here / beginner guide.
- Hubs per category (CollectionPage + ItemList), each linking to its guides and picks.
- Buying guides / best-of lists (the money pages), comparisons, single-product reviews only with real hands-on use or clearly labelled research-based summaries.
- Informational guides answering the PAA questions, 1,200–1,800 words when the topic needs it (length follows the question, not a quota).
- FAQ page (collects the site's questions).
- Trust pages: About, Contact, Affiliate disclosure, Editorial policy (how products are chosen, no paid placement), Privacy, Terms, Disclaimer (medical/financial/safety as the niche needs).
- Newsletter page only if a provider is actually connected.

## 3. On-page targets
- Title 30–65 chars (primary keyword early, brand at the end), meta description 110–165 chars and not a restatement of the title.
- Exactly one H1; logical H2/H3; short intro that answers the query in the first 2–3 sentences.
- Table of contents on long pages; "Our top picks" box near the top on buying guides with a disclosure line.
- Each product: who it's for, why, one honest drawback, button. Comparison table with real attributes.
- Internal links: every page gets ≥3 contextual links in and out; hubs link down, guides link up and across; no orphan pages; link to final URLs (not redirects).
- External links to authoritative sources where facts are claimed.
- Images: WebP, width/height set, descriptive alt, own or licensed; featured image set on every post (schema needs it); 1200×630 social image.
- Author/brand box and "last updated" date; "Reviewed by" only with a real reviewer.

## 4. FAQ system
1. Build an FAQ source file per page from PAA + forums + the keyword list (5–8 questions per page, answers 40–80 words, factual, sourced where needed).
2. Questions shown on the page = questions in FAQPage schema. Nothing hidden.
3. One FAQ page collecting the best questions, grouped by topic, each linking to its detailed page.

## 5. Schema per page type (through Rank Math's graph)
| Page | Types |
|---|---|
| Home | WebSite (+SearchAction if search works), Organization (logo ≥112 px square, sameAs socials) |
| Hub | CollectionPage + ItemList of the guides/products shown |
| Buying guide / guide | Article or BlogPosting (author = Organization unless a real person writes it), BreadcrumbList |
| Product box | No Product/Offer/Review schema unless price/rating data is real and visible (API) |
| Niche type | e.g. Recipe (image, ingredients, steps, times), Course, SoftwareApplication — only with real visible data |
| About / Contact | AboutPage / ContactPage |
| FAQ blocks | FAQPage from visible questions only |
Validate with Google's Rich Results Test and validator.schema.org; aim for no duplicate types per page.

## 6. Content quality bar
Helpful, specific, first-hand where possible (photos, measurements, what was actually tried); no filler; no AI-sounding boilerplate; cite sources for facts; date-stamp anything that changes (prices, models). Thin pages either get expanded or merged.
