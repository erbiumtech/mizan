<?php

namespace App\Modules\ConstructionField\Services;

use App\Modules\Construction\Models\Job;
use App\Modules\ConstructionField\Models\ProgrammeActivity;
use App\Modules\ConstructionField\Models\ProgrammeActivityPredecessor;
use App\Modules\ConstructionField\Support\ProgrammeImportSummary;
use App\Support\TenantTransaction;
use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

/**
 * Importing a programme — `docs/construction-management-plan.md` §13.
 *
 * §13 asks for exactly this and nothing more: "import Primavera XER and P6 XML and MS Project XML into the same tables,
 * keyed on an `external_id`". Three formats, one set of tables, no scheduling.
 *
 * **The most consequential decision here is what an import is allowed to overwrite.** A contractor sends a P6 update
 * every month. If each one rewrote the baseline, then every re-programme would silently retire the entitlement it was
 * caused by — which is the exact failure §13 keeps two pairs of dates apart to prevent. So:
 *
 *  - **`MODE_UPDATE` (the default) never touches a baseline.** It writes planned dates, actuals, percent complete,
 *    durations, float and criticality. This is the monthly progress file.
 *  - **`MODE_BASELINE` writes the baseline as well**, and records `baseline_revision` against every row it touched. This
 *    is the accepted programme, imported deliberately, once per revision.
 *
 * The summary says which of the two happened in words, every time, because "500 activities imported" with that left
 * unsaid is the silence this whole section is written against.
 *
 * **Float and lag arrive in hours and are stored in days.** P6 counts both in hours against an activity's calendar, so
 * the conversion needs a working-day length — `config('construction.programme.hours_per_day')`. It is configuration
 * rather than a constant because a job on a ten-hour day would otherwise have its float overstated by a quarter, and
 * nothing in the file says which it is.
 *
 * **A row this importer cannot use is skipped and counted, never guessed at.** The failure mode of an importer is not
 * throwing; it is importing four hundred activities out of five hundred and reporting success.
 */
class ProgrammeImport
{
    public const MODE_UPDATE = 'update';

    public const MODE_BASELINE = 'baseline';

    /** The XER relationship codes, and the two spellings P6 XML uses for the same four links. */
    private const RELATIONSHIPS = [
        'PR_FS' => 'fs', 'PR_SS' => 'ss', 'PR_FF' => 'ff', 'PR_SF' => 'sf',
        'Finish to Start' => 'fs', 'Start to Start' => 'ss',
        'Finish to Finish' => 'ff', 'Start to Finish' => 'sf',
        'FS' => 'fs', 'SS' => 'ss', 'FF' => 'ff', 'SF' => 'sf',
    ];

    /**
     * Import a file, guessing its format from the content rather than the extension.
     *
     * The extension is what a mail client decided to call it; the first bytes are what the tool wrote. An XER always
     * opens `ERMHDR`, and the two XML dialects are told apart by whether the root document mentions MS Project's
     * namespace or P6's `APIBusinessObjects`.
     *
     * @param  string  $mode  self::MODE_UPDATE leaves the baseline alone; self::MODE_BASELINE sets it.
     */
    public function import(
        Job $job,
        string $contents,
        string $mode = self::MODE_UPDATE,
        ?string $baselineRevision = null,
    ): ProgrammeImportSummary {
        $trimmed = ltrim($contents);

        if ($trimmed === '') {
            throw new InvalidArgumentException('That file is empty.');
        }

        if (str_starts_with($trimmed, 'ERMHDR')) {
            return $this->importParsed($job, ProgrammeActivity::SOURCE_XER, $this->parseXer($contents), $mode, $baselineRevision);
        }

        if (str_starts_with($trimmed, '<')) {
            [$source, $parsed] = $this->parseXml($trimmed);

            return $this->importParsed($job, $source, $parsed, $mode, $baselineRevision);
        }

        throw new InvalidArgumentException(
            'That is not a programme this application reads. It takes a Primavera XER, a P6 XML export or an MS '
            .'Project XML export — the three §13 names. A .mpp or a .pp is the tool\'s own binary format and has to be '
            .'exported first.'
        );
    }

