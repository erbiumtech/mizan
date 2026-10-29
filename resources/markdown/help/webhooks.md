## What this is

Where your own systems listen. When something happens here — somebody joins, an
employee accepts a payslip — this application posts a small JSON body to an address
you give it, so the system at the other end hears about it instead of polling.

## Setting one up <!-- requires: WebhookManage -->

**New**, then give it a name, an HTTPS address that answers `POST`, and tick what you
want sent. Anything in the 200s counts as delivered; anything else is retried.

On save you are shown a **signing secret, once**. Copy it. It is not shown again, and
that is deliberate: a screen that can show a secret again is a screen anybody who
reaches it can read it from. Lost it? Delete the endpoint and make another.

## Checking the signature

Every delivery carries three headers:

```
X-Mizan-Event      employee.joined
X-Mizan-Delivery   4182
X-Mizan-Signature  sha256=<hmac>
```

The signature is an HMAC-SHA256 of **the exact request body** under your endpoint's
secret. Compute the same thing at your end and compare them with a constant-time
comparison before you trust a word of the body. Without that check, a webhook URL is
a public endpoint that anybody who learns it can post to.

**Send a test** posts a made-up body signed the same way, so you can get that check
working before anything real depends on it.

## Retries, and when an endpoint is switched off

A delivery that does not get a 2xx is retried three times — after a minute, ten
minutes, and an hour. That covers a deploy and most outages. After that it is marked
failed and waits in the delivery log for you to send it again by hand.

An endpoint that fails **ten events in a row** is switched off. It is not a
punishment: an endpoint that has been gone for ten events is gone, and every event
after it would otherwise cost a worker four attempts over two hours to discover that
again. Fix it and switch it back on.

## What is in the body

Each event sends a small, named set of fields — not the whole record. That is on
purpose: a payload built from the record would change the day somebody adds a column,
including the day the column is a bank account.

## Roles and permissions

`WebhookManage`, which by default only Administrator holds. An endpoint is a standing
instruction to send this company's data to an address, which is not a small thing to
be able to add.
