# Backups, and the restore drill

Two archives a night, both written to the `local` disk under `storage/app/private/`:

| when  | command           | what it holds                                                    |
|-------|-------------------|------------------------------------------------------------------|
| 01:00 | `backup:clean`    | prunes old archives by the strategy in `config/backup.php`       |
| 01:30 | `backup:run`      | the **landlord** database and the uploads — companies, users, roles, files |
| 02:00 | `backup:tenants`  | **one archive per company's database**                           |

The health check **Backups** watches the landlord archive's age. A stale *tenant*
archive is not monitored — `backup:tenants` exiting non-zero is the only signal, so
whatever runs the scheduler must surface its failures (mail on failure, at least).

## The archives are encrypted

`BACKUP_ARCHIVE_PASSWORD` in the production `.env` encrypts every archive with
AES-256 (both `backup:run` and `backup:tenants`; enabled 2026-09-29 — archives
from before that date are plain). **A restore is impossible without it, so it must
exist somewhere that survives the server**: the production `.env` holds the live
copy, and whoever operates this keeps another in a password manager. The classic
`unzip` cannot open AES zips whatever password you give it — restore with PHP's
`ZipArchive::setPassword()` or `7z x -p`.

## Off the box

An archive on the same disk as the database it backs up survives a bad deploy and
not a dead disk. The destination is env-driven now, so an off-box copy is a `.env`
change rather than a code one:

```
BACKUP_DISKS=local,s3
BACKUP_CONTINUE_ON_FAILURE=true      # a blip to the remote must not lose the local archive
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_BUCKET=…
AWS_ENDPOINT=https://…              # any S3-compatible host: Backblaze B2, Wasabi, Spaces, MinIO
AWS_DEFAULT_REGION=…
```

Then `config:cache` + reload (config is cached on deploy). Both `backup:run` and
`backup:tenants` write to every disk in `BACKUP_DISKS`, and the health check
watches each disk's age — so a remote that silently stops receiving archives goes
red rather than unnoticed. Until a remote disk is set, the backup is a convenience,
not a safeguard, and this file says so.

The archives are AES-encrypted (BACKUP_ARCHIVE_PASSWORD), so the copy on remote
storage is unreadable without the password kept off the server — which is what
makes an off-box copy safe to hold with a third party.

## The restore drill — do it before you need it

A backup nobody has restored is a hope. Once a quarter, on a scratch server:

1. `php artisan backup:list` on the production box; copy the newest landlord
   archive **and** the same night's tenant archive for one company to the scratch
   box. They must be from the same night: the landlord holds the `companies` row
   that names the tenant database and the `users` and roles that can open it, so a
   tenant archive on its own restores a database nothing can reach
   (`App\Backup\TenantBackup` says this on every run).
2. Unzip both. Each holds `db-dumps/<connection>-<database>.sql` and, for the
   landlord, the uploads under `storage/`.
3. Restore the landlord dump into an empty database, then the tenant dump into an
   empty database **with the name the `companies` row expects** — check
   `companies.database` in the restored landlord.
4. Point a scratch `.env` at both, run `php artisan migrate --pretend` (nothing should
   be pending; if it is, the archive predates a release and the deploy notes apply),
   then `php artisan health:check`.
5. Log in as an administrator of that company. Open a payslip, a journal entry and an
   employee record. If all three open, write the date and the archive names here:

| drilled on | landlord archive | tenant archive | by |
|------------|------------------|----------------|----|
|            |                  |                |    |

An empty table is the finding.
