"""Live go-live / health check of a WordPress affiliate site, the way a visitor (and Google) sees it.

Usage:
  python site_check.py --base https://example.com [--paths paths.txt] [--sitemap] [--tag name-20] [--extra-tag name2-20]
                       [--claim-words "expert|doctor|vet"] [--out report.md]

--paths    text file, one path per line ("/", "/about/", ...).  --sitemap reads /sitemap_index.xml instead (or as well).
Checks per page: HTTP status, cache header, exactly one <title>/H1/meta description, title 30-65 / description 110-165 chars,
canonical = self, noindex, http:// assets, JSON-LD parses (types listed), FAQPage questions visible on the page,
Amazon links carry rel sponsored+nofollow and the tracking ID, affiliate disclosure present when Amazon links exist,
no Amazon-hosted images, unproven "<expert> approved/recommended" claims, draft text, images without alt.
Exit code 1 if any page has a FIX-level problem.  Standard library only.
"""
import argparse, html, json, re, sys, urllib.request

UA = {"User-Agent": "Mozilla/5.0 (site check)"}


def get(url, timeout=40):
    req = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.status, dict(r.headers), r.read().decode("utf-8", "ignore")


def text(x):
    x = re.sub(r"<(script|style)\b[^>]*>.*?</\1>", " ", x, flags=re.S | re.I)
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", x))).strip()


def sitemap_paths(base):
    out = []
    _, _, idx = get(base + "/sitemap_index.xml")
    for sm in re.findall(r"<loc>(.*?)</loc>", idx):
        try:
            _, _, body = get(sm)
        except Exception:
            continue
        out += re.findall(r"<loc>(.*?)</loc>", body)
    return [u.replace(base, "") or "/" for u in out]


