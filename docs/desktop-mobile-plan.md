# Desktop and mobile clients: the plan

> Written 2026-09-27 from a code inventory, not aspiration. Nothing below is built
> unless marked so. Phase 0's questions gate everything after them.

## 0. What is true today, and the one fact that reorders everything

The codebase is a server-rendered Filament panel with a deliberately small mobile API
bolted to its side. The API is real and consistent where it exists:

- **15 endpoints across five modules** — payslips (`/api/my-payslips`, per-payslip PDF,
  comments), leave (`/api/my-leave-balances`, `/api/my-leave-requests`), MPRs
  (`/api/my-mprs`, comparison, show), profile (`/api/my-profile`), accounting
  (`/api/reports/trial-balance`, `profit-and-loss`, `accounts` resource + tree). Every
  one carries the same stack: `auth:sanctum` + `api.company` (X-Company tenant
  resolution, `ApiCompanyResolutionTest`) + `module:*` licence gating.
- **Files for API clients work**: `/files/{companyId}/{path}` accepts a Sanctum bearer
  (`auth:web,sanctum`, `TenantFileController`) precisely so the `pdf_url` values the API
  returns open on a phone. WhatsApp media uses the hand-rolled path-segment HMAC
  (`PayslipMediaLink`) because Twilio mangles query strings.
- **Realtime exists, push does not**: 17 notification classes broadcast over Reverb on
  one private user channel; there is zero FCM/APNs/device-token code anywhere.
- **A mobile client already ships.** The repo does not contain it, but the code is
  written for it: `routes/api.php` warns "check the shipped builds before revoking a
  licence in production", `MprController` returns absolute URLs "because a mobile client
  resolves nothing relative". Someone is already holding shipped builds this API can
  break.

That last fact reorders the plan. A "future mobile app" plan could defer API hygiene;
an API with shipped consumers cannot:

- `POST /api/login` is **unthrottled** — free credential-stuffing against a public
  codebase whose login route everyone can read.
- Tokens **never expire** (`sanctum.expiration = null`), carry **`["*"]` abilities**,
  are all named `'API Token'`, and there is **no logout/revoke endpoint**. A phone lost
  in a rickshaw is a permanent credential.
- `login` returns the **unfiltered user model**, and the response envelopes disagree
  (`{success, message, ...}` hand-rolled in five controllers, bare `{data}` from the one
  `JsonResource`; `MprController` answers 400 where 404 is meant).
- **No versioning** of any kind, with external builds already in the field.

So Phase A1 is not preparation for a client — it is overdue maintenance of one.

## 1. Decisions (the shape, argued once)

**D1 — The mobile app is a companion, not the ERP.** Employee and manager self-service:
payslips, leave, approvals, notifications, profile. The panel remains the only surface
for accounting, payroll runs, configuration — rewriting a 5,000-test ERP natively is the
project this plan exists to refuse. `docs/construction-management-plan.md` already
refused a native field app on the same grounds.

**D2 — PWA first, native shell only when a named need appears.** The candid comparison:

| Route | For | Against |
|---|---|---|
| **PWA on the existing panel** ★ | Zero new codebase; self-service pages already exist as Filament pages; installable on Android/iOS/Windows/macOS; web push covers Android + desktop natively and iOS 16.4+ when installed | iOS push only after home-screen install; no store presence; no biometric unlock |
| Capacitor wrapping the PWA | Store presence, FCM/APNs push, biometrics — while keeping the one codebase | Store accounts + review cycles; a webview app must still feel like an app |
| Flutter / React Native | Real native feel, offline primitives | A second full codebase in a language the team does not write, against an API of 15 endpoints — the tail wags the dog |
| NativePHP / Electron desktop | — | The app is multi-tenant server-side; a local runtime contradicts the architecture. Refused. |

PWA first because the marginal cost is a manifest, a service worker, and mobile polish
on pages that already render — and because it is the same artifact desktop installs
(Chrome/Edge "install app"). Capacitor is the sanctioned upgrade path and reuses
everything; Flutter is the path only if the companion someday outgrows a webview, which
is a decision for that day.

**D3 — Desktop is the PWA.** The panel already is the desktop app; installability makes
it feel like one (dock icon, own window, notifications). A Tauri wrapper is gated
behind a concrete OS-level need — tray presence, kiosk lockdown for the retail till
(`docs/retail-stores-pos-plan.md` §5 owns that decision), auto-start. None exists today.

**D4 — Version the API before touching its shapes.** `/api/v1/...` aliases added while
every existing unprefixed route keeps answering, deprecated, until the shipped builds
are confirmed migrated (the same courtesy `docs/modules-plan.md` §risks demanded for the
403 change). New endpoints land under `/v1` only.

## 2. Phase 0 — questions that gate the rest

1. **Where is the shipped mobile client?** Which repo, which framework, who maintains
   it, what does it call today? Everything in the M-series either inherits it or
   supersedes it, and nobody should build a companion app twice. *(Gates M1.)*
