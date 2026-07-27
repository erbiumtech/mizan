<?php

namespace App\Support;

use App\Http\Controllers\TenantFileController;
use App\Models\Company;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The single source of truth for where a company's uploads live on disk and how
 * they are addressed over HTTP.
 *
 * Files are never served straight off the filesystem: `public/storage` (the
 * `storage:link` symlink) is not available on every host, and — more
 * importantly — exposing `storage/app/public` wholesale would let anyone who
 * knows a path fetch another company's payslips. Everything goes through
 * {@see TenantFileController} instead, which checks
 * membership first.
 */
class TenantStorage
{
    /** URL prefix of the streaming route; also the `public` disk's URL root. */
    public const URL_PREFIX = 'files';

    /** Directory, relative to the storage root, holding one company's files. */
    public static function suffix(Company|int|string $company): string
    {
        return 'tenants/'.($company instanceof Company ? $company->getKey() : $company);
    }

    /** Absolute path to a company's public-disk root. */
    public static function publicRoot(Company|int|string $company): string
    {
        return storage_path('app/public/'.static::suffix($company));
    }

    /** Absolute path to a company's private-disk root. */
    public static function privateRoot(Company|int|string $company): string
    {
        return storage_path('app/private/'.static::suffix($company));
    }

    /**
     * The URL root handed to the `public` disk, so every existing
     * `Storage::disk('public')->url($path)` call resolves to the streaming
     * route without the call sites needing to know about it.
     *
     * Relative on purpose: it resolves against the current host rather than a
     * possibly-mismatched APP_URL.
     */
    public static function urlRoot(Company|int|string $company): string
    {
        return '/'.static::URL_PREFIX.'/'.($company instanceof Company ? $company->getKey() : $company);
    }

    /** A read-only filesystem rooted at one company's public files. */
    public static function publicDisk(Company|int|string $company): Filesystem
    {
        return Storage::build([
            'driver' => 'local',
            'root' => static::publicRoot($company),
            'throw' => false,
        ]);
    }

    /**
     * Whether a user-supplied relative path is safe to resolve. Traversal is
     * the only real concern — the route pattern allows slashes so that nested
     * paths like `payslips/2026/x.pdf` work.
     */
    public static function isSafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) {
            return false;
        }

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
