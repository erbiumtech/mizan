# Project management: plan

> **Built — all ten phases, 2026-10-30, one commit per phase.** Written the same day from a code
> inventory and a reading of what Jira, Linear and GitHub Projects ship. Mechanics common to any new
> module — the manifest, permissions, role grants, navigation, option lists, tenant migrations,
> the guard tests — are in `docs/new-module-checklist.md` and are not repeated. This document
> decides *what a work item is, how a board moves it, how a repository talks to it, and in what
> order that got built*. Where the build departed from the text below, the commit for that phase
> says so; the departures worth knowing: burndown and velocity live on the sprint page rather than
> in the report catalogue (Phase 3); cumulative flow is per project, not per board (Phase 4); the
> inbound hook URL carries the company slug (Phase 7); identity claiming is on the connection's
> page, not the user profile (Phase 7); Bitbucket and GitLab authenticate by token, with the OAuth
> round-trips deferred (Phase 8); Jira statuses map by name rather than through a form (Phase 9).
> Phases 4–10 were written without running the suite (the sandbox lacked PHP 8.4) and first ran
> in CI on their pull request.
>
> **Scoped on the owner's instruction, the same day:** a complete module, to modern standards,
> tasks managed Kanban-style, with GitHub, Bitbucket and other tools integrated. "Complete" is
> read as *what a software or services team needs to run a project end to end without opening
> another product*, and §17 says what is left out of that and why.
>
> **Amended the same day, on the owner's instruction: Clockify.** Time on a work item may be
> logged here (§9) or in Clockify (§8.8), and a company says which. The design rule that
> settles every question the pair raises: Clockify is the stopwatch, the timesheet is the
> ledger, and an hour exists in the ledger exactly once.

**Created:** 2026-10-30
**Covers:** what exists and what it means for this (§1), the shape and the name (§2), the work
item (§3), workflow and status (§4), boards (§5), planning — backlog, sprints, epics, releases
(§6), the project page (§7), integrations — GitHub, Bitbucket, GitLab, Clockify, Slack,
imports (§8), time and cost (§9), who hears what (§10), the API (§11), AI assists (§12), permissions,
licensing and the module graph (§13), phases (§14), tests (§15), open decisions (§16), what
not to build (§17).

Three findings shape everything below.

1. **The repo already has most of the plumbing and none of the product.** Polymorphic comments
   with ownership, custom fields on any model, company-editable option lists, the activity log,
   outgoing webhooks with a registry, a versioned API with abilities, an optional socket, a
   structured-output AI client, a hierarchy-aware audience model, and a Projects module with
   members, managers and timesheets keyed on it. What does not exist is a single row that means
   "a piece of work", or any screen that moves one. §3–§7 are that product; §8 is what turns
   the plumbing outward.
2. **Three things here are called tasks and none of them is this.** Performance's recurring
   tasks are chores with penalties; Lifecycle's checklists are onboarding steps; Construction's
   punch lists are site defects. All three are right as they are and none converges with a work
   item (§2.2). The new thing needs a name that does not collide, and the one Atlassian itself
   moved to in 2025 — *work item* — happens to be the honest one.
3. **The board is the product and the board is a query.** A Kanban column is a status; a
   swimlane is a group-by; a card's position is a rank. If the model is right, the board is a
   view over it and every other view — backlog, sprint, timeline, my work — is the same query
   with a different `where`. The model gets built first and the board second, in that order,
   because a board built first grows a model shaped like its columns.

---

## 1. What exists, and what it means for this

| Already here | Where | What this plan does with it |
|---|---|---|
| Projects: code, name, status, two managers, dates, members with from/to dates | `app/Modules/Projects` | The container. A work item belongs to a project; nothing about `projects` changes except where it sits in the navigation (§2.3). |
| Environments, health checks, incidents, certificate expiry, the public status page | `app/Modules/Projects` | Untouched. It is operations, not planning; it stays in the same module and the project page gains tabs beside it (§7). |
| Timesheet entries keyed on `project_id`; `BillableTime` and `LabourCost` contracts | `app/Modules/Timesheets`, `app/Support/Contracts` | Time logged against a work item is a timesheet entry with one more column (§9). Cost and billing follow the contracts that already exist. |
| Comments, polymorphic, with `isOwnedBy()` for the policy | `app/Modules/Core/Models/Comment` | The conversation on a work item. The relation manager Payslips use is the shape to copy. |
| Custom fields on any model, with form, infolist and table contributions | `app/Filament/Support/CustomFieldsSchema` | A work item is a custom-field subject from day one. "Add a field to the bug form" is configuration, not a phase. |
| Option lists a company edits under Settings | `app/Support/OptionLists` | Item types, priorities and labels are option lists, so adding "Spike" or "P0" is a dropdown edit. |
| Activity log on every model | spatie/laravel-activitylog | The history tab. Nothing is written for it. |
| Outgoing webhooks: a registry of events with named payloads, a delivery job with retries | `app/Support/Webhooks`, `Core/Jobs/DeliverWebhook` | Work-item events are registered there (§10.4), so any tool can subscribe to "item moved to Done" the way one already can to "employee joined". |
| A versioned API with per-token abilities | `app/Support/ApiRoutes`, `app/Support/ApiAbilities` | §11 mounts a `work` ability and the endpoints a mobile board needs. |
| An optional socket, and the rule that nothing fails without one | `app/Support/Broadcasting` | Live board updates when Reverb is configured; polling when it is not (§5.6). |
| A structured-output AI client, optional | `app/Support/Ai/StructuredModel` | §12, and only §12. |
| Who hears that a record changed, from the columns that name people | `app/Support/RecordAudience` | A work item declares `changeAudience()` — assignee, reporter, watchers — and the bell works without a line of notification code. |
| A hierarchy: who may see whose records | `app/Support/EmployeeAccess` | A private project's items are visible to its members; a public one's to everybody with the permission (§13.2). |
| Relation managers one module contributes to another's resource, without the import | `app/Support/ResourceContributions` | How the Employee page gets a "My work" tab and the Ticket page gets "Linked work items" with no module naming this one. |
| Tickets with categories and SLA clocks | `app/Modules/Support` | A ticket can spawn a work item and link to it (§3.6). The two are not merged: a ticket is somebody asking, an item is somebody doing. |
| Recurring tasks with penalties; onboarding checklists; punch lists | Performance, Lifecycle, Construction | Left alone. §2.2. |
| Drag-and-drop, already in the bundle | `vendor/filament/support/resources/js/sortable.js` | The board's columns and the backlog's ranking use what Filament already ships for reordering; nothing is added to `package.json`. |

