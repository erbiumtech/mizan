## What a job is

One contract to build one thing at one place: a tower, a road package, a
fit-out, a substation. It's the unit that gets a contract sum, a cost code
structure, a site, a team, a programme, certificates, retention and a final
account — everything else in the construction modules hangs off it.

**A job is not a project.** If your company also uses the Projects module,
that's a software delivery engagement with deployment environments and a
status page, and it lives under **People → Employee**. The two are unrelated
and you'll never see them in the same menu.

## Creating and editing <!-- requires: ConstructionJobCreate, ConstructionJobUpdate -->

Click **New**. The **Job number** is what everyone on site will quote
(`J-2026-014`), so use the number your own paperwork uses — it must be unique
and you can search on it.

A job starts at **Tender** and moves through Awarded, Mobilising, In progress
and on to Final account and Closed. Nothing forces you to fill in the
commercial terms to save a tender, which is deliberate: at tender stage most
of them aren't agreed yet.

### The contract

**Contract standard** picks which contract family this job follows — FIDIC
(international, and most of Asia, the Middle East and Africa) or AIA (North
America). It changes the vocabulary and the certificate forms, not what you
can record. Pick **Custom** for a bespoke contract.

The **Certifier** is the Engineer under FIDIC and the Architect under AIA —
the same field, and the screen uses whichever word your standard uses.

**Contract sum** is the *original* sum only. The revised sum is worked out
from your approved variations and is never typed in, so the number on screen
and the variation register can't disagree.

> The Client and Certifier pickers only appear if you have the Invoicing
> module, because both are contacts from your contact book. Without it a job
> still works — you just record the parties elsewhere.

### The site and the programme

Address and city are what appear on a certificate and a delivery note.

The **completion date in force** is the revised one when an extension of time
has moved it, and that's the date the job list shows. Revised completion
should only be changed by an approved extension of time — a date edited by
hand is a date nobody can explain later.

## Lots, towers and phases

A job can sit under another one. Use this when a contract is **certified in
parts**: a development with three towers each taken over separately, a
highway in four lots each with its own completion date and its own liquidated
damages, or a framework with a job per call-off.

Cost and certificates attach to **any** job in the tree, and every report
takes a job and rolls up everything beneath it. So you get the per-lot
certificate *and* the consolidated figure the board asks for, without
choosing between them.

Two levels is normal, three is available, and beyond that people stop being
able to find anything.

## What the list shows

The list defaults to **live jobs** — a contractor's closed, cancelled and
lost list only ever grows, and it isn't what the screen is for. Switch the
**Live jobs** filter to see the rest.

## Deleting <!-- requires: ConstructionJobDelete -->

A job can be deleted while it's raised in error. A **closed** job cannot be
deleted by anyone: its certificates, retention ledger and final account are
the contractual record of a completed contract.
