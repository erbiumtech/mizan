# Custom domains: Caddy in front of nginx

A company on a plan with `custom_domain` (config/plans.php) sets its own host
from **Subscription → Custom domain**. The application refuses the domain until
its DNS points here, keeps the list of hosts it will answer on
(`App\Support\CustomDomains::trustedHosts()`), and shows that company alone on
that host. What is left is the certificate, and this is the one piece of ops
for it: Caddy issues TLS on demand for any host the application says it owns.

## Setup checklist

1. Install Caddy (`apt install caddy` on Debian/Ubuntu; the EL9 build is in the
   Caddy COPR).
2. Move nginx off 443: change the site's `listen` to `127.0.0.1:8080` and drop
   its `ssl_*` lines. Caddy now owns 80 and 443.
3. Copy `Caddyfile` to `/etc/caddy/Caddyfile`. Replace `app.example.pk` with the
   platform host (`APP_URL`'s host) and `ops@example.pk` with the address Let's
   Encrypt should write to.
4. `systemctl enable --now caddy`, then `systemctl reload caddy` after edits.
5. Check the ask endpoint from the box:
   `curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:8080/domains/check?domain=hr.acme.pk'`
   — 200 for a domain a company has claimed, 404 otherwise. Caddy issues only on 200.

Nothing in `.env` changes. `trustProxies` already trusts loopback, which is
where Caddy's `X-Forwarded-*` headers come from.

## What a customer does

Create a CNAME record `hr.theirs.pk → <platform host>`, wait for it to
propagate, then save the domain on the Subscription page. The first visit to
`https://hr.theirs.pk` takes a few seconds while the certificate is issued; after
that the host behaves like the platform host, showing their company only, with
their letterhead logo on the login page.

## The alternative, recorded

certbot with a `domains:sync` command regenerating nginx's `server_name` list and
reloading it works, and is what `deploy/reverb/README.md` does for one host. It
is one more thing to break per customer, which is why this is Caddy.