    /**
     * Write what a parser produced.
     *
     * @param  array{activities: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>}  $parsed
     */
    private function importParsed(
        Job $job,
        string $source,
        array $parsed,
        string $mode,
        ?string $baselineRevision,
    ): ProgrammeImportSummary {
        if ($parsed['activities'] === []) {
            throw new InvalidArgumentException(
                'No activities were found in that file. An export filtered down to a single WBS branch, or one that '
                .'exported only relationships, will look like this.'
            );
        }

        if (! in_array($mode, [self::MODE_UPDATE, self::MODE_BASELINE], true)) {
            throw new InvalidArgumentException("{$mode} is not an import mode.");
        }

        $writesBaseline = $mode === self::MODE_BASELINE;
        $created = 0;
        $updated = 0;
        $links = 0;
        $warnings = [];

        return TenantTransaction::run(function () use (
            $job, $source, $parsed, $writesBaseline, $baselineRevision,
            &$created, &$updated, &$links, &$warnings
        ): ProgrammeImportSummary {
            /** @var array<string, int> $byExternalId */
            $byExternalId = [];

            foreach ($parsed['activities'] as $row) {
                if (blank($row['external_id'] ?? null) || blank($row['code'] ?? null)) {
                    $warnings[] = 'An activity with no id was skipped.';

                    continue;
                }

                $existing = ProgrammeActivity::query()
                    ->where('job_id', $job->getKey())
                    ->where('source', $source)
                    ->where('external_id', $row['external_id'])
                    ->first();

                $attributes = [
                    'code' => $row['code'],
                    'name' => $row['name'] ?: $row['code'],
                    'activity_type' => $row['activity_type'] ?? ProgrammeActivity::TYPE_TASK,
                    'planned_start' => $row['planned_start'] ?? null,
                    'planned_finish' => $row['planned_finish'] ?? null,
                    'actual_start' => $row['actual_start'] ?? null,
                    'actual_finish' => $row['actual_finish'] ?? null,
                    'percent_complete' => $row['percent_complete'] ?? 0,
                    'original_duration_days' => $row['original_duration_days'] ?? null,
                    'remaining_duration_days' => $row['remaining_duration_days'] ?? null,
                    // Imported, never derived — §13, and the reason this importer exists at all.
                    'total_float_days' => $row['total_float_days'] ?? null,
                    'is_critical' => $row['is_critical'] ?? false,
                    'data_date' => $row['data_date'] ?? null,
                ];

                /*
                 * **The baseline is written only when somebody said so.**
                 *
                 * On a first import there is nothing to protect, so the accepted dates are taken from the file — a job
                 * whose very first programme left the baseline empty would have nothing to measure entitlement against.
                 * After that, an update import leaves it alone however different the file's dates are.
                 */
                if ($writesBaseline || $existing === null) {
                    $attributes['baseline_start'] = $row['planned_start'] ?? null;
                    $attributes['baseline_finish'] = $row['planned_finish'] ?? null;
                    $attributes['baseline_revision'] = $baselineRevision;
                }

                if ($existing === null) {
                    $activity = ProgrammeActivity::create($attributes + [
                        'job_id' => $job->getKey(),
                        'source' => $source,
                        'external_id' => $row['external_id'],
                    ]);
                    $created++;
                } else {
                    $existing->update($attributes);
                    $activity = $existing;
                    $updated++;
                }

                $byExternalId[(string) $row['external_id']] = $activity->getKey();
            }

            /*
             * The parents, in a second pass.
             *
             * A file lists activities in whatever order the tool wrote them, so a parent can appear after its child —
             * resolving on the way past would drop half the hierarchy.
             */
            foreach ($parsed['activities'] as $row) {
                $parent = $row['parent_external_id'] ?? null;

                if (blank($parent) || ! isset($byExternalId[(string) $row['external_id']], $byExternalId[(string) $parent])) {
                    continue;
                }

                ProgrammeActivity::query()
                    ->whereKey($byExternalId[(string) $row['external_id']])
                    ->update(['parent_id' => $byExternalId[(string) $parent]]);
            }

            foreach ($parsed['links'] as $link) {
                $to = $byExternalId[(string) ($link['activity_external_id'] ?? '')] ?? null;
                $from = $byExternalId[(string) ($link['predecessor_external_id'] ?? '')] ?? null;

                if ($to === null || $from === null || $to === $from) {
                    // Named rather than dropped: an export filtered to one WBS branch legitimately references
                    // activities outside it, and a reader needs to know the network here is partial.
                    $warnings[] = 'A relationship naming an activity outside this file was skipped ('
                        .($link['predecessor_external_id'] ?? '?').' → '.($link['activity_external_id'] ?? '?').').';

                    continue;
                }

                ProgrammeActivityPredecessor::query()->updateOrCreate(
                    [
                        'activity_id' => $to,
                        'predecessor_activity_id' => $from,
                        'relationship' => $link['relationship'] ?? 'fs',
                    ],
                    ['lag_days' => $link['lag_days'] ?? 0],
                );

                $links++;
            }

            return new ProgrammeImportSummary(
                source: $source,
                created: $created,
                updated: $updated,
                links: $links,
                baselineWritten: $writesBaseline,
                warnings: array_values(array_unique($warnings)),
            );
        });
    }