What does **not** exist: a work item, a status that belongs to a workflow, a board, a sprint, an
epic, a rank, a repository connection, an inbound webhook endpoint, any mapping from a git
identity to a user. That is the whole of §3–§8.

---

## 2. The shape, and the name

### 2.1 One module, `work`

Key `work`, label **Work**, requires `employees` and `projects`. Not folded into `projects`:
that module is operations (uptime, certificates, a status page) and has a different buyer in the
same company — the person who runs servers is rarely the person who runs sprints. A company that
monitors its environments and plans its work in Trello licenses one and not the other, and the
module boundary is what makes that a flag rather than a fork.

### 2.2 The thing is a *work item*, and the type says what kind

The table is `work_items`, the model `WorkItem`, and the system noun on screen is **work item** —
never "task" or "issue" as the generic. The **type** is what a user actually reads: a *Bug*, a
*Story*, a *Task*, an *Epic*, a *Subtask*. This is how Jira has always worked underneath and how
Atlassian now names it on the surface too, and it is the only naming that survives contact with
this repo, where *task* is already taken three times over by three different things:

- Performance's **recurring tasks** are standing obligations with a penalty for missing them —
  "submit the daily report by five". They have no project, no board, no estimate and no done
  state that stays done; they roll over every day. They stay exactly as they are.
- Lifecycle's **checklists** are the steps of joining and leaving. They belong to a person's
  lifecycle, not a project, and they are complete when the person is through the door.
- Construction's **punch lists** are defects on a site against a contract. They have a location,
  a trade and a certificate they hold up.

A fourth "task" beside those would be the one nobody could find in a search. A work item is a
different word for a different thing, and it is the word the market has settled on.

The key is per project: `WRK-12`, where `WRK` is the project's **key** — three to six capital
letters a project is given once (§3.1) — and `12` is a per-project sequence that never reuses a
number. A key is what somebody types into a commit message, a chat, a branch name; it has to
be short, stable and unmistakably a work item.

### 2.3 A domain on the rail: **Work**

The navigation is three levels (`NavigationDomains` → group → `NavigationTree` branch) and its
rail carries seven domains. This adds an eighth, **Work**, holding both `projects` and `work`:
Projects, then Boards, Backlog, Sprints, Releases, Work items, and the Settings branch's
Workflows.

Projects currently sits under People → Employee → Projects, which was never where it belonged —
it landed there because employees are assigned to projects. A team that lives on a board all day
needs the board one click from anywhere, which is what the rail is for; and a rail entry for a
module a company has not licensed is simply absent, the way Construction's is.

The alternative — a branch under People — was rejected because the People domain is employment,
and a Kanban board is not an HR screen. This is the one navigation decision here with a cost
(the rail's eighth icon, and `NavigationDomainsTest`'s exact list), and it is the owner's to
reverse.

---

## 3. The work item

### 3.1 The project gains a key

`projects` gets `key` (string 6, unique, uppercase) and `next_item_number` (unsigned int,
default 1). The key is set once, on the project, and refused afterwards: every commit message
and every branch name that ever referenced an item would break. Existing projects are keyed on
first use from their `code` — `PRJ-ERP-01` becomes `ERP` — with the edit form offering the
override before the first item exists.

The sequence is taken under a row lock on the project (`lockForUpdate`), because two people
creating items at once must not both get `WRK-12`; a unique index on `(project_id, number)`
makes the failure loud rather than silent if the lock is ever bypassed.

### 3.2 The columns

| Column | Why it is there |
|---|---|
| `project_id`, `number`, `key` (denormalised, indexed) | The identity. `key` is stored, not computed, because it is searched, sorted and joined against from commit messages. |
| `type` | An option list `work.type` seeded Epic, Story, Task, Bug, Subtask. Each has an icon and a colour the company may change. Epic and Subtask carry hierarchy meaning (§3.4); the rest are labels. |
| `title`, `description` | Description is Markdown, in Filament's own `MarkdownEditor` — shipped with the framework, not yet used here (MPR uses the rich editor) — and rendered through the same path the help panel renders its Markdown. Not a rich editor, deliberately: a description is pasted into pull requests and chat, and Markdown is what survives the trip. |
| `status_id` | A row in `work_statuses` (§4). Never a free string. |
| `priority` | Option list `work.priority`, seeded Highest, High, Medium, Low, Lowest. |
| `assignee_employee_id` | One assignee, nullable. Not many: a card with three faces on it is a card nobody owns, and the two products that allow it both warn against it. Pairing is a comment. |
| `reporter_user_id` | A landlord user id, set on create, read by `RecordAudience`. Who asked. |
| `parent_id` | Self-reference: a Subtask's Story, a Story's Epic. One level each way, enforced in §3.4. |
| `sprint_id` | Nullable; §6.2. |
| `release_id` | Nullable; the version it ships in; §6.4. |
| `estimate_points`, `estimate_minutes` | Both nullable, and a company setting says which is shown (§16.3). Points are a number with one decimal; minutes are what the timesheet already measures. |
| `due_on` | A date. Optional on everything; the board shows it red when past. |
| `rank` | A string — LexoRank-style base-36 between-two-neighbours ordering — so moving a card writes one row, not every row below it. Indexed per `(project_id, rank)`. |
| `started_at`, `resolved_at` | Written by the status engine when an item first enters an *in progress* category and when it enters *done* (§4.2). They are what cycle time and lead time are computed from, and they are never edited by hand. |
| `resolution` | Nullable; option list `work.resolution` seeded Done, Won't do, Duplicate, Cannot reproduce. Required when entering *done* if the workflow says so. |
| `archived_at` | Soft archive. Items are never deleted while anything references them; archive hides them from every board and keeps every link. |

Labels are many-to-many through `work_item_labels` against `work_labels` (name, colour, per
project or company-wide). Watchers are `work_item_watchers` (user ids). Both are tables rather
than JSON columns because both are filtered on.

Custom fields come from `CustomFieldsSchema` with no work here beyond registering `WorkItem` as
a subject; the bug form's "browser" and "environment" fields are the company's to add.

### 3.3 Attachments

