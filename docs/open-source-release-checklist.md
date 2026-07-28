# Open-source release checklist

**Status:** Not ready to publish — section 1 is blocking.
**Created:** 2026-07-28

Work top to bottom. Section 1 must be finished *before* the repository is ever made
public or pushed to a public remote, because a public commit cannot be recalled: it is
mirrored, cached and scraped within minutes.

---

## 1. Blocking — data that must not be published

These are things I found actually committed in this repository today.

### 1.1 Real employees' names and personal email addresses

`database/seeders/EmployeeSeeder.php` contains **16 real people**, including personal
Gmail addresses, and their reporting lines. That is other people's personal data, and
publishing it is not the maintainer's call to make on their behalf.

Replace with obviously fictional data — `faker` or a fixed list of invented names on an
`@example.test` domain. The seeder's *purpose* (a realistic org tree with managers) is
worth keeping; the identities are not.

### 1.2 Company compensation figures

`database/seeders/EmployeeSettingSeeder.php` carries real allowance and deduction
amounts (petrol, device, meal deduction). Round numbers that reveal a pay structure.
Substitute arbitrary demo values.

### 1.3 Named company and default super admin

- `database/seeders/CompanySeeder.php` — the real registered company name and slug
  (`ERBIUMTECH (SMC-PRIVATE) LIMITED`). No NTN, so nothing regulated here.
- `database/seeders/DatabaseSeeder.php` — `admin@erbium.tech` with password
  `password`.

Neither is dangerous on its own, but a known default credential shipped in a public
repo *will* be tried against your production host. Use `admin@example.test`, and
generate a random password printed once at seed time.

### 1.4 Bank reference files of uncertain licence

- `docs/IMD CODES IBFT.xlsx`
- `docs/ipayments_csv (002).csv`

These came from SBP/1LINK/Standard Chartered material. Confirm you have the right to
redistribute them; if not, remove them and document the format instead. The bank
directory itself can be seeded from a list you compile.

### 1.5 Files that should never have been committed

- `public/.idea/*` — 6 PhpStorm project files, committed inside the **web root**.
  Remove from the index and add to `.gitignore`.
- `public/link.php` — gone from the working tree, but it **is in history** (commit
  `c326856 "update link"`). A stray PHP file in the web root is the shape of a
  webshell and reviewers will read it that way, so make sure the history rewrite in
  1.6 removes it rather than publishing it. Worth understanding why it existed.
- `.gitignore` additions: `/public/.idea`. Already covered and verified:
  `database/tenants/*.sqlite` (via `database/tenants/.gitignore`), `.env`, `/storage/*`.
- `.gitattributes` — add `/tests export-ignore` and `/docs export-ignore` **only if**
  you publish to Packagist as a dependency; for an application repo, leave them in.

### 1.6 Git history retains all of the above

Deleting these files in a new commit is **not enough** — every earlier commit still
contains them, and GitHub will happily serve the old blobs.

Two options:

| Option | When to choose it |
|---|---|
| **Fresh repository, squashed initial commit** (recommended) | Almost always. Scrub the working tree, `git init` a new repo, one "Initial public release" commit, push that. Simple, no chance of a missed blob. You keep the private repo with its full history. |
| `git filter-repo` to rewrite history | Only if the commit history itself has value worth publishing. Slower, easy to get wrong, and every collaborator must re-clone. |

Whichever you pick, run a secret scan on the result before pushing:

```bash
# any credentials left in the tree or history
gitleaks detect --source . --verbose
trufflehog filesystem . --results=verified
```

### 1.7 Rotate anything that was ever in a private repo

Treat every credential that has appeared in git — API keys, `APP_KEY`, DB passwords,
the Slack token in `config/services.php`'s env keys — as compromised on the day you
publish, and rotate it. `APP_KEY` matters especially here: it decrypts sessions and any
`encrypted` cast.

---

## 2. Files GitHub and contributors expect

