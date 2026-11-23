"""Copy the bundled Site SEO plugin template into a project and give it the site's own prefix and name.

Usage:
  python make_seo_plugin.py --prefix abc --name "ABC SEO" --out "<project>/build" [--version 1.0.0]

--prefix  2-6 lowercase letters, unique to the site (used for classes, options, tables, CSS).
--name    menu / plugin name shown in WordPress.
--out     folder that will receive <prefix>-seo/ (must not already contain it: nothing is overwritten).
"""
import argparse, os, re, shutil, sys

HERE = os.path.dirname(os.path.abspath(__file__))
TPL = os.path.join(HERE, "..", "assets", "seo-plugin", "acme-seo")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--prefix", required=True)
    ap.add_argument("--name", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--version", default="1.0.0")
    a = ap.parse_args()
    p = a.prefix.lower()
    if not re.fullmatch(r"[a-z]{2,6}", p) or p == "acme":
        sys.exit("prefix must be 2-6 lowercase letters (and not 'acme')")
    dest = os.path.join(a.out, p + "-seo")
    if os.path.exists(dest):
        sys.exit("refusing to overwrite existing " + dest + " (back it up / bump the version instead)")
    shutil.copytree(TPL, dest)
    P = p.upper()
    pairs = [
        ("ACME_SEO", P + "_SEO"), ("acme_seo", p + "_seo"), ("acme-seo", p + "-seo"), ("acmeSeo", p + "Seo"),
        ("_acme_", "_" + p + "_"), ("acme-pill", p + "-pill"), ("acme-card", p + "-card"), ("acme-audit-", p + "-audit-"),
        ("acme-reviewed", p + "-reviewed"), ("acme_custom", p + "_custom"), ("acme_rv_", p + "_rv_"), ("acme_product", p + "_product"),
        ("'ACME'", "'" + P + "'"), ("Site SEO", a.name), ("1.0.0", a.version),
    ]
    for root, _, files in os.walk(dest):
        for f in files:
            fp = os.path.join(root, f)
            s = open(fp, encoding="utf-8").read()
            for x, y in pairs:
                s = s.replace(x, y)
            open(fp, "w", encoding="utf-8", newline="\n").write(s)
    os.rename(os.path.join(dest, "acme-seo.php"), os.path.join(dest, p + "-seo.php"))
    left = []
    for root, _, files in os.walk(dest):
        for f in files:
            if "acme" in open(os.path.join(root, f), encoding="utf-8").read().lower():
                left.append(f)
    print("created", dest)
    if left:
        print("WARNING: 'acme' still appears in", left)


if __name__ == "__main__":
    main()
