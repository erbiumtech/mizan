<?php

namespace App\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Is `public/storage` absent on this host?
 *
 * PublicStorageIsNotExposedTest asserts the same thing, but a test runs in CI and this is a property of a
 * *server*: a link created by a host's stock deploy recipe, or left over from before `filesystems.links` was
 * emptied, lives on the one machine the suite never sees. With it in place the web server answers
 * `/storage/tenants/{id}/…` for anybody, ahead of the application and its membership check — which is how
 * four employees' payslips were once public. TenantStorage documents the route that makes the link
 * unnecessary; this says, on the dashboard, whether somebody created it anyway.
 */
class PublicStorageLinkCheck extends Check
{
    protected ?string $path = null;

    /** Where to look; defaults to `public/storage`. Here for the test, which must not touch the real one. */
    public function path(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function run(): Result
    {
        $path = $this->path ?? public_path('storage');

        // is_link() first: file_exists() follows the link and says false for one that dangles, and a
        // dangling link is still a link the next deploy can point somewhere.
        if (! is_link($path) && ! file_exists($path)) {
            return Result::make()
                ->shortSummary('absent')
                ->ok('public/storage does not exist; uploads are served through the access-checked route.');
        }

        return Result::make()
            ->meta(['path' => $path, 'link' => is_link($path)])
            ->shortSummary('exposed')
            ->failed(
                "{$path} exists, which serves every company's uploads straight off the web root without the "
                ."membership check. Remove it (rm -rf {$path}); this application does not use storage:link."
            );
    }
}
