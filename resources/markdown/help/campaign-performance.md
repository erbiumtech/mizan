## What this shows

Every campaign that went out — or tried to — in the financial year to date, with what
it reached, what it did not, and why.

**The skipped count is the reason this report exists.** Every other view of a campaign
shows what went out. A campaign that skipped its whole audience and one that was never
sent look identical everywhere else in this system; here they do not.

## The two kinds of skip

Both are recorded as "skipped, no consent", and the report keeps them apart because they
are different problems:

**They had never agreed.** The segment contained people with no consent on record. This
is a list-building fault, and it points straight at the Consent Register: your segment
and your register disagree about who may be reached. Fix the segment, or record the
consent properly.

**They withdrew before the send.** Consent is checked when a campaign is prepared and
checked *again* when it is sent, because somebody may unsubscribe in the gap — and that
gap is exactly when a complaint comes from. This figure is the guard **working**: the
message that would have caused the complaint did not go out. It needs no action.

## Standing

- **Sent** — the run finished.
- **Sent · N never processed** — the campaign is marked sent but N recipients are still
  pending. The send loop stopped part way. Nothing else in the application notices,
  because the campaign's own status says it went out.
- **Sent · reached nobody** — it had recipients and none of them received anything.
- **In flight** — still sending. Pending rows here are expected.
- **Cancelled** — stopped on purpose. Pending rows here are expected too.

## Top failure

Delivery failures come from whichever channel sender your company has configured, so
their reasons are free text from outside this module. The column names the **most
common** reason per campaign rather than sorting them into categories, because the
categories would be invented.

The note names the most common reason across all campaigns, counted by how many
*campaigns* hit it rather than how many sends failed — one campaign to a bad list
produces thousands of identical failures, and the reason worth knowing is the one that
keeps happening to different campaigns.

## What this report cannot tell you

**A recipient with no email address or WhatsApp number gets no row at all.** When a
campaign is prepared they are passed over silently, on the reasonable grounds that
somebody with no WhatsApp number has not refused anything. So the recipient count here
can be lower than the segment's size, and the difference is not recorded anywhere.

This report deliberately does not try to reconstruct that gap by re-running the
segment. Segments evaluate their filters **live**, so re-running one gives today's
audience and not the audience that existed when the campaign went out — every campaign
whose segment has since gained a member would show a false shortfall. If you need the
figure, check it at the point of preparing the campaign, which shows exactly who it
would reach before anything leaves the building.

## Using it

The period is the **financial year to date** — 1 July to your date. Campaigns are
placed by their send date, or by when they were created if they have not sent yet.
Without that fallback the unfinished and in-flight runs would be exactly the ones this
report could not see.

**Recipients includes pending rows.** They were prepared and addressed; the run simply
never reached them.

Drafts and merely-scheduled campaigns do not appear. Neither has performance yet.

The table is wider than the pane and scrolls sideways.

## Roles and permissions

Requires `ReportView`, gated behind the Campaigns module being enabled. Read-only —
preparing, sending and cancelling all happen on the campaign itself.
