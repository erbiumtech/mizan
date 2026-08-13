<?php

namespace App\Modules\Attendance\Services;

/**
 * What an import did, or would do.
 *
 * Carries `committed` so a caller cannot confuse the two: a preview reporting "412
 * rows accepted" and an import reporting the same sentence is how somebody comes to
 * believe a dry run wrote the month.
 */
class AttendanceImportResult
{
    /**
     * @param  array<int, string>  $errors
     * @param  array<int, array<string, string>>  $preview
     */
    public function __construct(
        public readonly int $accepted = 0,
        public readonly array $errors = [],
        public readonly array $preview = [],
        public readonly bool $committed = false,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function summary(): string
    {
        $verb = $this->committed ? 'Imported' : 'Would import';
        $summary = "{$verb} {$this->accepted} day(s).";

        if ($this->hasErrors()) {
            $summary .= ' '.count($this->errors).' row(s) could not be read.';
        }

        return $summary;
    }
}
