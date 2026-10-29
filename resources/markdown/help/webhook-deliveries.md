## What this is

Every webhook this application tried to send, and what came back. Read-only.

The log is the feature, not a side effect. A webhook that fails quietly is worse than
one nobody set up: the system at the other end carries on believing it is in step.

## What a row says

- **Raised** — when the event happened.
- **Endpoint** — where it was going. "deleted" if the endpoint has since gone.
- **Status** — delivered, pending (waiting on a retry), or failed (out of attempts).
- The description under the status says what the far end answered and how many
  attempts it took.
- **Error** — the first part of the response body, when it was not a success.

**Body** shows exactly what was sent, which is the thing to compare against whatever
the receiving system logged.

## Sending one again <!-- requires: WebhookManage -->

**Send again** is offered on anything not delivered. It posts the same body to the
same endpoint, signed again, and resets the attempt count. Use it after fixing
whatever was wrong at the other end.

A delivered row has no such action: sending the same event twice to a system that
already acted on it is how a thing gets done twice.

## Filters worth knowing

**Failed only** after a payroll run or a bulk import is the one to reach for. It is a
short list when everything is well and a useful one when it is not.

## Roles and permissions

`WebhookManage`, the same as the endpoints themselves. The log holds the bodies that
were sent — this company's records in somebody else's shape — so whoever decides
where they go may see what went.