    // ------------------------------------------------------------------ XER

    /**
     * Primavera's XER: a tab-delimited dump of its own tables.
     *
     * `%T` names a table, `%F` its columns, `%R` a row. Read by column *name* rather than position, because the column
     * set differs between P6 versions and reading by index is how an importer silently puts a date in a float column.
     *
     * @return array{activities: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>}
     */
    private function parseXer(string $contents): array
    {
        $activities = [];
        $links = [];
        $wbsNames = [];

        $table = null;
        $fields = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $cells = explode("\t", $line);
            $tag = array_shift($cells);

            if ($tag === '%T') {
                $table = $cells[0] ?? null;
                $fields = [];

                continue;
            }

            if ($tag === '%F') {
                $fields = $cells;

                continue;
            }

            if ($tag !== '%R' || $fields === []) {
                continue;
            }

            $row = [];

            foreach ($fields as $i => $field) {
                $row[$field] = $cells[$i] ?? null;
            }

            if ($table === 'PROJWBS') {
                $wbsNames[(string) ($row['wbs_id'] ?? '')] = $row['wbs_short_name'] ?? null;

                continue;
            }

            if ($table === 'TASK') {
                $activities[] = [
                    'external_id' => $row['task_id'] ?? null,
                    'code' => $row['task_code'] ?? $row['task_id'] ?? null,
                    'name' => $row['task_name'] ?? '',
                    'activity_type' => $this->xerActivityType($row['task_type'] ?? null),
                    'planned_start' => $this->date($row['target_start_date'] ?? null),
                    'planned_finish' => $this->date($row['target_end_date'] ?? null),
                    'actual_start' => $this->date($row['act_start_date'] ?? null),
                    'actual_finish' => $this->date($row['act_end_date'] ?? null),
                    'percent_complete' => $this->percent($row['phys_complete_pct'] ?? null),
                    'original_duration_days' => $this->hoursToDays($row['target_drtn_hr_cnt'] ?? null),
                    'remaining_duration_days' => $this->hoursToDays($row['remain_drtn_hr_cnt'] ?? null),
                    'total_float_days' => $this->hoursToDays($row['total_float_hr_cnt'] ?? null, signed: true),
                    // P6 marks the longest path rather than "critical", which is the same thing said carefully.
                    'is_critical' => in_array(strtoupper((string) ($row['driving_path_flag'] ?? '')), ['Y', 'TRUE', '1'], true),
                ];

                continue;
            }

            if ($table === 'TASKPRED') {
                $links[] = [
                    'activity_external_id' => $row['task_id'] ?? null,
                    'predecessor_external_id' => $row['pred_task_id'] ?? null,
                    'relationship' => self::RELATIONSHIPS[$row['pred_type'] ?? ''] ?? 'fs',
                    'lag_days' => $this->hoursToDays($row['lag_hr_cnt'] ?? null, signed: true) ?? 0,
                ];
            }
        }

