# ScholarZim Frontend

React + TypeScript + Vite. Served by the Spring Boot backend under **`/app`**.

## Commands

```bash
npm install      # first time only
npm run dev      # dev server at http://localhost:5173/app
npm run build    # bundle into ../backend/src/main/resources/static/app/
npm run typecheck
```

`npm run dev` needs the backend running on port 8080 — Vite proxies `/api`,
`/login`, `/logout`, and the shared assets (`/css`, `/images`, `/icons`)
through to it, so sessions and CSRF behave exactly as they do in production.

## Layout

```
src/
├── pages/
│   ├── public/          home, scholarship list, scholarship detail
│   ├── applicant/       dashboard, profile, recommendations, saved
│   ├── applications/    my applications, wizard, confirmation, provider review
│   ├── provider/        provider dashboard
│   ├── opportunities/   list, create
│   ├── admin/           dashboard, search, analytics, reports, audit log, create user
│   ├── account/         security and notification preferences
│   ├── notifications/   notification centre
│   └── NotFoundPage.tsx
├── components/
│   ├── ui/              design-system primitives (barrel: '@/components/ui')
│   ├── layout/          SiteLayout, DashboardLayout, PageHeader, Section
│   ├── AsyncBoundary.tsx  loading / error / empty ladder for a useAsync result
│   ├── RequireRole.tsx  role guard; also usable as a layout route
│   ├── PendingEndpoint.tsx
│   ├── ScholarshipCard.tsx
│   └── LogoutButton.tsx
├── lib/
│   ├── api.ts       fetch wrapper — CSRF, 401 handling, endpoints
│   ├── session.tsx  /api/me provider + useSession
│   ├── types.ts     mirrors the backend DTOs
│   ├── format.ts    date and number formatting
│   └── useAsync.ts
└── styles/
    ├── index.css      imports the three below
    ├── base.css       reset, typography, page header, grid
    ├── layout.css     shells, header, sidebar, footer
    └── components.css one block per ui/ primitive
```

## Components

Pages compose primitives; they do not hand-roll markup or apply `sz-` classes
ad hoc. Every class in `components.css` has a matching component in `ui/`.

| Component | Use |
|-----------|-----|
| `Button` | Actions that stay on the page |
| `LinkButton` | Navigation **inside** the SPA (React Router) |
| `ExternalLinkButton` | Navigation that **leaves** the SPA (Thymeleaf pages, downloads) |
| `Card` / `CardTitle` / `CardMeta` / `CardFooter` | Card surfaces |
| `Badge` + `toneForStatus` | Status pills; tone is derived from the backend's status string in one place |
| `TextField` / `TextAreaField` / `SelectField` | Labelled form controls with hint and error wiring |
| `Table` | Generic data table; scrolls horizontally on its own |
| `Alert` / `EmptyState` / `ErrorState` / `Spinner` / `SkeletonGrid` | Feedback states |
| `StatTile` | Dashboard metric tiles |
| `Pagination` | Zero-based paging matching `PageResult` |
| `DescriptionList` | Label/value pairs on detail views |
| `PageHeader` / `Section` | The single `<h1>`, and titled blocks below it |

Two conventions worth knowing:

**`LinkButton` vs `ExternalLinkButton`** is a real distinction, not a style
choice. Routing a not-yet-ported path through React Router renders a 404
instead of the working server page, so anything still served by Thymeleaf must
use `ExternalLinkButton`.

**`AsyncBoundary`** renders the right thing for a `useAsync` result — skeletons,
error, empty, or content. Use it instead of writing the ladder by hand; the
empty case is the one that otherwise gets forgotten, leaving a page that looks
broken when it is merely empty.

Routes mirror the Thymeleaf URLs, offset by `/app` — `/app/applicant/saved` is
the counterpart of `/applicant/saved`. Porting a page is then just pointing the
old server route at the new one, with no URL redesign to reason about.

## Conventions

**Styling.** Brand colours, spacing, and radii come from the backend's shared
token sheet at `/css/tokens.css`, linked in `index.html`. Never redefine an
`--sz-*` variable here — that is what keeps the Thymeleaf and React pages from
drifting apart. Add only React-specific component CSS to `src/styles/`.

**Auth.** The SPA is same-origin with the API, so the existing session cookie
does the work: no tokens in `localStorage`, no CORS. `lib/api.ts` reads the
CSRF token from the `XSRF-TOKEN` cookie and sends it as `X-XSRF-TOKEN` on
writes, and redirects to the (still Thymeleaf) `/login` page on a 401/403.

**Routing.** `BrowserRouter` uses `basename="/app"`, matching Vite's `base` and
the backend's SPA fallback in `WebConfig`. Links to pages that have not been
ported yet must be plain `<a href>`, not router `<Link>`s — they leave the SPA.

**Data fetching.** `useAsync` is deliberately minimal. If pages start needing
caching, retries, or invalidation, replace it with TanStack Query rather than
growing it.

## Migration status

Every view exists and every route resolves, but **only five pages actually load
data** — the ones whose REST endpoints already exist:

| View | Endpoint |
|------|----------|
| Home | `/api/public/stats`, `/api/public/scholarships` |
| Scholarships | `/api/public/scholarships` |
| Scholarship detail | `/api/public/scholarships/{id}` |
| Recommendations | `/api/applicant/recommendations` |
| Saved scholarships | `/api/applicant/saved` |

The rest render a `<PendingEndpoint>` marker naming the endpoint their port
needs, and link to the Thymeleaf page still serving that feature. This is
deliberately visible: those pages are not finished and should not look
finished.

To port one: add the REST endpoint under `backend/.../api/`, replace the
`PendingEndpoint` with the real UI, then point the Thymeleaf route at `/app/…`.

**Auth is intentionally not ported.** Login, registration, and password reset
depend on Spring Security's form login, CSRF, and the role-based redirect
handler. Moving them is the riskiest step in the migration and should come last,
once everything behind them already works in React.
