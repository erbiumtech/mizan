## What this is

The queue between "can I have a letter for my visa application" and the letter
itself. An employee asks here; somebody in HR issues it or refuses it with a
reason.

Anybody may ask — there is no permission for it, the same way there is none for
filing a change request. Deciding is `LetterIssue`.

## Asking for one

**New**, pick the **letter**, and say what it is **for** if it helps whoever
reads the request. The purpose is printed on some letters, so "a visa
application for Turkey" reads better on paper than "visa".

Increment, promotion, transfer and confirmation letters are not on the list.
Those arrive on their own when the thing happens — see Letter Templates.

## Issuing or refusing

**Issue** renders the letter from the company's template, emails it to the
employee with the PDF attached, and marks the request issued. If the company
has not written that template yet, nothing is sent and the screen says so:
write the template first, then issue.

**Refuse** needs a reason, and the reason is shown on the request where the
employee can read it. A refused request is not deleted, because "I asked and
was told no, in these words" is the thing somebody needs three months later.

A request waiting on a decision can be withdrawn by the person who made it.
Once it has been answered it stays as a record.

## Roles and permissions

Everybody can see their own requests and raise new ones. `LetterView` shows
everybody's; `LetterIssue` is the decision. Both are held by Manager and above
by default.