2. **Scope of the companion**: employee-only (payslips/leave/notifications), or
   manager approvals too? *(Gates A4/M3.)*
3. **Store presence required?** If the answer is yes on iOS, Capacitor + an Apple
   developer account enter at M4; if no, the PWA route runs the whole distance.
   *(Gates M4.)*
4. **Push provider**: Web Push (VAPID, no vendor account, works for PWA) vs FCM
   (needed the moment Capacitor/native enters). Building the device-token table and the
   notification seam identically for both is cheap; choosing the first transport is not
   reversible-free. *(Gates A3.)*

## 3. A-series: API hardening (build first, client-agnostic)

**A1 — Auth lifecycle.** Throttle `POST /api/login` (`RateLimiter`, per-email+IP, the
`LeadCaptureController` pattern already in the repo); token expiry via
`sanctum.expiration` plus a refresh flow; `createToken($deviceName, $abilities)` with a
required `device_name` in the login payload; `POST /api/logout` (revoke current),
`GET/DELETE /api/tokens` (list/revoke devices); the login response slimmed to a
deliberate resource, not `$user` raw. One test file pinning each behavior.

**A2 — One envelope, one error grammar, `/v1`.** A thin `ApiResponse` support class (or
plain `JsonResource`s — `AccountResource` is the precedent) adopted by the five
hand-rolled controllers; `MprController`'s 400→404 corrected **under `/v1` only** (D4);
module-gate 403 and tenant-resolution 422 documented in one place. Publish a static
OpenAPI file in `docs/` — 15 endpoints is a morning, and the shipped-client author needs
it more than we do.

**A3 — Push.** `device_tokens` table (landlord — devices belong to users), registration
endpoint, and one seam: the existing `Broadcasting::channels()` (built for the Slack
gate) grows push the same way — every one of the 17 `toBroadcast` classes inherits it
for free, payload identical to the database/broadcast shape. Transport per Phase 0 Q4;
the seam does not care.

**A4 — Companion endpoints, scope per Phase 0 Q2.** Likely: notifications
(list/mark-read — the database notifications already exist), leave approvals for
managers (`LeaveRequestService::decide()` is the seam), expense claim approvals.
Only what the companion's screens actually draw.

## 4. M-series: the companion app

**M1 — PWA-ify the panel.** Manifest (name/icons from the per-company branding that
`CompanyLetterhead` now serves — the tenant-aware brand landed 2026-09-27), a service
worker that caches the app shell and answers offline with a "you're offline" page (not
offline data), installability verified on Android/iOS/desktop Chrome. Mobile polish
pass over the self-service pages only: payslip list/view, leave apply/balances,
notifications. Filament is responsive; polish means thumb-sized, not redesigned.

**M2 — Push wired end to end.** A3's transport delivering the 17 notifications to an
installed PWA (and/or the shipped native client through FCM); notification tap opens
the right screen.

**M3 — Manager approvals** on mobile (per Phase 0 Q2): approve/return leave and expense
claims from the notification. This is the feature that makes managers install it.

**M4 — Native shell, gated.** Capacitor around the same PWA if Phase 0 Q3 demands store
presence/biometrics/FCM-only push. Explicitly not scheduled until the PWA has users
asking for what it cannot do.

## 5. D-series: desktop

**D1 — ships with M1.** The PWA manifest makes the panel installable on Windows/macOS;
web push (A3) gives desktop notifications. Nothing else to build.

**D2 — Tauri wrapper, gated** on a named OS-level need. The first candidate on record
is the retail till (kiosk mode, cash-drawer/printer peripherals), which belongs to the
retail plan's own phases, not this document.

## 6. Not doing, so nobody re-litigates it

- **Native rewrite of the ERP** — the panel is the product; the companion is a satellite.
- **NativePHP/Electron** — a local Laravel runtime against database-per-tenant hosting
  is an architecture contradiction, not an option.
- **Offline-first sync** — no workflow here needs it; the two that might (construction
  field photos, retail till) are owned by their own plans with their own gates.
- **An API for every panel feature** — endpoints follow companion screens, never
  speculation. YAGNI is why the current 15 are all real.

## 7. Order of work

| Step | What | Gate |
|---|---|---|
| 0 | Phase 0 answers (esp. locate the shipped client) | — |
| 1 | A1 auth lifecycle + login throttle | none — overdue regardless |
| 2 | A2 envelope + `/v1` + OpenAPI | A1 |
| 3 | M1 PWA + mobile polish | Phase 0 Q1/Q2 |
| 4 | A3 push seam + M2 delivery | Phase 0 Q4 |
| 5 | A4 + M3 approvals | Phase 0 Q2 |
| 6 | M4 Capacitor / D2 Tauri | a named need, in writing |

A1 is the only step with no gate: an unthrottled login issuing immortal all-ability
tokens for a public codebase does not wait for a mobile strategy.
