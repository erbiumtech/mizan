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

## Off the box

An archive on the same disk as the database it backs up survives a bad deploy and
not a dead disk. Add a remote disk (S3-compatible works) to
`config/backup.php` → `destination.disks` and `monitor_backups[].disks`. Until
then the backup is a convenience, not a safeguard, and this file should say so.

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