        return ['activities' => $activities, 'links' => $links];
    }

    private function xerActivityType(?string $type): string
    {
        return match ($type) {
            'TT_Mile', 'TT_StartMile' => ProgrammeActivity::TYPE_START_MILESTONE,
            'TT_FinMile' => ProgrammeActivity::TYPE_FINISH_MILESTONE,
            'TT_LOE' => 'level_of_effort',
            'TT_WBS' => 'wbs_summary',
            default => ProgrammeActivity::TYPE_TASK,
        };
    }

    // ------------------------------------------------------------------ XML

    /**
     * P6 XML and MS Project XML, told apart by what the document says about itself.
     *
     * @return array{0: string, 1: array{activities: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>}}
     */
    private function parseXml(string $contents): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            // No entity loading: a programme file arrives by email from another company, and an XML importer that
            // resolves external entities is a file-read primitive handed to whoever sent it.
            $xml = simplexml_load_string($contents, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
        } catch (Throwable) {
            $xml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new InvalidArgumentException('That XML could not be read. Re-export it and try again.');
        }

        $root = $xml->getName();

        if ($root === 'Project' && isset($xml->Tasks)) {
            return [ProgrammeActivity::SOURCE_MSP, $this->parseMsProject($xml)];
        }

        return [ProgrammeActivity::SOURCE_P6_XML, $this->parseP6Xml($xml)];
    }

    /**
     * P6's own XML, which nests activities and relationships under `Project`.
     *
     * @return array{activities: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>}
     */
    private function parseP6Xml(\SimpleXMLElement $xml): array
    {
        $activities = [];
        $links = [];

        foreach ($xml->xpath('//Activity') ?: [] as $node) {
            $activities[] = [
                'external_id' => (string) ($node->ObjectId ?? $node->Id ?? ''),
                'code' => (string) ($node->Id ?? $node->ObjectId ?? ''),
                'name' => (string) ($node->Name ?? ''),
                'activity_type' => $this->p6ActivityType((string) ($node->Type ?? '')),
                'planned_start' => $this->date((string) ($node->PlannedStartDate ?? $node->StartDate ?? '')),
                'planned_finish' => $this->date((string) ($node->PlannedFinishDate ?? $node->FinishDate ?? '')),
                'actual_start' => $this->date((string) ($node->ActualStartDate ?? '')),
                'actual_finish' => $this->date((string) ($node->ActualFinishDate ?? '')),
                'percent_complete' => $this->percent((string) ($node->PercentComplete ?? '')),
                'original_duration_days' => $this->hoursToDays((string) ($node->PlannedDuration ?? '')),
                'remaining_duration_days' => $this->hoursToDays((string) ($node->RemainingDuration ?? '')),
                'total_float_days' => $this->hoursToDays((string) ($node->TotalFloat ?? ''), signed: true),
                'is_critical' => filter_var((string) ($node->LongestPath ?? 'false'), FILTER_VALIDATE_BOOLEAN),
                'parent_external_id' => (string) ($node->WBSObjectId ?? '') ?: null,
            ];
        }

        foreach ($xml->xpath('//Relationship') ?: [] as $node) {
            $links[] = [
                'activity_external_id' => (string) ($node->SuccessorActivityObjectId ?? ''),
                'predecessor_external_id' => (string) ($node->PredecessorActivityObjectId ?? ''),
                'relationship' => self::RELATIONSHIPS[(string) ($node->Type ?? '')] ?? 'fs',
                'lag_days' => $this->hoursToDays((string) ($node->Lag ?? ''), signed: true) ?? 0,
            ];
        }

        return ['activities' => $activities, 'links' => $links];
    }

    private function p6ActivityType(string $type): string
    {
        return match (true) {
            str_contains($type, 'Start Milestone') => ProgrammeActivity::TYPE_START_MILESTONE,
            str_contains($type, 'Finish Milestone') => ProgrammeActivity::TYPE_FINISH_MILESTONE,
            str_contains($type, 'Level of Effort') => 'level_of_effort',
            str_contains($type, 'WBS Summary') => 'wbs_summary',
            default => ProgrammeActivity::TYPE_TASK,
        };
    }

    /**
     * MS Project's XML, whose predecessors are nested inside each task rather than listed separately.
     *
     * **Its durations are ISO 8601 periods** (`PT80H0M0S`), and its float is in *tenths of a minute* — a unit nothing
     * else in this application uses and which is silently a factor of 4,800 out if taken as hours.
     *
     * @return array{activities: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>}
     */
    private function parseMsProject(\SimpleXMLElement $xml): array
    {
        $activities = [];
        $links = [];

        foreach ($xml->Tasks->Task ?? [] as $node) {
            $uid = (string) ($node->UID ?? '');

            // MS Project's UID 0 is the project summary row, not work.
            if ($uid === '' || $uid === '0') {
                continue;
            }

            $activities[] = [
                'external_id' => $uid,
                'code' => (string) ($node->ID ?? $uid),
                'name' => (string) ($node->Name ?? ''),
                'activity_type' => filter_var((string) ($node->Milestone ?? '0'), FILTER_VALIDATE_BOOLEAN)
                    ? ProgrammeActivity::TYPE_FINISH_MILESTONE
                    : ProgrammeActivity::TYPE_TASK,
                'planned_start' => $this->date((string) ($node->Start ?? '')),
                'planned_finish' => $this->date((string) ($node->Finish ?? '')),
                'actual_start' => $this->date((string) ($node->ActualStart ?? '')),
                'actual_finish' => $this->date((string) ($node->ActualFinish ?? '')),
                'percent_complete' => $this->percent((string) ($node->PercentComplete ?? '')),
                'original_duration_days' => $this->isoPeriodToDays((string) ($node->Duration ?? '')),
                'remaining_duration_days' => $this->isoPeriodToDays((string) ($node->RemainingDuration ?? '')),
                // Tenths of a minute, per the MSPDI schema.
                'total_float_days' => $this->tenthsOfMinuteToDays((string) ($node->TotalSlack ?? '')),
                'is_critical' => filter_var((string) ($node->Critical ?? '0'), FILTER_VALIDATE_BOOLEAN),
            ];

            foreach ($node->PredecessorLink ?? [] as $link) {
                $links[] = [
                    'activity_external_id' => $uid,
                    'predecessor_external_id' => (string) ($link->PredecessorUID ?? ''),
                    'relationship' => $this->mspLinkType((string) ($link->Type ?? '1')),
                    'lag_days' => $this->tenthsOfMinuteToDays((string) ($link->LinkLag ?? '0')) ?? 0,
                ];
            }
        }

        return ['activities' => $activities, 'links' => $links];
    }

    /** MSPDI numbers its link types: 0 FF, 1 FS, 2 SF, 3 SS. */
    private function mspLinkType(string $type): string
    {
        return match ($type) {
            '0' => 'ff',
            '2' => 'sf',
            '3' => 'ss',
            default => 'fs',
        };
    }

    // ------------------------------------------------------------------ units

    /**
     * A date, or null.
     *
     * Null rather than today on anything unparseable, because a guessed date on a programme is a guessed entitlement.
     */
    private function date(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function percent(?string $value): float
    {
        if (blank($value)) {
            return 0.0;
        }

        $percent = (float) $value;

        // P6 writes a fraction in some exports and a percentage in others. Above 1 it can only be a percentage;
        // at or below 1 it is ambiguous, and a fraction is the commoner export — so 0.4 becomes 40.
        $percent = $percent <= 1.0 && $percent > 0.0 ? $percent * 100 : $percent;

        return round(max(0.0, min(100.0, $percent)), 2);
    }

    /**
     * P6's hours into days.
     *
     * The working-day length is configuration, not a constant: a job on a ten-hour day would otherwise have its float
     * overstated by a quarter, and nothing in the file says which it is.
     */
    private function hoursToDays(?string $value, bool $signed = false): ?int
    {
        if (blank($value) || ! is_numeric($value)) {
            return null;
        }

        $hours = (float) $value;
        $perDay = max(1.0, (float) config('construction.programme.hours_per_day', 8));
        $days = $hours / $perDay;

        return (int) round($signed ? $days : max(0.0, $days));
    }

    /** `PT80H0M0S` into days, using the same working-day length. */
    private function isoPeriodToDays(?string $value): ?int
    {
        if (blank($value) || ! preg_match('/^PT?(?:(\d+)H)?(?:(\d+)M)?/', $value, $m)) {
            return null;
        }

        $hours = (float) ($m[1] ?? 0) + ((float) ($m[2] ?? 0) / 60);

        return $hours <= 0.0 ? 0 : $this->hoursToDays((string) $hours);
    }

    /** MS Project's tenths of a minute into days — a factor of 4,800 if mistaken for hours. */
    private function tenthsOfMinuteToDays(?string $value): ?int
    {
        if (blank($value) || ! is_numeric($value)) {
            return null;
        }

        return $this->hoursToDays((string) ((float) $value / 600), signed: true);
    }
}
