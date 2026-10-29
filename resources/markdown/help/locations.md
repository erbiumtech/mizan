## What this is

The places the company works from: a head office, a factory, a site office.
An employee belongs to one, and a location carries its own holiday calendar
and, optionally, a geofence for clocking in.

## Adding one <!-- requires: WorkLocationCreate -->

Click **New**. Name it and, if attendance is clocked from a phone, enter the
**latitude**, **longitude** and a **radius** in metres. A clock-in from outside
the radius is flagged or refused depending on the attendance setting.

## Phone, map link and head office

**Phone** is the branch's own number — the one somebody rings when they cannot reach a
person.

**Map link** is a share link from any map, shown as a link and never fetched. It is a
separate thing from the geofence: the geofence is a pair of coordinates the attendance
check uses, and this is something a person taps.

**Head office** marks the branch documents are issued from. Only one branch can hold
it — setting it on another clears it — and a company that has never thought about it
falls back to its first active branch, which for a company with one branch is right.

## Holidays per location

A holiday with a location applies only there; one without applies to every
location. Payroll and leave read the employee's location when they ask whether
a day is a working day.
