![Projects](/images/help/projects.png)

## What a project is

A delivery project: a team of employees with dated stints, a primary and
secondary manager, and its deployment environments (Production, Qualification,
Development) — each with a URL, shared login details, and ongoing health
monitoring. Unlike most resources here, **every project is visible to
everyone** who can view projects at all — there's no per-record ownership
scoping, only the module-wide permission.

## Creating and editing

Click **New**. Give it a **Code** (e.g. `PRJ-ERP-01`) and **Name**, a
**Status** (Planned, Active, On hold, Completed, Cancelled), and optionally a
**Primary manager** and **Secondary manager** — two different people; the
secondary is who to go to when the primary is unavailable, and the form
refuses to let them be the same person.

## Environments and monitoring

Each environment (Prod/Qual/Dev) carries its own URL, username, and
**password — stored and displayed in plain text**, so treat this like any
other place a credential lives. Per environment you control:

- **Health checks** (on by default) — the server polls the URL on an
  interval you set (or a company default), tracking uptime and latency.
  Turn this off for a URL the server genuinely can't reach, e.g.
  `localhost`.
- **Alert on outage** — emails the primary and secondary manager once an
  outage is *confirmed* (a health checker tolerates transient blips before
  declaring one).
- **Body must contain** / **Expected HTTP status** — optional stricter checks
  that catch a page returning 200 while actually showing an error, or
  confirm a status other than 2xx is in fact expected (401/403 behind auth).
- **Show on public status page** — publishes only the up/down status and
  uptime percentage for that environment; the URL and credentials are never
  exposed there.

Opening a project shows each environment's current health, uptime over the
last 30 days, latency, and (over HTTPS) certificate expiry — with the SSL
column turning amber inside 30 days and red inside 7.

## The team

The **Team** tab manages who's assigned, as dated stints rather than a plain
member list: **Assign employee** records a role, an allocation percentage, and
a start date; **End assignment** closes it (sets an end date) without erasing
the history, which is what happens for someone leaving a project in the
ordinary course. **Detach** erases the stint outright and is reserved for
correcting a mistake — it needs delete-level access, not just update. An
employee can only have one *open* assignment on a project at a time; ending
the current one is required before starting a new one.

From the Employees side, an employee's own **Projects** tab shows the same
assignments read-only — it's edited from the project, not from there.

## Deleting

Only possible for a project with **no assignment history at all** — once
anyone has ever been on the team, delete the assignments (or just end them)
rather than the project.

## Roles and permissions

- **View / Create / Update** (`ProjectView/Create/Update`) — every seeded
  role, including Employee: projects are a shared company reference, and any
  employee may add one or correct environment data on one.
- **Delete** (`ProjectDelete`) — CEO and Administrator only.
- **On-demand health check** (`ProjectHealthCheck`) — Manager, CEO,
  Administrator only, not Employee or Accountant: firing one makes the server
  issue an outbound request on demand, which is treated as a privileged
  action rather than ordinary project editing.
- Depends on the Projects module being enabled.