| File | Notes |
|---|---|
| `LICENSE` | **A repo with no licence is not open source** — default copyright means nobody may use it. MIT is the Laravel-ecosystem norm and the README already claims it; add the file or change the README. |
| `README.md` | Done. Add screenshots or a short GIF — for an admin panel this is the single highest-value addition. |
| `CONTRIBUTING.md` | How to set up, run tests, code style (Pint), what a good PR looks like, and that domain changes need tests. |
| `CODE_OF_CONDUCT.md` | Contributor Covenant 2.1, verbatim, with a real contact address. |
| `SECURITY.md` | Private reporting route, what's in scope, response expectations. Must state the plain-text environment-password decision explicitly so it isn't reported as a novel finding. |
| `CHANGELOG.md` | Keep a Changelog format. Start at `0.1.0` — the schema is not stable. |
| `.github/ISSUE_TEMPLATE/bug_report.yml` | Ask for PHP/Laravel version, tenant vs landlord, and whether the queue/scheduler are running — those three explain most reports. |
| `.github/ISSUE_TEMPLATE/feature_request.yml` | Ask which country's rules it applies to. |
| `.github/PULL_REQUEST_TEMPLATE.md` | Checklist: tests pass, Pint clean, migration is tenant-path aware. |
| `.github/dependabot.yml` | Composer + npm + github-actions, weekly. |
| `.github/workflows/tests.yml` | Matrix on PHP 8.3/8.4: `composer install`, `php artisan test`, `pint --test`. |
| `.editorconfig`, `.gitattributes` | Both already present and sensible. `.gitattributes` already `export-ignore`s `/.github` and `CHANGELOG.md`. |

## 3. Repository settings

- **Description** — one line, plus the URL. Do not leave it empty; it's what search shows.
- **Topics** — `laravel`, `filament`, `php`, `accounting`, `payroll`, `double-entry`,
  `hrms`, `multi-tenant`, `pakistan`, `erp`.
- **Branch protection** on `main`: require the test workflow to pass, require one
  approving review, no force-push, no deletion. Set it before the first external PR.
- **Disable wiki and projects** unless you intend to use them — empty tabs read as
  abandonment.
- **Enable** issues, discussions (better than issues for "how do I…"), and **private
  vulnerability reporting** (Settings → Security).
- **Enable Dependabot alerts + secret scanning + push protection**. Push protection is
  the one that stops the next accidental key commit.
- **Add `FUNDING.yml`** only if you actually want it.
- **Tag a release** (`v0.1.0`) so people can pin something. An unreleased default branch
  discourages adoption.

## 4. Before announcing

- Deploy the public README's quick start on a clean machine and follow it literally.
  It is the only way to find the step you know by heart and forgot to write down.
- Decide and document what you will support. "Issues answered, no SLA, PRs reviewed
  when I can" is a fine and honest answer.
- Decide the tax/bank-file maintenance story: those need updating every Finance Act,
  and a stale tax table in a payroll tool is worse than no tool. Say so in the README
  if you cannot promise it.

## 5. Repository name

The README currently uses **Mizan** (میزان — *balance/scales*; a trial balance is
*mīzān-ul-ḥisāb*). Short, pronounceable, and it means the exact accounting concept the
app is built on.

| Name | For | Against |
|---|---|---|
| **`mizan`** ★ | Meaningful, short, memorable, ties to double-entry | Common word — check GitHub/Packagist/npm and trademark availability |
| `khata` | "ledger book"; instantly understood across South Asia | Also a common word; several existing fintech products use it |
| `roznamcha` | Literally the accounting daybook | Hard to spell or say outside Urdu/Hindi speakers |
| `hisaab` | "accounts/reckoning"; widely understood | Vaguer than *mizan* |
| `mizan-erp` / `mizan-hr` | Disambiguates a common word | Clunkier, and "ERP" oversells current scope |
| `laravel-payroll-pk` | Honest, searchable, self-describing | No identity; ages badly if it outgrows Pakistan |

**Recommendation:** `mizan`, with the description doing the explaining — *"Multi-tenant
HR, payroll and double-entry accounting for Pakistani businesses (Laravel + Filament)."*
Fall back to `mizan-hr` if the bare name is taken. Keep `MPR` out of the public name: it
means nothing to outsiders and collides with dozens of acronyms.

---

## Suggested order

1. Section 1, all of it. Then verify with a secret scan.
2. Fresh repo, squashed initial commit, still private.
3. Add section 2 files; get CI green.
4. Apply section 3 settings.
5. Flip to public, tag `v0.1.0`, then announce.
