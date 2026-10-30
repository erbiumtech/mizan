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
items sees the commits, branches and pull requests on them.
