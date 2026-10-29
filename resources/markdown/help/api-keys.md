## What this is

Keys that let a script, or another system, act through this application's API as
**you**.

They are yours, not the company's. A key carries your permissions wherever it goes,
so there is no way to make one "for the company" that is not really a key acting as
one person. When somebody's access is revoked, their keys go with it.

## Making one

**New key**, then:

- **What it is for** — name it after the thing that will use it, not after yourself.
  In a year this is all anybody will have to decide whether it is still needed.
- **Scopes** — every key can use the API, and there is nothing narrower to give yet.
- **Good for** — 30 days, 90, a year, or until revoked. A key that never expires is a
  key nobody ever revokes.

The key is shown **once**. There is nowhere to look it up afterwards, because what is
stored is a hash — this screen cannot leak what it does not have. Lost it? Revoke it
and make another.

## Using one

```
Authorization: Bearer <key>
```

If you belong to more than one company, also send the company:

```
X-Company: your-company-slug
```

The API answers under `/api/v1`. A request with no key, an expired key, or a key
whose scope was removed gets a 401 or a 403, never a partial answer.

## Last used

The column most worth looking at. A key nobody has used in a year is a key nobody will
miss; a key used this morning is one to ask about before revoking. It updates on every
request the key makes.

## Revoking

Immediate, and it cannot be undone. Anything using that key stops working at once, so
make the replacement first if something depends on it.

## Roles and permissions

No permission. Everybody signed in can manage their own keys, and nobody can see
anybody else's — not even an Administrator, because there is nothing to see: the table
holds hashes.
