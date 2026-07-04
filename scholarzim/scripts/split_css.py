"""Split app.css into maintainable modules and remove duplicate polish layers."""
from pathlib import Path

CSS_DIR = Path(__file__).resolve().parents[1] / "src/main/resources/static/css"
css_path = CSS_DIR / "app.css.bak"

# Read original if backup exists, else current app.css
orig = CSS_DIR / "app.css"
if not css_path.exists():
    css_path.write_text(orig.read_text(encoding="utf-8"), encoding="utf-8")

lines = css_path.read_text(encoding="utf-8").splitlines(keepends=True)
print(f"Source lines: {len(lines)}")

prod_start = None
for i, line in enumerate(lines):
    if "Production SaaS UX" in line:
        prod_start = i - 1 if i > 0 and "════" in lines[i - 1] else i
        break

exclude_ranges = [
    (1060, 2229),
    (2229, prod_start - 1 if prod_start else 3116),
]

def in_exclude(idx: int) -> bool:
    return any(a <= idx <= b for a, b in exclude_ranges)

kept = [l for i, l in enumerate(lines) if not in_exclude(i)]
kept_text = "".join(kept)
prod_block = "".join(lines[prod_start:]) if prod_start else ""

tokens_end = kept_text.find("body {")
base_end = kept_text.find("/* Sidebar")
layout_end = kept_text.find("/* Stat cards")
landing_marker = kept_text.find("/* ============================================================")
if landing_marker == -1:
    landing_marker = kept_text.find("/* Apply wizard")

spacing = """
/* Production spacing */
:root {
    --sz-space-page: 1.5rem;
    --sz-space-section: 4rem;
    --sz-space-block: 1.25rem;
    --sz-radius-ui: 8px;
    --sz-border-subtle: 1px solid #e2e8f0;
}

@media (min-width: 768px) {
    :root {
        --sz-space-page: 2rem;
        --sz-space-section: 5rem;
    }
}
"""

tokens = kept_text[:tokens_end].strip() + "\n\n" + spacing.strip()
base = kept_text[tokens_end:base_end].strip()
layout = kept_text[base_end:layout_end].strip()
components = kept_text[layout_end:landing_marker].strip() if landing_marker > layout_end else kept_text[layout_end:].strip()

nav_start = prod_block.find("/* ── Public landing header")
nav_end = prod_block.find("/* ── App dashboard topbar")
landing_marketing = prod_block.find("/* Landing — clean marketing")

prod_prefix = prod_block[:nav_start].strip() if nav_start > 0 else ""
topbar_block = prod_block[nav_end:landing_marketing].strip() if nav_end > 0 and landing_marketing > 0 else ""
landing_block = prod_block[nav_start:].strip() if nav_start > 0 else prod_block

base = base + "\n\n" + prod_prefix
layout = layout + "\n\n" + topbar_block
landing = landing_block

(CSS_DIR / "tokens.css").write_text(tokens + "\n", encoding="utf-8")
(CSS_DIR / "base.css").write_text(base + "\n", encoding="utf-8")
(CSS_DIR / "layout.css").write_text(layout + "\n", encoding="utf-8")
(CSS_DIR / "components.css").write_text(components + "\n", encoding="utf-8")
(CSS_DIR / "landing.css").write_text(landing + "\n", encoding="utf-8")

app_css = """/* ScholarZim design system — modular */
@import url("tokens.css");
@import url("base.css");
@import url("layout.css");
@import url("components.css");
@import url("landing.css");
"""
(CSS_DIR / "app.css").write_text(app_css, encoding="utf-8")

for name in ["tokens.css", "base.css", "layout.css", "components.css", "landing.css", "app.css"]:
    n = len((CSS_DIR / name).read_text(encoding="utf-8").splitlines())
    print(f"  {name}: {n} lines")

print("Done.")
