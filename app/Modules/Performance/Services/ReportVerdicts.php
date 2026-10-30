<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\DailyReport;
use App\Support\Ai\ModelUnavailable;
use App\Support\Ai\StructuredModel;

/**
 * One call to the model that already exists — docs/saas-launch-plan.md §8.3.
 *
 * `StructuredModel` turns text into a fixed set of fields against a schema, and
 * that is all this asks of it: a verdict, a summary, up to three questions. The
 * stable half of the prompt (the company's rules, the role, yesterday's report)
 * is the system; today's body is the only volatile part.
 *
 * The model never writes the ledger. A verdict lands in four columns and waits
 * for a manager; `ai.enabled` off, or no key, means no verdict and nothing else.
 */
class ReportVerdicts
{
    public function __construct(private readonly StructuredModel $model) {}

    public function available(): bool
    {
        return (bool) config('ai.enabled') && (config('ai.driver') !== 'claude' || filled(config('ai.claude.api_key')));
    }

    public function assess(DailyReport $report): DailyReport
    {
        if (! $this->available()) {
            return $report;
        }

        try {
            $reply = $this->model->extract($this->system($report), $report->body, $this->schema());
        } catch (ModelUnavailable $e) {
            // Recorded nowhere on the report: a verdict that could not be reached is
            // no verdict, and the manager reads the report as they would have anyway.
            report($e);

            return $report;
        }

        // A reply with no verdict field is no verdict, as above. The `local` driver is the command
        // bar's pattern parser, which fills only the fields it knows — and a missing verdict used to
        // be stamped "thin" on every report.
        if (! array_key_exists('verdict', $reply)) {
            return $report;
        }

        $verdict = in_array($reply['verdict'] ?? null, DailyReport::VERDICTS, true) ? $reply['verdict'] : DailyReport::VERDICT_THIN;

        $report->forceFill([
            'verdict' => $verdict,
            'verdict_summary' => mb_substr(trim((string) ($reply['summary'] ?? '')), 0, 1000),
            'verdict_questions' => array_values(array_slice(array_filter(array_map('strval', (array) ($reply['questions'] ?? []))), 0, 3)),
            'verdict_at' => now(),
            'verdict_model' => (string) (config('ai.driver') === 'claude' ? config('ai.claude.model') : config('ai.driver')),
        ])->save();

        return $report;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['verdict', 'summary', 'questions'],
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => DailyReport::VERDICTS],
                'summary' => ['type' => 'string', 'description' => 'One sentence a manager can read in passing.'],
                'questions' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 3],
            ],
        ];
    }

    /** The cacheable half: rules, role, and yesterday for continuity. */
    public function system(DailyReport $report): string
    {
        $employee = $report->employee;
        $yesterday = DailyReport::query()
            ->where('employee_id', $report->employee_id)
            ->where('report_date', '<', $report->report_date->toDateString())
            ->orderByDesc('report_date')
            ->value('body');

        $rules = (string) setting('performance.daily_report.rules', '');

        return implode("\n\n", array_filter([
            'You read one employee\'s daily work report and answer with three fields. '
            .'"substantive" means it names concrete work done or blocked today; '
            .'"thin" means it is vague, generic or copied from a previous day; '
            .'"off_topic" means it is not about work at all. '
            .'The summary is one sentence for a manager. '
            .'The questions are up to three a manager might ask to confirm the work; none if the report needs none. '
            .'You judge the writing, not the person; you never estimate hours or quality beyond these fields.',
            'Role: '.trim(($employee?->designation ?: 'employee').($employee?->department ? ', '.$employee->department : '')).'.',
            $rules !== '' ? "The company's reporting rules:\n".$rules : null,
            $yesterday ? "Yesterday's report, for continuity:\n".mb_substr($yesterday, 0, 2000) : null,
        ]));
    }
}
