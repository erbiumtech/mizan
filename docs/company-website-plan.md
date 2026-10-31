# Company website: plan

> **Decided 2026-10-30: the public site lives in this codebase; rewritten against Filament the same
> day.** `docs/saas-launch-plan.md` §12.4 asked where the marketing site belongs. The answer, given on
> 2026-10-30, is here — the signup door (§5) already is, and the pages around it exist to send people
> through it. The 2026-07-16 draft below the line was a brochure site for a software house, specified
> in Nova with a Material Design skin; Nova has been removed (`docs/filament-laravel13-migration-plan.md`)
> and the company no longer sells bespoke development through this product. What follows is the plan
> for the site the product actually needs. The old draft is kept at the end as "not now", so nobody
> re-specifies it.
>
> **Status: W1–W5 built 2026-10-30** — see the phase headings. Verified by `php -l` and Pint in a PHP 8.3
> sandbox; `WebsiteTest` runs in CI on 8.4.

## 1. What the site is for, in one sentence

A stranger with a Pakistani payroll to run reads what the product does and what it costs, and starts a
trial — the pricing page, the feature pages, a contact form, and the signup door that already exists.

Three things follow:

- **Everything on it is generated from what is sold.** The plans come from `config/plans.php`
  through `App\Support\Plans`; the feature pages come from each module's `module.php` manifest
  (`label`, `description`, `requires`) through `App\Support\Modules::registry()`. A plan renamed or a
  module added changes the site in the same commit, with no second copy to drift.
- **The little that is written by hand is written once, in the platform panel.** A tagline, a home
  page introduction, a contact address, a footer line, and any number of plain pages (terms, privacy,
  about). Landlord-side, tenant-less, edited by a super admin — the same posture as companies and
  users on that panel, and for the same reason: there is no company to belong to.
- **The data residency sentence is config, not prose.** `config/site.php` carries it: *Hosted in
  Pakistan, backed up in Pakistan.* (§12.5, decided 2026-10-30). It appears on the pricing page and the
  home page from the one place, so it cannot be true on one and stale on the other.

## 2. Shape

| Piece | Where | Why there |
|---|---|---|
| Public routes | `app/Modules/Core/routes/web.php`, beside signup | Core owns the signup door; the site is the door's porch |
| Controller | `App\Modules\Core\Http\Controllers\Site\SiteController` | one controller, one page per method, plain arrays to views like `CareersController` |
| Views | `resources/views/site/*`, layout `site/layout.blade.php` | its own layout: indexable, wider than the 28rem `layouts/public` the forms use, no Vite build so it renders on any host |
| Models | `SiteSetting`, `SitePage`, `ContactMessage` in `App\Modules\Core\Models`, **landlord** | tenant-less; `PlatformPanelIsLandlordOnlyTest` is the guard that would catch anything else |
| Editing | `Core/Filament/Platform/Pages/SiteSettings`, `Platform/Resources/SitePages`, `Platform/Resources/ContactMessages` | the platform panel is where the installation, not a company, is administered |
| Switch | `config('site.enabled')`, default on | off restores the old `/` → `/admin` redirect and 404s the site, for an installation that is not selling |

## 3. Phases

### W1 — Content foundation (built 2026-10-30)

- Landlord migration: `site_settings` (key/value), `site_pages` (slug, title, meta description,
  Markdown body, published flag, sort), `contact_messages` (name, email, company, message, ip,
  status, handled by).
- `SiteSetting::get()` / `::put()`, cached; `SitePage` with a `published()` scope and the Markdown
  rendered through `Str::markdown` at read time, never stored as HTML.
- Platform panel: a **Website** navigation group with the settings page and the pages resource.
  Super-admin only, through `canAccess()`, like every other platform resource.
- Public `/p/{slug}` renders a published page; a draft is 404, not 403 — nothing tells a visitor it exists.

### W2 — Pricing (built 2026-10-30)

`/pricing`, generated: every plan in the order `config/plans.php` lists them (cheapest first), its
label, description, per-employee monthly price in the chosen currency (`?currency=`, default PKR,
only the currencies the file prices), the minimum seats, the trial length and plan, the add-ons as
flat monthly prices with the plan they require, which plan allows a custom domain, and the residency
sentence. One button per plan: **Start a trial**, into `/signup`. There is no checkout — the trial is
the sale, and the plan a trial starts on is `config('plans.trial.plan')` (§12.1: Business).

### W3 — Features (built 2026-10-30)

`/features` lists every module a plan or add-on sells, grouped by the cheapest plan that includes it,
with its label and description from the manifest; `/features/{module}` is one module's page, saying
which plans include it and what it requires. Modules nobody sells (Core, and anything not in a plan)
are not pages. Nothing is written by hand.

### W4 — Contact (built 2026-10-30)

`/contact`: name, work email, company, message. The signup door's three rules apply unchanged: a
honeypot field, a silent per-address limit, and a throttled or honeypot submission answers with the
same "thank you" page as a real one. A real one is stored and `ContactMessageReceived` goes to every
active super admin by mail and database notification. The platform panel lists messages with a
**Mark handled** action; nothing on the public side can read them back.

### W5 — Home, SEO, the `/` route (built 2026-10-30)

- `/` is the home page: the tagline and introduction from settings (with defaults), three plan cards,
  the six most-sold modules, the residency sentence, and the trial call to action. With
  `site.enabled` off it is the old redirect to `/admin`.
- Each page carries its own `<title>`, meta description, canonical URL and Open Graph tags; the
  layout is `index, follow` where `layouts/public` is `noindex`.
- `/sitemap.xml` lists home, pricing, features, every feature page, every published page and contact.
  `/robots.txt` allows the site and disallows the panels and the signup verification link.

### W6 — Later, if asked

A *Load sample data* action on a trial (§12.2 decided empty by default), screenshots on the feature
pages, a changelog, a status page for the installation itself (the per-company one exists in
Projects). None is scheduled.

## 4. Decisions

- **Same codebase**, because the signup door is here and a trial is the whole funnel. A separate
  static site would need the plan list copied into it.
- **No Material Tailwind, no Livewire pages.** The forms are plain HTML posts like signup; the
  layout is a stylesheet in the Blade file like `layouts/public`. The site must render on a host where
  the Vite build has not run, which is how the status page and careers page were built too.
- **Markdown, not a rich-text editor,** for the hand-written pages — rendered at read time by
  `Str::markdown`, so what is stored is what was typed.
- **Super admins edit, nobody else.** A content-editor role is a platform-panel permission question
  for the day a non-admin needs it.

## 5. Not now — the 2026-07-16 draft

The original plan specified a software-house brochure: a team page (`team_members`), services
(`site_services`), a product showcase (`showcase_products`) with screenshot carousels, all content
edited through a Nova **Website** group, Material Design via `@material-tailwind/html`, Roboto and
Material Symbols, Livewire full-page components under `App\Livewire\Site`. None of it describes the
product being sold now, and the admin half named a tool that no longer exists. If the company ever
wants a brochure for services again, that draft is in git history at the commit before this rewrite
(`git log -- docs/company-website-plan.md`); the public-site mechanics built here (layout, settings,
pages, contact, sitemap) are the foundation it would stand on.
