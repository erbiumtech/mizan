# Company Website — implementation plan

A public marketing site for the software house (team, services, products,
about, contact) rendered with Blade + Tailwind 4 (already wired via Vite),
with **all content managed from Nova** the same way the accounting module is:
models + migrations + Nova resources + policies + permissions + seeders + tests.

Everything lives in this app — public routes sit alongside `/cpi` (Nova) and
`/reports`, so content editors use the login and roles they already have.

---

## Phase W1 — Content foundation (settings, pages, media)

1. **Migrations**
   - `site_settings` — single-row key/value store: `key` (unique), `value`
     (text), `type` (`text|textarea|image|json`). Holds company name, tagline,
     logo path, hero text, mission/vision (About), office address, phone,
     email, Google-Maps embed, social links (JSON), footer text, SEO defaults.
   - `site_pages` — CMS-lite for static pages: `slug` (unique), `title`,
     `meta_title`, `meta_description`, `hero_image`, `body` (rich text),
     `blocks` (JSON — ordered sections: heading/text/image/cta), `is_published`,
     `published_at`, `sort_order`.
2. **Models** — `SiteSetting` (with a cached `SiteSetting::get('key')` helper,
   cache flushed on save) and `SitePage`.
3. **Nova** — new **Website** group:
   - `SitePage` resource (slug auto-from-title, Trix/Markdown body, image
     upload to the `public` disk, publish toggle).
   - Settings as a Nova resource on `site_settings` (KeyValue-style editing,
     image fields for logo/hero) — one place to edit global content.
4. **Layout** — `resources/views/site/layout.blade.php`: header nav (Home,
   About, Services, Products, Team, Contact), footer with address/socials from
   settings; Tailwind 4; responsive; dark-mode-friendly. `@vite` assets.
5. **Permissions** — `WebsiteView`, `WebsiteManage` (create/update/delete all
   website content). Admin all; a future "Content Editor" role only needs
   these two. Managers/CEO get `WebsiteManage`; other roles read-only.
6. **Routes/Controllers** — `SiteController@home` (`/`) and
   `SiteController@page` (`/p/{slug}`) rendering published pages only; 404 on
   drafts. Existing `/` redirect to `/nova` moves to `/cpi` only when the site
   is disabled via a `site.enabled` config flag (default on).
7. **Seeder** — `SiteContentSeeder`: default settings (company name from
   `config('app.name')`, placeholder logo, socials) and the Home + About page
   rows. Idempotent via `key` / `slug`.
8. **Tests** — settings cache round-trip; published page renders; draft 404s;
   only `WebsiteManage` holders can edit (policy).

## Phase W2 — Teams

1. **Migration** `team_members` — `name`, `designation`, `bio`, `photo`,
   `email` (nullable, shown only if `show_email`), `linkedin_url`,
   `github_url`, `twitter_url`, nullable `employee_id` FK (optionally link to
   the HR record — photo/designation stay website-specific), `department`
   (nullable grouping: Leadership / Engineering / Design / Operations),
   `is_published`, `sort_order`.
2. **Model + Nova resource** — drag-to-reorder via `sort_order`, publish
   toggle, photo upload with thumbnail preview; policy behind `WebsiteManage`.
3. **Public page** — `/team`: grouped by department, ordered by `sort_order`,
   only published members; card grid with photo, name, designation, socials.
4. **Seeder** — pull active employees with a `Leadership/IT` designation into
   placeholder cards (published=false so nothing leaks until reviewed).
5. **Tests** — ordering respected; unpublished hidden; employee link optional.

## Phase W3 — Services

1. **Migration** `site_services` — `slug` (unique), `title`, `excerpt`
   (card text), `body` (rich text), `icon` (heroicon name or uploaded SVG),
   `image`, `is_featured` (home-page strip), `is_published`, `sort_order`.
   Typical rows: Web Development, Mobile Apps, Cloud & DevOps, UI/UX,
   Dedicated Teams, Maintenance & Support.
2. **Nova resource** under Website; featured toggle; policy `WebsiteManage`.
3. **Public pages** — `/services` (grid) and `/services/{slug}` (detail with
   body, related services, contact CTA). Featured services render on Home.
4. **Seeder + tests** — six default services; featured cap enforced (e.g. 4);
   draft hidden; detail 404s on unpublished.

