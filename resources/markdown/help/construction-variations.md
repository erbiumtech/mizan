## Variations and change orders

The same thing under two names — a **variation** under FIDIC, a **change order**
under AIA. One register for both, because the process is identical: instructed,
described, priced, agreed, then written into the schedule.

## The one thing to understand about this screen

There are **two ways to approve** a variation, and they mean different things.

| | Approve | Approve in principle |
|---|---|---|
| The price is | agreed | still being argued |
| The certificate | may include it | may not |
| The cost report | includes it | includes it |
| The schedule | gets the new lines | does not |

**Approve in principle is the state most variations actually live in**:
instructed, work proceeding on site, price disputed for four months. It exists
because the alternative is choosing between two wrong answers — certifying money
nobody has agreed, or forecasting a cost the job is already spending as zero.

So the register shows two figures that are deliberately different: the **revised
contract sum** (original plus *agreed* variations, which is what a certificate
reads) and the **forecast** (plus everything live, provisional included, which is
what the cost report reads). If they are far apart, that gap is the commercial
risk on the job, and it is meant to be visible.

## Raising one <!-- requires: ConstructionVariationCreate -->

**New**, pick the contract — only executed ones appear, because a draft schedule
is edited directly rather than varied. Leave the number blank and it takes the
next in that contract's series; the series must have no gaps, because a missing
variation number is a question at adjudication.

Fill in the **origin** and the **justification**. Origin is where it came from —
an instruction, an RFI answer, a site condition, expenditure of a provisional
sum. Justification is why it is a change at all rather than work already
included, and it is the field somebody reads when the claim is argued.

**Valued by** follows FIDIC 12.3 and 13.3 in order: rates already in the
contract, then pro-rata to them, then a new rate agreed. Recording which one was
used is what makes the assessment defensible later.

## The lines <!-- requires: ConstructionVariationUpdate -->

Four actions, and what each does when the variation is written into the schedule:

- **Add** — a new schedule line, printed appended to the original bill rather
  than folded into it. That is exactly how a change order appears on a
  continuation sheet.
- **Omit** — a **negative** line. It does *not* reduce the line it omits.
- **Remeasure** — changes the quantity on a named line, keeping the old figure.
- **Rate change** — changes the rate on a named line, keeping the old one.

### Why an omission is a negative line

Because every certificate already issued measured "work completed to date"
against that line's **scheduled value**. Shrink the scheduled value now and those
certificates print a completed figure larger than the value it was completed
against — which is not merely wrong, it is impossible on the face of the form.

A negative line is ugly on the page and correct in the ledger. The printed
schedule may net the pair together; the record keeps both.

Remeasures and rate changes *are* allowed to edit in place, and that is safe for
a specific reason: on a remeasured contract the quantity was always approximate —
it is a bill of *approximate* quantities — and every certificate line carries its
own frozen cumulative value regardless, so nothing already issued moves.

## Pricing and approving <!-- requires: ConstructionVariationPrice -->

**Price** records the assessment. Left blank it takes the sum of the lines; typed,
it stands — a certifier assessing a figure different from the one quoted is
ordinary, and both are kept.

**Approve** and **Approve in principle** need `ConstructionVariationApprove`, and
that is a different permission on purpose. Pricing is a surveyor's judgement of
what a change is worth. Approving commits the employer's money.

**Write into schedule** is the last step, and only an approved variation can take
it. An approval in principle deliberately cannot: the schedule is what
certificates are measured against, and putting a provisional price into it would
certify money nobody agreed.

Writing it in twice does nothing the second time — each line records the schedule
line it created.

## Rejecting

Needs a reason, and the field is not optional. It is read months later, by a
lawyer. A rejected variation stays on the register: correspondence does not
disappear because the answer was no.

## Provisional and prime cost sums

Expending one is itself a variation. Set the origin to **provisional sum
expenditure**, omit the sum in full, and add the work that was actually
instructed. That is what FIDIC 13.5 requires, and it is what stops the contract
sum counting the same money twice.

## Who does what

| | Surveyor / commercial | Manager | CEO |
|---|---|---|---|
| Read variations | ✓ | ✓ | ✓ |
| Raise and describe one | ✓ | ✓ | ✓ |
| Price it | ✓ | ✓ | ✓ |
| Approve, or approve in principle | | ✓ | ✓ |
| Write it into the schedule | | ✓ | ✓ |

Site staff hold view: an instruction nobody on site can see is work that gets
built to the superseded drawing.