def check(base, path, a):
    url = base + path
    issues = []
    try:
        code, hdr, s = get(url)
    except Exception as e:
        return url, [("FIX", "cannot open: %s" % getattr(e, "code", e))], {}
    info = {"cache": hdr.get("x-litespeed-cache") or hdr.get("X-LiteSpeed-Cache") or hdr.get("cf-cache-status") or "-"}
    if code != 200:
        issues.append(("FIX", "status %s" % code))
    t = re.findall(r"<title>(.*?)</title>", s, re.S)
    title = html.unescape(t[0].strip()) if t else ""
    if len(t) != 1:
        issues.append(("FIX", "%d title tags" % len(t)))
    elif not 30 <= len(title) <= 65:
        issues.append(("CHECK", "title %d chars" % len(title)))
    d = re.findall(r'<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)', s, re.I)
    if len(d) != 1:
        issues.append(("FIX", "%d meta descriptions" % len(d)))
    elif not 110 <= len(html.unescape(d[0])) <= 165:
        issues.append(("CHECK", "description %d chars" % len(html.unescape(d[0]))))
    h1 = len(re.findall(r"<h1\b", s, re.I))
    if h1 != 1:
        issues.append(("FIX", "%d H1" % h1))
    c = re.search(r'<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)', s, re.I)
    if not c:
        issues.append(("CHECK", "no canonical"))
    elif c.group(1).rstrip("/") != url.rstrip("/"):
        issues.append(("CHECK", "canonical -> " + c.group(1)))
    if re.search(r'<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*noindex', s, re.I):
        issues.append(("FIX", "noindex"))
    if re.search(r'(?:src|href)="http://', s):
        issues.append(("CHECK", "http:// asset or link"))
    main = re.search(r"<main\b.*?</main>", s, re.S | re.I)
    main = main.group(0) if main else s
    mtext = text(main)
    types, faq = [], []
    for block in re.findall(r'<script[^>]*application/ld\+json[^>]*>(.*?)</script>', s, re.S):
        try:
            j = json.loads(block)
        except Exception:
            issues.append(("FIX", "invalid JSON-LD block"))
            continue
        nodes = j.get("@graph", [j]) if isinstance(j, dict) else j
        for n in nodes if isinstance(nodes, list) else [nodes]:
            if not isinstance(n, dict):
                continue
            ty = n.get("@type")
            types += ty if isinstance(ty, list) else [ty]
            if "FAQPage" in (ty if isinstance(ty, list) else [ty]):
                faq += [q.get("name", "") for q in n.get("mainEntity", []) if isinstance(q, dict)]
            if ty == "Recipe" or (isinstance(ty, list) and "Recipe" in ty):
                if not n.get("image"):
                    issues.append(("FIX", "Recipe schema has no image (critical for Google)"))
    dupes = sorted({x for x in types if x and types.count(x) > 1 and x in ("Recipe", "FAQPage", "Product", "Article", "BreadcrumbList")})
    if dupes:
        issues.append(("FIX", "duplicate schema types: " + ", ".join(dupes)))
    low = mtext.lower()
    miss = [q for q in faq if q and q.lower().strip() not in low]
    if miss:
        issues.append(("FIX", "%d/%d FAQ schema questions not visible" % (len(miss), len(faq))))
    amz = bad_rel = bad_tag = 0
    tags = [x for x in (a.tag, a.extra_tag) if x]
    for attrs in re.findall(r"<a\b([^>]*)>", main, re.I):
        h = re.search(r'href=["\']([^"\']+)', attrs)
        if not h or not re.search(r"^https?://([a-z0-9.-]*\.)?(amazon\.[a-z.]+|amzn\.to)/", html.unescape(h.group(1)), re.I):
            continue
        amz += 1
        if not (re.search(r'rel=["\'][^"\']*sponsored', attrs) and re.search(r'rel=["\'][^"\']*nofollow', attrs)):
            bad_rel += 1
        if tags and "amazon." in h.group(1) and not any(re.search(r"[?&;]tag=" + re.escape(t) + r"(&|$)", html.unescape(h.group(1))) for t in tags):
            bad_tag += 1
    if bad_rel:
        issues.append(("FIX", "%d Amazon links without rel sponsored+nofollow" % bad_rel))
    if bad_tag:
        issues.append(("FIX", "%d Amazon links without your tracking ID" % bad_tag))
    if amz and not re.search(r"as an amazon associate|qualifying purchases|commission|affiliate", s, re.I):
        issues.append(("FIX", "Amazon links but no disclosure"))
    if re.search(r'<img[^>]+src=["\'][^"\']*(media-amazon\.com|images-amazon\.com|ssl-images-amazon)', s, re.I):
        issues.append(("FIX", "Amazon-hosted image on page"))
    if a.claim_words:
        for m in re.finditer(r"\b(" + a.claim_words + r")[- ](approved|recommended|endorsed|certified|tested)\b", mtext, re.I):
            before = mtext[max(0, m.start() - 60):m.start()].lower()
            if re.search(r"\b(not|no|never|don't|do not|does not|doesn't|isn't|aren't|without|nor)\b", before):
                continue  # a negation such as "we do not call any product expert approved"
            issues.append(("FIX", 'claim "%s" - keep only if a named professional really did this' % m.group(0)))
            break
    m = re.search(r"editor note|lorem ipsum|\bTODO\b|coming soon|\[placeholder", mtext, re.I)
    if m:
        issues.append(("CHECK", 'draft text "%s"' % m.group(0)))
    noalt = len(re.findall(r'<img\b(?![^>]*\balt=)[^>]*>', main, re.I))  # alt="" is fine for decorative images
    if noalt:
        issues.append(("CHECK", "%d images without alt" % noalt))
    info.update(words=len(mtext.split()), amazon=amz, schema=",".join(sorted({x for x in types if x})))
    return url, issues, info


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", required=True)
    ap.add_argument("--paths")
    ap.add_argument("--sitemap", action="store_true")
    ap.add_argument("--tag", default="")
    ap.add_argument("--extra-tag", default="")
    ap.add_argument("--claim-words", default="expert|doctor|dentist|nutritionist|dietitian|vet|veterinarian|dermatologist")
    ap.add_argument("--out")
    a = ap.parse_args()
    base = a.base.rstrip("/")
    paths = []
    if a.paths:
        paths += [l.strip() for l in open(a.paths, encoding="utf-8") if l.strip() and not l.startswith("#")]
    if a.sitemap or not paths:
        paths += sitemap_paths(base)
    paths = list(dict.fromkeys(p if p.startswith("/") else "/" + p for p in paths))
    lines, bad = ["# Site check: %s (%d pages)" % (base, len(paths)), ""], 0
    for p in paths:
        url, issues, info = check(base, p, a)
        fix = [i for i in issues if i[0] == "FIX"]
        bad += 1 if fix else 0
        head = "OK " if not issues else ("FIX" if fix else "CHK")
        lines.append("- **%s** %s  `%s`" % (head, p, " ".join("%s=%s" % kv for kv in info.items())))
        lines += ["    - %s: %s" % i for i in issues]
    lines += ["", "Pages with problems to fix: %d of %d" % (bad, len(paths))]
    out = "\n".join(lines)
    print(out)
    if a.out:
        open(a.out, "w", encoding="utf-8").write(out + "\n")
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()