## Phase W4 — Products (showcase)

Distinct from the accounting `products` table (inventory) — this is the
software-product portfolio, so table name `showcase_products`.

1. **Migration** `showcase_products` — `slug` (unique), `name`, `tagline`,
   `description` (rich text), `logo`, `screenshots` (JSON array of image
   paths), `tech_stack` (JSON tags), `website_url`, `app_store_url`,
   `play_store_url`, `category` (`saas|mobile|web|internal-tool`),
   `is_featured`, `is_published`, `sort_order`.
2. **Nova resource** — multi-image upload for screenshots (Nova `Repeater` or
   JSON field with `Image`s), tag-style tech stack, category filter/lens.
3. **Public pages** — `/products` (filterable grid by category) and
   `/products/{slug}` (detail: screenshots carousel, stack badges, store
   links, "Discuss a similar project" CTA to contact).
4. **Seeder + tests** — 3–4 sample products; same publish/404 rules.

## Phase W5 — Contact Us

1. **Migration** `contact_submissions` — `name`, `email`, `phone` (nullable),
   `company` (nullable), `subject`, `message`, `ip_address`, `status` enum
   (`new|read|replied|spam`), `handled_by` FK nullable, timestamps.
2. **Public page** — `/contact`: office info + map embed from settings, and
   the form (POST `/contact`) with validation, a honeypot field, and
   per-IP rate limiting (`throttle:5,10`); success flash message.
3. **Notification** — `ContactSubmissionReceived` (queued mail, same pattern
   as the payslip/change-request mails) to users holding a new
   `ContactInboxView` permission (Admin + Manager/CEO by default), linking to
   the submission in Nova.
4. **Nova resource** — read-only submissions inbox with status badge,
   Mark Read / Mark Replied / Mark Spam actions (each sets `handled_by`),
   sidebar badge showing the `new` count (same `withBadgeIf` pattern as
   change requests) — including the `runAction` policy method so the actions
   actually run for non-updatable records.
5. **Tests** — validation, honeypot drops silently, throttle kicks in,
   submission stored + staff notified, status actions.

## Phase W6 — Home page assembly, SEO & polish

1. **Home** (`/`) — hero (settings), featured services, featured products,
   team teaser, client-logo strip (settings JSON), contact CTA.
2. **SEO** — per-page meta title/description; OpenGraph tags; `/sitemap.xml`
   (published pages/services/products/team) and `robots.txt` route;
   canonical URLs.
3. **Performance** — full-page cache for public routes
   (`cache.headers` + response cache keyed on content `updated_at`), image
     sizes via `spatie/laravel-medialibrary` *only if needed* — start with
     plain `public` disk uploads to avoid a new dependency.
4. **Navigation from Nova** — "Website" menu section links + a "View Site"
   external link in the Nova main menu.
5. **Tests** — smoke test every public route (200, key content present);
   sitemap includes published-only entries.

---

## Build order & scope

**W1 → W2 → W3 → W4 → W5 → W6.** Roughly 6 migrations, 7 models, 8 Nova
resources/actions, 1 public controller per section, one Blade layout + ~10
views, 2 permissions + 1 notification, seeders and a feature-test file per
phase. No new composer packages required for the base build (Trix/images ship
with Nova; Tailwind 4 is already configured).

## Decisions taken (flag if you disagree)

- **Same Laravel app**, not a separate repo/CMS — editors reuse Nova, auth,
  roles, and the audit log; deployment stays one artifact.
- **`showcase_products`** table name to avoid colliding with the inventory
  `products` table from accounting Phase 19.
- **Blade + Tailwind server-rendered pages** (no Vue/React SPA) — fastest to
  ship, SEO-friendly, and consistent with the existing reports pages.
- Content is **publish-flag driven** (draft/published) rather than full
  versioning/scheduling; can be added later if editors need it.

## Open questions

1. Should the site live at `/` of this same domain, or a separate domain
   pointing at the same app (route by host)? Plan assumes `/` here.
2. Is there brand material (logo, colors, copy) to seed, or should seeders
   ship neutral placeholders? Plan assumes placeholders.
3. Do you want a dedicated "Content Editor" role now, or is Manager/CEO/Admin
   enough for the first release? Plan assumes the latter.