`work_attachments`: item, user, original name, stored path on the tenant disk, size, mime. Uploaded
on the item page and dragged into a comment. No media library is added — the repo has none, and
one table with the four columns a file needs is less than the package's four tables. Inline
images in Markdown reference the attachment by id and are served through the tenant-file access
that already gates every other upload (`TenantFileAccess`), never by a public URL.

### 3.4 Hierarchy, one level each way

Epic → Story/Task/Bug → Subtask. A Subtask's parent must not be a Subtask; an Epic's parent must
be nothing. A model rule, not a form hint, because the API can set `parent_id` too. Three levels
is what Jira ships by default and what every team that was asked actually uses; the fourth level
is a Jira Premium feature whose main use is making roadmaps for people who do not open the tool.

An Epic's progress is computed — done children over all children, by count and by points — and
shown on its card and in §6.3. Subtasks are shown under their parent on the board and never as
their own card unless the board asks for it (§5.4).

### 3.5 Links

`work_item_links`: `from_id`, `to_id`, `kind` in *blocks*, *is blocked by*, *relates to*,
*duplicates*, *is duplicated by*. Stored once with the forward kind; the reverse is read, not
written, so a link cannot drift into saying two things. A blocked item shows it on the card.

### 3.6 Links out, and the ticket

A work item may carry `external_links` — a small table of (label, URL, kind) for the Figma file,
the Sentry issue, the spec. Plain links, deliberately: an API integration per design tool is a
maintenance contract nobody here signed, and a URL is what the person wanted to keep anyway.

A support ticket spawns a work item from a header action — "Make a work item" — which copies the
title and body, links the two with a `work_ticket_links` row, and shows the item's status on
the ticket. Through `ResourceContributions`, so Support never learns that Work exists. The two
are not merged (§1): a ticket closes when the customer is answered, an item closes when the
code ships, and the day those are the same day is rare enough to be a link rather than a model.

---

## 4. Workflow and status

### 4.1 Statuses belong to a workflow, and a workflow to a project

`work_workflows` (name, is_default); `work_statuses` (workflow, name, **category**, sort,
colour). A project points at one workflow; a company ships with one — *To do → In progress →
In review → Done* — and may make more. Changing a project's workflow maps every status of the
old one onto a status of the new one before it is allowed, in a form that lists them, because an
item with a status from a workflow its project no longer uses is an item on no board.

### 4.2 Three categories, which is the whole engine

Every status is in exactly one **category**: `todo`, `in_progress`, `done`. The category is
what the application reasons about; the status name is what the team reads. *In review* and
*In QA* are both `in_progress`; *Done* and *Won't do* are both `done`. This is Jira's status
category, and it is why a burndown, a cycle time, a "what is still open" filter and a Kanban
column mapping all work for every workflow a company invents without a line of configuration.

Entering the first `in_progress` status stamps `started_at` if it is empty. Entering a `done`
status stamps `resolved_at` and, if the workflow says so, requires a resolution. Leaving `done`
clears `resolved_at`. All of it in one `WorkItem::moveTo(Status)` method, which is the only
thing allowed to write `status_id`.

### 4.3 Transitions: any to any, unless told

By default every status may move to every other. A workflow may add **rules**, each of which is a
row rather than a script: *from X only to Y, Z*; *entering Done requires a resolution*; *entering
In review requires an assignee*; *only the assignee or a manager may move out of In progress*.
Four rule kinds, in a table, evaluated in `moveTo()`. That is the ceiling (§17.1): Jira's
conditions, validators and post-functions are a language, and a language is where every workflow
tool goes to become unmaintainable. The rule builder in `docs/saas-launch-plan.md` §8.8 is the
place to grow this if a fifth kind is ever needed, and it is a form over a registry for the
same reason.

---

## 5. Boards

### 5.1 A board is a saved query with columns

