<?php

namespace App\Support;

use App\Backup\TenantBackup;
use App\Modules\Core\Models\Company;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use SplFileInfo;
use ZipArchive;

/**
 * A company's data, in a form it can take elsewhere — docs/saas-launch-plan.md §9.
 *
 * One archive: the tenant database as a plain SQL dump, every file the company
 * uploaded (public and private), and a README saying what is what. Written into
 * the company's own public storage under `exports/`, so the ordinary tenant file
 * route streams it — membership first, then the Administrator-only rule Core
 * registers for that prefix — and it needs no signed URL to outlive.
 *
 * The dump comes from the same throwaway connection the nightly backup uses
 * (TenantBackup::connectionFor), so it is the whole database and never another
 * company's. Unencrypted, unlike the backup: this archive is for the customer,
 * and a password they have to ask us for is a promise with a step in it.
 *
 * This is the 90-day promise, and the precondition tenant:drop checks for.
 */
class TenantExport
{
    public const PREFIX = 'exports/';

    public function __construct(private readonly TenantBackup $backup) {}

    /**
     * Build the archive and return its path relative to the company's public root.
     */
    public function run(Company $company): string
    {
        $connection = $this->backup->connectionFor($company);
        $root = TenantStorage::publicRoot($company);
        File::ensureDirectoryExists($root.'/'.rtrim(self::PREFIX, '/'));

        $stamp = Carbon::now()->format('Y-m-d-His');
        $relative = self::PREFIX.$company->slug.'-'.$stamp.'.zip';
        $archive = $root.'/'.$relative;
        $dump = sys_get_temp_dir().'/tenant-export-'.$company->getKey().'-'.$stamp.'.sql';

        try {
            DbDumperFactory::createFromConnection($connection)->dumpToFile($dump);

            $zip = new ZipArchive;

            if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Could not create the export archive at {$archive}.");
            }

            $zip->addFile($dump, 'database.sql');
            $zip->addFromString('README.txt', $this->readme($company, $stamp));

            // The company's own uploads, public and private — minus earlier exports,
            // or every archive would contain every archive before it.
            $this->addDirectory($zip, $root, 'files/public', skip: rtrim(self::PREFIX, '/'));
            $this->addDirectory($zip, TenantStorage::privateRoot($company), 'files/private');

            $zip->close();
        } finally {
            File::delete($dump);
        }

        return $relative;
    }

    /**
     * The newest archive, or null when the company has never been exported.
     *
     * @return array{path: string, created_at: Carbon, bytes: int}|null
     */
    public function latest(Company $company): ?array
    {
        $directory = TenantStorage::publicRoot($company).'/'.rtrim(self::PREFIX, '/');

        if (! File::isDirectory($directory)) {
            return null;
        }

        $newest = collect(File::files($directory))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'zip')
            ->sortByDesc(fn (SplFileInfo $file): int => $file->getMTime())
            ->first();

        return $newest === null ? null : [
            'path' => self::PREFIX.$newest->getFilename(),
            'created_at' => Carbon::createFromTimestamp($newest->getMTime()),
            'bytes' => $newest->getSize(),
        ];
    }

    /** The download URL the tenant file route serves, absolute so it survives an email. */
    public function url(Company $company, string $path): string
    {
        return url(TenantStorage::urlRoot($company).'/'.$path.'?download=1');
    }

    private function addDirectory(ZipArchive $zip, string $directory, string $prefix, ?string $skip = null): void
    {
        if (! File::isDirectory($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($directory))), '/');

            if ($skip !== null && str_starts_with($relative, $skip.'/')) {
                continue;
            }

            $zip->addFile($file->getPathname(), $prefix.'/'.$relative);
        }
    }

    private function readme(Company $company, string $stamp): string
    {
        return implode("\n", [
            "Data export for {$company->name} ({$company->slug}), made {$stamp}.",
            '',
            'database.sql   The whole company database as a plain SQL dump (mysqldump or sqlite3 .dump).',
            '               Every table: employees, payslips, the ledger, invoices, everything.',
            'files/public   Every file uploaded to the company: payslip PDFs, documents, receipts, logos.',
            'files/private  Files the application keeps out of the browser: statement imports and the like.',
            '',
            'Nothing is encrypted. Keep the archive where you keep your books.',
            '',
        ]);
    }
}
