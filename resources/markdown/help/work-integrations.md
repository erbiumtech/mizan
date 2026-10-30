## What this is

A connection is this company's door to one account on a code host — a GitHub organisation, say.
Through it, repositories are linked to projects, and from then on the repository talks to the
board: a commit that names `WEB-12` is attached to WEB-12, a branch called `WEB-12-export-fix`
starts it, a pull request that mentions it moves it to review, and merging moves it to done.

One company may hold several connections to one provider — two GitHub organisations, for
instance.

## Connecting

**GitHub** connects as a GitHub App: install the App on the organisation, and give the connection
the installation id. An App survives the person who installed it leaving, and asks for exactly the
permissions it needs. The App's own private key lives on the server, never in this company's
records.

A company whose policy forbids installing apps may type a **personal access token** instead. The
connection records which it is.

**Bitbucket Cloud** connects with an app password (your username and the password) or a workspace
access token, and the workspace slug. **GitLab** connects with a personal or project access token
and, for a self-hosted instance, its URL. Both are read exactly as GitHub is once connected: the
same commits, branches and merge requests, the same rules.

**Clockify** is a different kind of connection — a time tracker rather than a code host. It takes
the workspace API key your Clockify admin generates and the workspace id. When making the webhook
in Clockify, paste the URL shown on the connection's page and give it the connection's signing
token, shown beside it.

## Time from Clockify

Clockify is the stopwatch; the timesheet is the ledger; and an hour exists in the ledger exactly
once.

A Clockify **project** is linked to a project here from the connection's *Time projects* tab —
pick one from the workspace, or create one with the same name and the key as its code. A
Clockify **task** is a work item, named `WEB-12 · title` so it reads the same in both places,
created the first time somebody starts a timer on the item (or, if the project says so, when the
item starts). A Clockify **user** is matched to a person here by email, like a git identity, and
may be claimed.

A stopped entry on a linked task becomes a timesheet row with the item set, marked as from
Clockify, with the minutes from the interval, billable from Clockify's flag and the description as
the note. An entry on a linked project with no task is time on the project with no item — still
true. An entry on an **unlinked** project is held on the connection's deliveries tab, with the
reason, until the project is linked and it is replayed. A **running** timer is shown on the item
and written nowhere until it stops: the ledger holds hours, not intentions. An update rewrites the
row and a delete removes it, both on the activity log as *by Clockify (via …)*.

Entries arrive by webhook and by a pull every fifteen minutes for the last two days (and nightly
for the last thirty), so a workspace whose plan has no webhooks still syncs; the two paths land in
the same place and the same entry is never written twice.

**The mode**, under Settings → Work and overridable per project, is which door an hour comes
through: *here* (Log time on the item; no timer buttons), *Clockify* (the timer buttons; Log time
only on items with no task, and for people with no linked Clockify account), or *either* (both,
reconciled in the Time by Source report).

Nothing else flows out. Clockify's own rates are never read: the rate is this application's.

## Linking a repository

**Link repository** on a connection lists the repositories the token can see; pick one and the
project it belongs to. The webhook on the provider is registered by this application, with a
secret it generated — nobody pastes a URL into GitHub by hand and gets it wrong. One repository
links to one project; a project may link several.

## What a repository does

- **A commit mentioning a key** is attached to the item. A *smart commit* — `WEB-12 #done`,
  `WEB-12 #in-review`, `WEB-12 #time 2h`, `WEB-12 #comment fixed the off-by-one` — performs the
  transition, logs the time or posts the comment, in the name of the matched person.
- **A branch** whose name contains a key is attached. Creating one for an item that is still *to
  do* moves it to the first *in progress* status. That is a company setting, on by default.
- **A pull request** whose title or branch names a key is attached. Opening one moves the item to
  the workflow's review status — the one named *In review*, or the one chosen on the workflow.
  Merging moves it to done if the project (or, failing that, the company) says so; otherwise the
  merge is only recorded.

Every automatic move goes through the same rules a drag on the board does, and is written to the
activity log as *by GitHub (via Ahmed Raza)*. The integration never acts anonymously, and a rule
that refuses a move is recorded rather than overridden.

## Who is who

A commit's author is a GitHub username, and the first time one is seen it is matched to a person
here by **verified email**. When that fails, the identity is listed on the connection as unmatched.
An administrator may assign it, or the person may claim it there. Until then, a smart commit from
that identity is attached but **not acted on**, and the connection says how many deliveries are
waiting on a match.

## When it breaks

Every call to the provider runs through a circuit breaker: after five consecutive failures the
connection is paused for ten minutes and its page says so. A paused connection accepts no
deliveries either — the provider redelivers, and nothing is lost. A delivery that failed while
being processed keeps its payload and can be replayed from the connection's page once whatever
failed is fixed.

## Roles and permissions

Connecting, linking and assigning identities need **IntegrationManage**, which only Administrators
hold: a connection holds a credential to somebody else's system. Everybody who may read work
items sees the commits, branches and pull requests on them, and anybody with **WorkItemUpdate**
and a linked Clockify account may start and stop the timer on an item.
