"""Propose a redirect map from a previous site's sitemap to the new site's pages.

Usage:
  python redirect_map.py --old-sitemap https://old.example.com/sitemap_index.xml --new-paths new_paths.txt [--out map.md]
  (new_paths.txt: one planned/new path per line, e.g. /best-x/, /guides/how-to-y/)

Output: a Markdown review table and a ready-to-paste PHP 'rules' array for includes/redirect-map.php.
Matching is by slug-word overlap; anything below the threshold is proposed as 410 (gone) for a human to decide.
Paths that exist unchanged on the new site are listed as 'same' (no rule needed).
Always review the proposal with the user before using it: a wrong 301 is worse than a 404.
"""
import argparse, re, urllib.request
from urllib.parse import urlparse

UA = {"User-Agent": "Mozilla/5.0 (redirect map)"}
STOP = {"the", "a", "an", "and", "or", "for", "of", "to", "in", "on", "with", "your", "my", "how", "best", "top", "vs", "is", "are"}


def get(u):
    return urllib.request.urlopen(urllib.request.Request(u, headers=UA), timeout=40).read().decode("utf-8", "ignore")


def locs(sitemap):
    body = get(sitemap)
    urls = re.findall(r"<loc>(.*?)</loc>", body)
    out = []
    for u in urls:
        if u.endswith(".xml"):
            out += locs(u)
        else:
            out.append(u)
    return out


def norm(p):
    p = "/" + p.strip().lstrip("/").lower()
    return p if re.search(r"\.[a-z0-9]{2,5}$", p) else p.rstrip("/") + "/"


def words(p):
    return {w for w in re.split(r"[^a-z0-9]+", p.lower()) if w and w not in STOP and not w.isdigit()}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--old-sitemap", required=True)
    ap.add_argument("--new-paths", required=True)
    ap.add_argument("--threshold", type=float, default=0.34)
    ap.add_argument("--out")
    a = ap.parse_args()
    new = [norm(l) for l in open(a.new_paths, encoding="utf-8") if l.strip() and not l.startswith("#")]
    old = sorted({norm(urlparse(u).path) for u in locs(a.old_sitemap)})
    rows, same = [], []
    for o in old:
        if o in new:
            same.append(o)
            continue
        if o == "/":
            continue
        ow = words(o)
        best, score = None, 0.0
        for n in new:
            nw = words(n)
            if ow and nw:
                s = len(ow & nw) / len(ow | nw)
                if s > score:
                    best, score = n, s
        rows.append((o, best if score >= a.threshold else None, score))
    md = ["# Redirect map proposal", "", "Old sitemap: " + a.old_sitemap, "",
          "| Old path | Proposed target | Code | Match | Decide |", "|---|---|---|---|---|"]
    for o, t, s in rows:
        md.append("| `%s` | %s | %s | %.2f | %s |" % (o, "`%s`" % t if t else "-", 301 if t else 410, s, "check" if s < 0.6 else "ok"))
    md += ["", "Same address on the new site (no rule): " + (", ".join(same) or "none"), "", "```php"]
    for o, t, s in rows:
        if t:
            md.append("\t\tarray( 'from' => '%s', 'to' => '%s', 'code' => 301, 'note' => 'Closest match' )," % (o, t))
        else:
            md.append("\t\tarray( 'from' => '%s', 'to' => null, 'code' => 410, 'note' => 'No equivalent' )," % o)
    md += ["```", "", "'same' => array( %s )," % ", ".join("'%s'" % x for x in same)]
    out = "\n".join(md)
    print(out)
    if a.out:
        open(a.out, "w", encoding="utf-8").write(out + "\n")


if __name__ == "__main__":
    main()
