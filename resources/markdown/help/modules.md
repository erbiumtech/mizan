## What this is

Switches for which licensed modules are actually turned on for this company
right now. Only modules already **licensed** appear here at all — an
unlicensed one isn't shown as a locked toggle, since there'd be nothing you
could do about it from this screen. Licensing itself is granted by a super
admin, on the company's own record under Platform administration; a note on
this page points there directly if nothing is licensed yet.

Core (this page, Users, and Roles) never appears here — a company able to
switch it off could lock itself out of its own administration.

## Switching a module off

Nothing is deleted. Every record the module owns stays exactly as it is;
switching the module back on restores full access to it, unchanged. Each
toggle shows how many records exist under that module, so you can see the
size of what you're about to hide before you hide it.

## Dependencies

Some modules require another to function (Invoicing requires Accounting, for
example). Before you save, the page shows exactly what your changes will
additionally do:

- Switching a module **on** pulls in everything it requires, automatically.
- Switching a module **off** takes everything that depends on it down with
  it, rather than leaving it half-working.
- Trying to switch on a module whose requirement isn't licensed at all simply
  refuses — it's named in the summary as something that could not be enabled.

## Roles and permissions

Administrator only.
