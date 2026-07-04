"""Patch split CSS modules with missing rules from app.css.bak."""
from pathlib import Path

CSS = Path(__file__).resolve().parents[1] / "src/main/resources/static/css"
bak = (CSS / "app.css.bak").read_text(encoding="utf-8")
lines = bak.splitlines(keepends=True)

def slice_lines(start, end):
    return "".join(lines[start - 1 : end])

# landing.css additions
landing_extra = slice_lines(1046, 1059)  # container
landing_extra += "\n" + slice_lines(1085, 1158)  # brand + buttons
landing_extra += "\n" + slice_lines(1756, 1834)  # footer + landing empty
landing_extra += "\n" + slice_lines(2843, 2986)  # nav header

landing = (CSS / "landing.css").read_text(encoding="utf-8")
# Remove duplicate :root spacing block at top of landing
if landing.startswith("/* ═"):
    idx = landing.find("/* Disable decorative motion")
    if idx > 0:
        landing = landing[idx:]
        landing = landing[landing.find("/* Landing — clean marketing") :]

(CSS / "landing.css").write_text(
    landing_extra.strip() + "\n\n" + landing.lstrip(),
    encoding="utf-8",
)

# layout.css - replace old topbar with production
layout = slice_lines(145, 113)  # sidebar only through role badge area
layout = slice_lines(145, 257)  # through sidebar footer
layout += "\n" + slice_lines(2988, 3115)  # production topbar
layout += "\n" + """
/* Dashboard page structure */
.sz-dashboard-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem 1.5rem;
    padding-bottom: 1.25rem;
    border-bottom: var(--sz-border-subtle);
    margin-bottom: 1.5rem;
}
.sz-dashboard-header__title {
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    margin: 0 0 0.375rem;
    color: var(--sz-text);
}
.sz-dashboard-header__meta {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--sz-muted);
    margin: 0 0 0.5rem;
}
.sz-dashboard-header__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
}
.sz-profile-banner {
    border: var(--sz-border-subtle);
    border-radius: var(--sz-radius-ui);
    background: #fff;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}
.sz-profile-banner .progress { height: 6px; background: #f1f5f9; }
.sz-content { max-width: 1280px; }
.sz-main { background: #f8fafc !important; }
[data-bs-theme="light"] .sz-main { background: #f8fafc !important; }
"""
(CSS / "layout.css").write_text(layout, encoding="utf-8")

# base.css - add motion disable
base = (CSS / "base.css").read_text(encoding="utf-8")
motion = slice_lines(3120, 3160)
base += "\n\n" + motion
# Flatten btn hover
base = base.replace("transform: translateY(-1px);", "transform: none;")
(CSS / "base.css").write_text(base, encoding="utf-8")

# components - append app surface rules from old landing tail
comp = (CSS / "components.css").read_text(encoding="utf-8")
app_tail = """
/* Production app surfaces */
.sz-hero--gradient {
    background: var(--sz-card) !important;
    color: var(--sz-text) !important;
    border: var(--sz-border-subtle) !important;
    box-shadow: none !important;
}
.sz-section-card {
    border: var(--sz-border-subtle);
    border-radius: var(--sz-radius-ui);
    box-shadow: none;
}
.sz-section-card .card-header {
    background: #fff;
    border-bottom: var(--sz-border-subtle);
    font-weight: 600;
    font-size: 0.9375rem;
    padding: 0.875rem 1.25rem;
}
.sz-page-header {
    padding-bottom: 1rem;
    border-bottom: var(--sz-border-subtle);
    margin-bottom: 1.5rem;
}
.sz-page-header h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
.sz-empty {
    border: 1px dashed #e2e8f0;
    border-radius: var(--sz-radius-ui);
    background: #f8fafc;
}
.sz-opp-card {
    border: var(--sz-border-subtle);
    border-radius: var(--sz-radius-ui);
    box-shadow: none;
}
.sz-opp-card:hover { border-color: #cbd5e1; box-shadow: var(--sz-shadow); }
.sz-opp-title { font-size: 1rem; font-weight: 600; }
.sz-stat-card:hover { transform: none; box-shadow: var(--sz-shadow); }
.sz-auth-hero { background: #f8fafc !important; color: #0f172a !important; border-right: var(--sz-border-subtle); }
.sz-auth-hero::before { display: none !important; }
.sz-auth-panel::before { display: none !important; }
.sz-auth-card { box-shadow: none; border: var(--sz-border-subtle); }
"""
if "Production app surfaces" not in comp:
    comp += "\n" + app_tail
(CSS / "components.css").write_text(comp, encoding="utf-8")

# landing responsive
landing = (CSS / "landing.css").read_text(encoding="utf-8")
responsive = """
@media (min-width: 992px) {
    .sz-container { padding-left: 1.5rem; padding-right: 1.5rem; }
}
@media (min-width: 1200px) {
    .sz-container { max-width: 1140px; }
}
@media (min-width: 1400px) {
    .sz-container { max-width: 1280px; }
}
"""
if "@media (min-width: 992px)" not in landing or ".sz-container { padding-left" not in landing:
    landing += "\n" + responsive
(CSS / "landing.css").write_text(landing, encoding="utf-8")

print("Patched CSS modules.")