`work_boards`: project (nullable — a cross-project board is allowed, §5.5), name, **kind**
(`kanban` or `scrum`), filter (JSON: the same shape the list's filters produce), column mapping
(JSON: each column names the statuses it shows), swimlane (`none`, `assignee`, `epic`,
`priority`), WIP limits per column, and whether subtasks appear as cards. A project gets one
Kanban board on creation, mapped one column per status; everything else is an edit.

A **Kanban** board shows every open item matching its filter. A **Scrum** board shows the active
sprint's items and has a backlog view beside it (§6). The difference is the `where`, not the
screen.

**Board settings — built 2026-10-31** (`WorkBoardSettingsTest`). Phase 2 shipped the board with
only its first-view mapping and no screen to edit it, and a project form with no way to choose its
workflow. Now: a **Board settings** action on the board (`BoardManage`) edits name, kind, lanes,
subtasks-as-cards and the columns — label, statuses, an optional card limit, reorderable, the limit
travelling with its column. A save is refused, with the reason, for a column without a status, a
status in two columns, or a limit that is not a whole number of at least one; a status in no column
is allowed and named above the board as before. **Reset columns from workflow** goes back to one
column per status. A project may have several boards (**New board**, **Delete this board** — never
the last), chosen by a second picker carried in the address as `board`; the first stays the
default, and `GET /work/projects/{key}/board` takes `?board=` the same way. The project form
gains **Workflow**, contributed by Work through `ResourceContributions::addFormComponent()` so
Projects still names nothing of this module's. The change rule is the strict one, in
`BoardSettings`: a project changes workflow only while none of its items is in a status the new
workflow lacks — in practice, before it has items — with nothing remapped silently; a model guard
refuses the same change made any other way, and when it goes through every board of the project is
redrawn from the new workflow with its limits cleared.

### 5.2 The columns are statuses, mapped

A column shows the items whose status it lists. Two statuses may share a column (*In review*
and *In QA* under "Review"); a status in no column is hidden from that board and said so in the
board's settings and above the board, because a status that silently swallows cards is the first thing a team
blames the tool for. Dropping a card into a column with several statuses moves it to the first;
the card's menu offers the others.

### 5.3 Drag, drop, rank

Dragging between columns calls `moveTo()`; dragging within one rewrites `rank` between its two
new neighbours. Both are one Livewire call carrying the item id, the target status and the ids
of the cards above and below, and both are refused — with the card snapping back and the reason
shown — when a transition rule (§4.3) or the WIP limit says no. The WIP limit is a limit, not a
warning: over it, the column refuses the drop and reads red. A team that wants a soft limit sets
it one higher.

The drag itself is Filament's own sortable plugin, already in the bundle for repeaters and
reorderable tables; the board's columns are a sortable group with one handle per card. Nothing
new is installed.

### 5.4 The card

Key, type icon, title, assignee (initials, in the brand colour), priority, estimate, labels,
due date (red when past), a blocked marker, an epic tag, and a count of subtasks done over total.
Clicking opens the item in a slide-over, not a page: the board stays where it was. The item's
own page exists for the long read and for a link in a commit message.

Quick filters sit above the columns — *mine*, *unassigned*, *blocked*, *due this week*, each
label, each type — and compose with the board's saved filter. They are URL state, so a filtered
board is a link somebody can send.

### 5.5 "My work", across projects

One board every user has without making it: every open item assigned to them, across every
project they may see, columns by category (To do / In progress / Done this week), swimlanes by
project. It is the dashboard widget's full-size twin, and the first screen most people open.

### 5.6 Live

With a socket configured (`Broadcasting::isConfigured()`), a card moved by somebody else moves on
every open board within the second, over a private channel per board. Without one, the board
polls every thirty seconds and says so in its footer. The rule the rest of the app follows
holds: nothing is queued for a broadcaster that is not there.

---

## 6. Planning

### 6.1 The backlog

A ranked list of every open item in the project not in an active or future sprint, ordered by
`rank`, with the same drag-to-reorder as the board. Multi-select and *move to sprint*, *set
epic*, *set release*, *set label* as bulk actions. Inline create at the bottom — a title and a
type, nothing else, because a backlog is written at speed and refined later. An item's full
form is a click away and never in the way.

### 6.2 Sprints

`work_sprints`: project, name, goal, `starts_on`, `ends_on`, `state` in *future*, *active*,
*closed*, `started_at`, `closed_at`. One active sprint per project. Starting one requires dates
and refuses while another is active; completing one asks where the unfinished items go — the
next sprint or the backlog — and moves them, recording on each item that it carried over, which
is what a velocity chart needs to be honest about.

Velocity is computed, never stored: points (or hours) of items that reached `done` while the
sprint was active, per closed sprint, shown over the last eight.

### 6.3 Epics

An Epic is a work item with children (§3.4). The Epics view lists them with progress bars, their
open and done children, and — when both an Epic and its children carry dates — a horizontal bar
per Epic on a timeline (§6.5). An Epic is `done` when the company says so, not when its last
child is: a team that wants auto-close sets a workflow rule for it (§4.3's fourth kind).

### 6.4 Releases

`work_releases`: project, name, `planned_on`, `released_on`, description. An item's `release_id`
is its *fix version*. Releasing one stamps the date, moves nothing, and offers the **release
notes** as Markdown — one line per done item, grouped by type — which is the text that goes into
the GitHub release (§8.6) and the customer email. A release with open items warns before it is
marked released and lets you move them to the next.

### 6.5 Timeline

Epics and releases as bars on a month-scale timeline, items as ticks beneath their Epic when
dated. Read-only in phase 10 (§14): dragging a bar to change a date is a nicety on top of a date
field. No dependencies between bars, no critical path (§17.3).

---

## 7. The project page

`ViewProject` exists and shows environments and health. It gains tabs, each a relation manager or
a page in its own right, and each absent when the module that owns it is not licensed:

**Overview** (the existing page) · **Board** · **Backlog** · **Sprints** · **Epics** ·
**Releases** · **Timeline** · **Reports** · **Members** (the existing relation manager) ·
**Repositories** (§8) · **Settings** (workflow, board columns, key, estimation unit).

The tabs are contributed through `ResourceContributions` so `projects` never imports `work`
(§13.3). The project list gains three computed columns — open items, items done this month, the
active sprint — behind a toggle, hidden by default.

**Reports**, through the existing report catalogue so they get the same explorer, the same
saved views and the same scheduled delivery as every other report: burndown (per sprint),
velocity (per project), cumulative flow (per board), cycle and lead time (per project, by type),
created vs resolved (per project, per week). Five reports, all from `work_items` plus
`started_at`/`resolved_at`, none needing a table of their own.

---

## 8. Integrations

### 8.1 The shape: a registry of providers, a connection per company, a repository per project

`App\Support\Integrations` — a registry in the style of `Webhooks` and `SettingsSections`: a
provider registers a key, a label, a **kind** (`code` or `time`), how it authenticates, which
inbound events it can parse, and which outbound actions it offers. The `work` module registers
GitHub, Bitbucket and GitLab as `code` providers and Clockify as a `time` provider (§8.8); a
future module registers whatever it integrates without touching this one. The kind is what the
rest of this section branches on: a code provider links repositories and yields commits,
branches and pull requests; a time provider links a workspace and yields time entries.

`integration_connections`: company, provider, label, **credentials** (encrypted — the same cast
the environment passwords use), scopes, external account id and name, `connected_by`,
`connected_at`, `status`, `last_event_at`, `webhook_secret`. One company may hold several
connections to one provider (two GitHub organisations).

`work_repositories`: connection, project, external repository id, full name, default branch,
`webhook_id` on the provider's side, `linked_at`. One repository links to one project; a project
may link several.

Connecting is a Settings screen — *Integrations*, in the Company Settings tab of that name — that
walks through the provider's authorisation and, on return, lists the repositories the token can
see so a project owner picks theirs. The webhook on the provider is registered by this
application through the provider's API at link time, with a secret it generated, so nobody pastes
a URL into GitHub by hand and gets it wrong.

### 8.2 Authentication, per provider

- **GitHub**: a GitHub App, not OAuth. An App installs on an organisation with the exact
  permissions it needs (contents read, pull requests read/write, metadata), survives the person
  who installed it leaving, and authenticates with an installation token minted from the App's
  private key — which lives in `.env`, never in a tenant row. The connection stores the
  installation id. Self-hosted GitHub Enterprise is a base URL on the connection, deferred to
  §14's last phase.
- **Bitbucket Cloud**: OAuth 2 consumer, refresh token stored encrypted. Bitbucket Server/Data
  Center is a different API and is deferred (§16.5).
- **GitLab**: OAuth 2 application, with the instance URL on the connection because self-hosted
  GitLab is the common case.

Fallback for all three: a personal access token typed in, for the company whose policy forbids
installing apps. The connection records which it is.

### 8.3 Inbound: one endpoint, verified before queued

`POST /hooks/{provider}/{connection}` — public, tenant resolved from the connection id the way the
status page resolves its tenant from the slug. The signature is verified **synchronously** —
GitHub's `X-Hub-Signature-256` HMAC, GitLab's `X-Gitlab-Token`, Bitbucket's `X-Hub-Signature`
(and, for the one Bitbucket API that sends none, the secret in the URL plus the published IP
ranges) — and a bad one is a `401` that touches no queue. A good one is written to
`integration_events` (connection, provider event id, type, payload, `received_at`,
`processed_at`, `failed_at`, `failure`) and a job is queued. The handler is idempotent on the
provider's event id, because every provider redelivers.

Parsing turns each provider's shape into one of three internal facts — *a commit mentioning
keys*, *a branch created or deleted*, *a pull request opened, updated, merged or closed* — so
everything after this line is provider-free.

### 8.4 What the facts do

- **A commit mentioning `WRK-12`** links the commit to the item (`work_item_commits`: item,
  repository, sha, message first line, author identity, committed at, URL). A **smart commit**
  — `WRK-12 #done`, `WRK-12 #in-review`, `WRK-12 #time 2h`, `WRK-12 #comment fixed the
  off-by-one` — performs the transition, logs the time (§9) or posts the comment, in the name
  of the matched user (§8.5). An unmatched identity links the commit and does nothing else.
- **A branch** whose name contains a key links to it (`work_item_branches`); deleting it marks
  the row deleted rather than removing it. Creating a branch whose key's item is in `todo` moves
  it to the first `in_progress` status — the one automation every team asked for and the one
  Jira hides behind a setting. It is a setting here too, on by default.
- **A pull request** whose title or branch names a key links to it (`work_item_pull_requests`:
  item, repository, number, title, state, author identity, URL, `merged_at`, `checks_state`).
  Opening one with an item in `todo`/`in_progress` moves it to *In review* if the workflow has a
  status so named or mapped (the workflow settings let a company say which); merging moves it to
  the first `done` status if the project's setting says so, and otherwise only records the
  merge. Closing without merging records that.

Every automatic move goes through `moveTo()`, obeys the transition rules, and is written to the
activity log as *by GitHub (via Ahmed Raza)* — the integration never acts anonymously.

### 8.5 Identity matching

`integration_identities`: connection, provider username, provider user id, email, `user_id`
(nullable), `matched_how` (*email*, *manual*, *claimed*). On first sight of an identity the
application matches by verified email against users; failing that it lists the identity on the
connection's page as unmatched, and a user may **claim** it from their own profile. A smart
commit from an unmatched identity is linked but not executed, and the connection's page says
how many events are waiting on a match. The gap analysis names identity matching as a missing
row; this is it.

### 8.6 Outbound

From the item page's **Development** panel — which lists its branches, commits and pull requests
with their state and a link out — three actions: **Create branch** (named `WRK-12-short-title`
from the repository's default branch, through the provider's API), **Open pull request** (from a
linked branch, title prefilled with the key and title, body with the description), and
**Link existing** (paste a PR URL). From a release (§6.4): **Create GitHub release** with the
release notes as the body, on the repository the release's items touched most.

All outbound calls go through a per-provider client with retry on the provider's rate-limit
headers and a circuit breaker that pauses a connection for ten minutes after five consecutive
failures and says so on its page. A paused connection queues nothing inbound either; the
provider's redelivery covers the gap.

### 8.7 Chat: Slack and Microsoft Teams

Not a provider in §8.1's sense — there is no repository to link. A **channel subscription** on a
project (`work_channel_subscriptions`: project, channel kind, destination, events) posts a line
per chosen event: item created, moved to done, blocked, sprint started, sprint completed,
release released. Slack goes through the notification channel this application already has and
`Broadcasting` already guards; Teams through an incoming-webhook URL. Both are one-way. Slash
commands and bots are §17.6.

### 8.8 Time: Clockify

**Why a second place to log time at all.** Developers run a stopwatch, not a form. A team that
already lives in Clockify — timer in the menu bar, Chrome extension on every tab — will not open
a timesheet entry at the end of each item, and a tool that makes them is a tool whose timesheet
is empty by Thursday. The item page's *Log time* (§9) stays for everybody else, and for the same
team on the day the extension is down.

**The mapping, one row per thing on each side.** A Clockify *workspace* is a connection (§8.1,
kind `time`, authenticated by the workspace API key the company's Clockify admin generates — it
is the one credential Clockify offers at that level — stored encrypted). A Clockify *project* is
linked to a project here (`work_time_projects`: connection, project, external project id,
name); the link is made from the project's Repositories tab, renamed **Connections** once a time
provider exists, by picking from the workspace's project list or by **creating** the Clockify
project from here with the same name and the key as its code. A Clockify *task* is a work item
(`work_time_tasks`: item, external task id), created lazily — the first time somebody starts a
timer on an item, or when an item enters an `in_progress` status if the project says so — named
`WRK-12 · title`, so it reads the same in both places. A Clockify *user* is a user, matched by
email through the identity table of §8.5, with claiming for the ones that differ.

**Inbound: entries become timesheet entries, exactly once.** Clockify's webhooks — a new entry,
an entry updated, an entry deleted, a timer stopped — reach the same endpoint as §8.3 and are
verified with the signing secret Clockify issues per webhook. Where the workspace's plan does not
offer webhooks, or as the backstop where it does, a scheduled pull every fifteen minutes asks the
time-entries API for the last two days and the nightly run asks for the last thirty. Both paths
land in `integration_events` and are processed by one handler, idempotent on the Clockify entry
id: a stopped entry on a linked task becomes a `timesheet_entries` row with `work_item_id` set,
`source = clockify`, `external_ref` the entry id, minutes from the interval, billable from
Clockify's flag, the description as the note. An entry on a linked project with no task is a
timesheet entry with no item — still time on the project, still true. An entry on an unlinked
project is held as unmatched on the connection's page with a count, the way an unclaimed git
identity is. A running timer is shown on the item as *running, 00:41* and written nowhere until
it stops: the ledger holds hours, not intentions. An update re-writes the row; a delete removes
it, and both are in the activity log as *by Clockify (via Ahmed Raza)*.

**Outbound: start, stop, and the task that must exist first.** On the item page, for a user
with a linked Clockify identity, **Start timer** creates the task if it is missing and starts an
entry on it through the API, with the key in the description; **Stop** ends it. Nothing else
flows out: rates, cost and the invoice are this application's (§9), and Clockify's own rates are
never read, because two sources of a rate is a reconciliation nobody runs.

**The mode, which is the option the owner asked for.** A company setting, `work.time_source`,
in the `work.defaults` section:

- **here** — *Log time* on the item writes the ledger; the timer buttons are absent; Clockify,
  if connected, is read for the project-level entries only.
- **clockify** — the timer buttons are present; *Log time* is absent on an item that has a
  Clockify task, because an hour that could be entered in both places will be, and the ledger
  would carry it twice. It stays present on an item with no task, and on every item for a user
  with no linked identity, who has no other way.
- **either** — both, and the reconciliation report (§9) is where a manager finds the day
  somebody did both.

A project may override the company's mode on its Settings tab, because an outsourced team and
an in-house one in the same company do not run the same stopwatch.

Toggl Track is the same shape — workspace, project, task, entry, a webhook per workspace — and
is a second `time` provider on the day somebody asks; nothing in this section names Clockify
below the parsing layer.

### 8.9 Anything else: the outgoing webhook registry, and imports

Every tool not named above gets the item events through the outgoing webhooks that already
exist (§10.4), which is how Zapier, Make, n8n and a company's own scripts subscribe. That is the
"other tools" answer, and it is deliberately the same answer the rest of the application gives.

**Imports**, because a team arriving from Jira or Trello with four hundred open items will not
retype them: a CSV importer through `CsvImporters` for Jira's export and Trello's JSON, mapping
their statuses onto a workflow in a form that shows both lists, their users onto users by email,
and keeping the source key in a `imported_from` column so a search for the old `PROJ-412` still
finds it. Attachments are not imported in the first version; the row keeps their URLs.

---

## 9. Time and cost

`timesheet_entries` gains three columns, added by **this module's** migration, not Timesheets'
— they arrive when the module does and Timesheets never learns the names: `work_item_id`
(nullable, indexed); `source` (`manual`, `clockify`, `smart_commit` — where the row came from);
and `external_ref` (nullable, unique per source — the Clockify entry id, the commit sha — which
is what makes every sync idempotent). The timesheet form gains an item picker, contributed
through `ResourceContributions`, offered only when the entry's project has items. A row whose
source is not `manual` is read-only on the timesheet and says where to edit it; the ledger does
not let two tools disagree about one hour by letting both write it.

From the item page: **Log time** — minutes, date, a note, billable — which writes a timesheet
entry and nothing else, so the week's timesheet, the payroll inputs and the invoicing lines all
see it as time, because it is. Beside it, when the company or the project has chosen Clockify
(§8.8) and the user has linked their account, **Start timer** and **Stop**; and in `clockify`
mode *Log time* steps aside on any item that has a Clockify task, so that no hour has two doors.
The item shows estimate against logged, with the remaining computed and the source of each entry
marked; a sprint shows the same in sum.

A sixth report joins §7's five: **time by source** — per project and per person, hours logged
here against hours synced from Clockify, with the days on which one person has both. It is the
reconciliation a manager runs when the mode is `either`, and the reason `either` is allowed.

Cost per item is the `LabourCost` contract over its entries — the rate the module behind that
contract already knows — rolled up to Epic, sprint and project. Billing is `BillableTime`,
unchanged: an invoice line from a project's billable hours now carries the keys of the items
they were logged against, which is what a client asks for on the invoice.

---

## 10. Who hears what

### 10.1 The audience, from the columns

`WorkItem::changeAudience()` returns the reporter, the assignee's user, and every watcher.
`RecordAudience` does the rest: any change to the item reaches them on the bell, and the
existing *"a record of yours changed"* switch governs it. Assigning an item adds the assignee to
its watchers; commenting adds the commenter. Nobody is told about their own edit.

### 10.2 A category: *work*

A new key in `NotificationPreferences::CATEGORIES`, and every notification this module sends
declares it (`CategorisedNotification`). One switch — *work* — turns all of it off for somebody
who runs their day from the board and wants no mail about it. `NotificationCoverageTest` enforces
the declaration.

### 10.3 Mentions, and the digest

`@Ahmed` in a comment or a description notifies that user, matched on the mention picker's id
rather than the text, so renaming nobody breaks nothing. The morning **digest** — items due
today, items assigned since yesterday, items blocked on you — is one scheduled notification per
user who has any, sent at a time the company sets, off for anybody who turns the category off.

### 10.4 Outgoing webhooks

Registered in `Webhooks` by this module's provider, each with a named payload: `work_item.created`,
`work_item.moved`, `work_item.assigned`, `work_item.resolved`, `work_item.commented`,
`sprint.started`, `sprint.completed`, `release.released`. The payload carries the key, the
project key, the status and its category, the assignee's email, and never the description — the
description is the one field a subscriber should fetch rather than be sent, because it is the
one that leaks.

---

## 11. The API

Mounted through `ApiRoutes::group(['api', 'auth:sanctum', 'api.company', 'module:work'])`,
with a `work` ability registered in `ApiAbilities` and required on every route. The surface a
mobile board needs and nothing it does not:

- `GET /work/projects` — the projects the token's user may see, with key and open count.
- `GET /work/projects/{key}/board` — columns with their cards, in rank order; the one call the
  board screen makes.
- `GET /work/items?project=&status_category=&assignee=me&sprint=active` · `POST /work/items` ·
  `GET /work/items/{key}` · `PATCH /work/items/{key}` — the fields of §3.2 by name.
- `POST /work/items/{key}/move` — status (by id or name) plus optional neighbours for rank; the
  same `moveTo()` the board calls, the same rules, the same refusals as `422`s with the reason.
- `POST /work/items/{key}/comments` · `POST /work/items/{key}/time`.
- `GET /work/my` — the "My work" board as data.

Every write is attributed to the token's user in the activity log and reaches the audience of
§10. `docs/api/openapi.yaml` gains the paths in the same commit as the routes — and a test that
does not exist today: nothing checks the spec against the routes, so the two have been free to
drift since the file was written. Phase 9 adds `ApiContractTest` (every mounted `/api/v1` route
is in the spec, every spec path is mounted) and backfills whatever it finds.

---

## 12. AI assists

Three, all through `StructuredModel`, all absent when `ai.php` has no key, none of them on the
write path without a person clicking:

1. **Draft from a sentence.** "Users can't export the March payroll as PDF on Safari" becomes a
   Bug with a title, a description in the house template (steps, expected, actual), a suggested
   priority and two labels — shown as a prefilled form, saved only when somebody saves it.
2. **Summarise the thread.** On an item with more than ten comments, a three-line summary at the
   top, regenerated on request, marked as generated.
3. **Suggest the estimate.** From the title, type and the estimates of the project's closed items
   with similar titles — a number next to the field, with the three items it was drawn from.
   The number is never written; the person types it or does not.

That is the ceiling. Auto-assignment, auto-prioritisation and sprint planning by model are
§17.7.

---

## 13. Permissions, licensing, and the module graph

### 13.1 Permissions

Group **Work**: `WorkItemView`, `WorkItemCreate`, `WorkItemUpdate`, `WorkItemDelete`,
`WorkItemMove` (so a client contact may read a board and not drag on it), `BoardManage`,
`SprintManage`, `ReleaseManage`, `WorkflowManage`, `IntegrationManage`. Role grants: Employee
gets view, create, update, move; Manager adds board, sprint and release management; Administrator
holds everything including workflows and integrations, which are the two that reach outside the
project.

### 13.2 Visibility

A project is **open** (every `WorkItemView` holder sees its items) or **private** (its members,
its managers, and the hierarchy above them through `EmployeeAccess`). The default is open — a
team that hides its board from the rest of the company is the exception, and a private project
says so in its header.

### 13.3 The graph stays acyclic

`work` requires `employees` and `projects`. It is required by nothing. Three places where the
arrow would otherwise point the wrong way, and the mechanism for each:

- Projects' page showing Work's tabs — `ResourceContributions` (§7).
- Timesheets' form offering an item — `ResourceContributions`, and the column is Work's
  migration (§9).
- Support's ticket spawning an item — `ResourceContributions` (§3.6).

`ModuleBoundaryTest`'s cycle budget stays at zero, and `ModuleGatingTest` gets the usual rows: a
company without `work` sees no eighth domain, no tabs, no column, no item picker.

### 13.4 Licensing

`licensed_by_default` false. Added to the *Business* plan beside projects and timesheets in
`config/plans.php`, and to the services and software company profiles. Metered, if §6 of the
launch plan's metering ever charges per module, on active users who moved a card in the month.

---

## 14. Phases

Each phase ends green, committed on its own, with the guard tests updated in the same commit
(§15). The order is the dependency order; nothing in a later phase is needed to use an earlier
one.

**Phase 0 — The rail, the skeleton, and the badge registry.** The `work` manifest, plugin,
provider, permissions, role grants; the **Work** domain on the rail with `projects` moved into
it; `NavigationDomainsTest` and `NavigationTreeTest` updated. And the sidebar **badge registry**
the performance test has asked for four times: one query collecting every badge count, which
this module would otherwise push from 21 to 24 separate statements. Built first because it is
cheaper to build before the badges exist than after.

**Phase 1 — The work item.** §3 and §4 entire: the project key and sequence, `work_items` with
every column, labels, watchers, attachments, links, the three option lists, workflows with
statuses and categories, `moveTo()` with the four rule kinds, the item page (description,
comments, activity, custom fields, attachments, links, time-logged placeholder), the item list
with filters, the slide-over. No board yet: the list is the board's query with no columns, and
it has to be right first.

**Phase 2 — The Kanban board.** §5: boards, column mapping, drag between and within, rank,
WIP limits, swimlanes, quick filters as URL state, the card, "My work", polling. Live updates
behind `Broadcasting::isConfigured()`.

**Phase 3 — Backlog and sprints.** §6.1–6.2: the ranked backlog, inline create, bulk actions,
sprints with start and complete, carry-over, the Scrum board, velocity, burndown as the first
report through the catalogue.

**Phase 4 — Epics, releases, the project page.** §6.3–6.4 and §7: Epic progress, releases with
notes, the tabs through `ResourceContributions`, the project list's columns, the remaining
reports.

**Phase 5 — Time and cost.** §9: the column, the picker, Log time, estimate against logged,
cost roll-up through `LabourCost`, item keys on invoice lines through `BillableTime`.

**Phase 6 — Who hears what.** §10: `changeAudience()`, the *work* category, mentions, watchers,
the digest, the eight outgoing webhook events.

**Phase 7 — Integrations: the registry and GitHub.** §8.1–8.6 for one provider: the registry,
connections, the GitHub App, repository linking with the hook registered by the app, the inbound
endpoint with signature verification and idempotency, the three facts, smart commits, identity
matching with claiming, the Development panel, create branch and open PR, the circuit breaker.
GitHub first because it is where most of this company's own code lives and the one every
candidate will have used.

**Phase 8 — Bitbucket, GitLab, and Clockify.** §8.2–8.4 for the other two code hosts, which
is parsing and auth; the facts and everything after them are already provider-free. Self-hosted
URLs for GitLab. Then §8.8 entire — the `time` kind on the registry, the workspace connection,
project and task linking, the inbound handler with its fifteen-minute and nightly pulls, the
timer buttons, the mode and its project override, and the time-by-source report. After Phase 5
because it writes the ledger Phase 5 defines, and after Phase 7 because it rides the registry,
the endpoint, the identity table and the circuit breaker Phase 7 builds.

**Phase 9 — Chat, imports, the API.** §8.7, §8.9 and §11: channel subscriptions for Slack and
Teams, the Jira and Trello importers, the API with its ability and OpenAPI paths.

**Phase 10 — Timeline and AI.** §6.5 read-only, and §12's three assists. Last because both are
on top of everything else and neither is what a team buys this for.

Ten phases. The first four are a usable product; a team can plan and run sprints on a board at
the end of Phase 3. Seven onward is what makes it the team's only tool.

---

## 15. Tests

The guard tests that must change, by phase — the "updated, not added" list of
`docs/new-module-checklist.md` §12, applied:

| Test | When | What changes |
|---|---|---|
| `NavigationDomainsTest`, `NavigationTreeTest` | 0 | The eighth domain; `projects` leaves People; the new branches, each ≤ 8. |
| `PanelPerformanceTest` | 0 | The dashboard budget comes **down** when the badge registry collapses 21 counts into one; the note explains the drop. Then holds through every later phase, which is the registry's point. |
| `ModuleCoverageTest`, `ModuleGatingTest`, `AliasLockTest` | 1 | The module, its models' aliases, what a company without it does not see. |
| `RoleGrantsTest` | 1 | Role sizes grow by the grants in §13.1. |
| `SettingsSectionsTest` | 1, 7 | Two sections, both under **Company** in the exact lists: `work.defaults` (the estimation unit of §16.3 and the merge-moves-to-done default of §8.4, each of which a project may override on its own Settings tab) and `work.integrations` (the connections of §8.1). Company rather than People because neither is employment policy. |
| `HelpCoverageTest`, `HelpContentAccuracyTest` | every phase that adds a screen | A help file per list page and standalone page, in the house style, with the roles table. |
| `TranslationCoverageTest` | every phase | Every label through `__()`. |
| `ModuleBoundaryTest` | 4, 5, 9 | Three contributions, zero cycles. |
| `ApiContractTest` | 9 | **New, not updated.** Nothing today checks `openapi.yaml` against the mounted routes. The test is written here, and its first run is an audit of the existing API as much as of this one. |
| `NotificationCoverageTest` | 6 | Every notification declares *work*. |
| `TenantIsolationTest` | 1, 7, 8 | Items, boards and — especially — `integration_connections` never cross a tenant; an inbound hook for connection 12 cannot touch connection 13's project, and a Clockify entry for one workspace cannot land in another company's timesheet. |

Feature tests per phase, named for what they prove, in the style of `HiringCandidatePageTest`:
the key sequence survives two concurrent creates; `moveTo()` refuses what the rules refuse and
stamps what the categories stamp; a card dropped over a WIP limit snaps back; completing a sprint
carries over exactly the unfinished; a forged signature is a 401 that queues nothing; a
redelivered event is processed once; a smart commit from an unmatched identity links and does
not move; a private project's items are invisible to a non-member with every permission; a
token without the `work` ability gets a 403 on every route. And for Clockify: the same entry
delivered three times is one timesheet row; an updated entry rewrites that row and a deleted one
removes it; a running timer writes nothing; an entry on an unlinked project is held, not
dropped; in `clockify` mode *Log time* is absent on an item with a task and present on one
without; a Clockify-sourced row cannot be edited on the timesheet; and Clockify's rate is never
read, which is asserted by giving the workspace a rate and checking the cost report ignores it.

---

## 16. Open decisions

1. **The eighth domain.** §2.3 puts Work on the rail and moves Projects out of People. The
   alternative is a branch under People with no rail entry. Decided here; reversible in one file.
2. **Work item, or task.** §2.2 argues the name. If the owner prefers *Task* on screen, the
   system noun stays `work_item` and only the labels change — but Performance's recurring tasks
   would then need a different label, and that is the cost.
3. **Points or hours.** Both columns exist; a per-company setting picks which the forms show and
   the reports sum. Default: hours, because the timesheet already measures hours and a company
   that has never run a sprint has never seen a point. A software team flips it.
4. **Where "In review" comes from.** §8.4 moves an item to *In review* on PR open only if the
   workflow has such a status. The alternative is a fourth category. Three categories are kept
   because every report depends on exactly three, and a review is in progress by any honest
   reading.
5. **Bitbucket Server / Data Center and GitHub Enterprise.** Deferred past Phase 10. Both are a
   base URL and a different auth path; both are a day each once Phase 8 exists; neither has a
   known customer.
6. **Attachments in imports.** §8.9 keeps the URLs. Fetching them is a job per file against a
   token that expires; worth doing when the first team arrives with them.
7. **Whether a Subtask may have an estimate of its own.** Jira says yes and sums; Linear says
   no. Yes here, summed into the parent for display, because the timesheet is logged against the
   subtask and the estimate should sit where the time does.
8. **The time mode: per company with a project override, or per user.** §8.8 chooses company
   and project. Per user was considered — one developer on Clockify, the rest not — and is
   covered by the rule that a user with no linked identity always keeps *Log time*; what per user
   would add is letting a linked user opt back out, and that is a setting on their profile the
   day somebody asks.
9. **Whether a Clockify task is created on first timer or on entering *in progress*.** §8.8
   offers both as a project setting, defaulting to first timer: a task created for every item
   that was ever started fills a Clockify workspace with tasks nobody timed.

---

## 17. What not to build

Each with the reason, so the next person who wants it knows what they are arguing against.

1. **A workflow language.** Conditions, validators and post-functions as expressions. §4.3's four
   rule kinds in a table cover what teams here configure; the rule builder of the launch plan is
   where a fifth goes. A language is maintained forever by the person who wrote it.
2. **Issue hierarchies beyond three levels.** Initiatives above Epics are a roadmap feature for
   people who do not open the tool; the timeline shows Epics, which is the level work is planned
   at.
3. **Gantt dependencies and critical path.** §6.5 is bars on months. Finish-to-start arrows and
   a computed critical path are a scheduling product, and the Construction module's programme is
   where that lives in this application already.
4. **A wiki.** The description is Markdown and attachments are files; a knowledge base beside
   the board is a second product with its own search, permissions and rot.
5. **Many assignees.** §3.2. A card with three owners has none.
6. **Chat bots and slash commands.** §8.7 posts; it does not listen. A bot is an authentication
   surface, a permissions model and a conversation designer, for a feature whose use is "move
   WRK-12 to done from Slack", which the smart commit already does from the place the work
   happens.
7. **AI that acts.** §12 drafts, summarises and suggests. Auto-assigning, auto-prioritising and
   planning a sprint by model are decisions a team makes in a room, and a tool that makes them
   silently is one the team stops trusting on the first wrong call.
8. **Merging tickets and work items.** §3.6. A ticket is a customer asking; an item is a team
   doing; the link between them is the feature.
9. **Time tracking of its own.** §9. The timesheet is the record of time; a second one is a
   reconciliation nobody runs.
10. **Per-design-tool API integrations.** §3.6. A URL to the Figma file is what was wanted.
11. **A stopwatch of our own.** §8.8's rule: Clockify is the stopwatch, the timesheet is the
    ledger. A running timer in this application's header is a second stopwatch, a second place
    for a developer to forget to stop it, and the same double-counting problem the mode exists
    to prevent — now between two screens of the same product. *Log time* takes minutes after the
    fact, which is what a ledger entry is.
12. **Reading Clockify's rates, or writing ours to it.** §8.8. Cost has one source here
    (`LabourCost`), and a rate that lives in two tools is a reconciliation the day they differ.
