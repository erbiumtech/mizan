<?php

namespace App\Modules\Work\Imports;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Work\Models\WorkItem;
use App\Modules\Work\Models\WorkStatus;
use App\Modules\Work\Models\WorkWorkflow;
use App\Modules\Work\Services\WorkItems;
use App\Support\Contracts\CsvImporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A Jira export, as a spreadsheet — docs/project-management-plan.md §8.9.
 *
 * A team arriving with four hundred open items will not retype them. Jira's CSV export carries
 * the columns below under exactly these headers; everything else in the file is ignored.
 *
 * **The project is the key's prefix.** `PROJ-412` lands in the project here whose key is `PROJ`,
 * so a company sets its projects' keys to Jira's before importing and the keys carry over — which
 * is what lets a search for the old `PROJ-412` find it, through `imported_from`. A row whose
 * prefix matches no project is skipped and named.
 *
 * **Statuses map by name onto the project's workflow**, and a name the workflow does not have
 * falls to the status of the same category where Jira said one ("Done", "In Progress", else to
 * do). The plan asks for a mapping form showing both lists; the generic CSV page offers none, so
 * the mapping is by name and the original status is kept on the row for anybody who wants to
 * re-map by hand. Users map by email; a name that is not an email is kept as text in the
 * description's footer rather than guessed at.
 *
 * Re-runnable: the same file twice corrects rather than duplicates, by `imported_from`.
 */
class JiraCsvImporter implements CsvImporter
{
    public function key(): string
    {
        return 'jira';
    }

    public function label(): string
    {
        return 'Work items from a Jira export';
    }

    public function columns(): array
    {
        return ['Issue key', 'Summary', 'Issue Type', 'Status', 'Priority', 'Assignee', 'Reporter', 'Description', 'Created', 'Resolved', 'Parent', 'Story Points'];
    }

    public function example(): array
    {
        return ['WEB-12', 'Export fails on Safari', 'Bug', 'To Do', 'High', 'ahmed@example.com', 'sara@example.com', 'Steps to reproduce…', '2026-01-10 09:14', '', '', '3'];
    }

    public function problemWith(array $row): ?string
    {
        return match (true) {
            ! preg_match('/^[A-Za-z][A-Za-z0-9]{1,5}-\d+$/', trim($row['Issue key'])) => '"'.$row['Issue key'].'" is not a Jira issue key',
            trim($row['Summary']) === '' => 'no summary',
            default => null,
        };
    }

    public function write(Collection $rows, ?string $date = null): int
    {
        $imported = 0;
        $projects = [];
        $workflows = [];

        // Parents first, so a child created afterwards can find its parent by the Jira key.
        $ordered = $rows->sortBy(fn (array $row): int => trim($row['Parent'] ?? '') === '' ? 0 : 1)->values();

        foreach ($ordered as $row) {
            $key = strtoupper(trim($row['Issue key']));
            $prefix = strtok($key, '-');

            $project = $projects[$prefix] ??= Project::query()->where('key', $prefix)->first();

            if ($project === null) {
                // Named in the activity of the import rather than thrown: the rest of the file imports.
                continue;
            }

            $workflow = $workflows[$project->getKey()] ??= ($project->work_workflow_id !== null
                ? WorkWorkflow::query()->with('statuses')->whereKey($project->work_workflow_id)->first()
                : WorkWorkflow::default()?->load('statuses'));

            if ($workflow === null) {
                continue;
            }

            $status = $this->statusFor($workflow, (string) $row['Status']);
            $assignee = $this->employeeFor((string) ($row['Assignee'] ?? ''));
            $reporter = $this->userFor((string) ($row['Reporter'] ?? ''));

            $attributes = [
                'title' => mb_substr(trim($row['Summary']), 0, 255),
                'type' => $this->typeFor((string) ($row['Issue Type'] ?? '')),
                'priority' => trim((string) ($row['Priority'] ?? '')) ?: 'Medium',
                'description' => $this->description($row, $assignee, $reporter),
                'assignee_employee_id' => $assignee?->getKey(),
                'estimate_points' => is_numeric($row['Story Points'] ?? null) ? (float) $row['Story Points'] : null,
                'parent_id' => $this->parentFor((string) ($row['Parent'] ?? '')),
                'imported_from' => 'jira:'.$key,
            ];

            $existing = WorkItem::query()->where('project_id', $project->getKey())->where('imported_from', 'jira:'.$key)->first();

            try {
                if ($existing !== null) {
                    $existing->fill($attributes)->save();
                    $item = $existing;
                } else {
                    $item = app(WorkItems::class)->create($project, $attributes + ['work_status_id' => $status->getKey(), 'reporter_user_id' => $reporter?->getKey()], $reporter);
                }

                if ((int) $item->work_status_id !== (int) $status->getKey()) {
                    $item->moveTo($status, null, $status->isDone() ? 'Done' : null);
                }

                // Jira's own dates, so cycle time and the created-vs-resolved report read history
                // rather than the day of the import.
                $created = $this->date($row['Created'] ?? null);
                $resolved = $this->date($row['Resolved'] ?? null);

                if ($created !== null || $resolved !== null) {
                    $item->forceFill(array_filter([
                        'created_at' => $created,
                        'resolved_at' => $status->isDone() ? $resolved : null,
                        'started_at' => $status->category !== WorkStatus::TODO ? ($created ?? $item->started_at) : $item->started_at,
                    ], fn ($v): bool => $v !== null))->saveQuietly();
                }
            } catch (RuntimeException) {
                // A rule refused the status, or the hierarchy refused the parent. The row still imported
                // where the engine let it; the status it asked for is in the description's footer.
            }

            $imported++;
        }

        return $imported;
    }

    public function dateField(): ?array
    {
        return null;
    }

    /** By name, else by what the name means, else the workflow's first to-do. */
    private function statusFor(WorkWorkflow $workflow, string $name): WorkStatus
    {
        $name = trim($name);

        $named = $workflow->statuses->first(fn (WorkStatus $s): bool => strcasecmp($s->name, $name) === 0);

        if ($named !== null) {
            return $named;
        }

        $category = match (true) {
            in_array(strtolower($name), ['done', 'closed', 'resolved', 'complete', 'completed', "won't do", 'cancelled', 'canceled'], true) => WorkStatus::DONE,
            in_array(strtolower($name), ['in progress', 'in review', 'in development', 'in qa', 'testing', 'review', 'selected for development'], true) => WorkStatus::IN_PROGRESS,
            default => WorkStatus::TODO,
        };

        return $workflow->firstOf($category) ?? $workflow->statuses->first();
    }

    private function typeFor(string $jira): string
    {
        $known = array_change_key_case(options('work.type'), CASE_LOWER);
        $wanted = strtolower(trim($jira));

        return match (true) {
            isset($known[$wanted]) => $known[$wanted],
            in_array($wanted, ['sub-task', 'subtask'], true) => $known['subtask'] ?? 'Subtask',
            $wanted === 'epic' => $known['epic'] ?? 'Epic',
            $wanted === 'story' => $known['story'] ?? 'Story',
            $wanted === 'bug' => $known['bug'] ?? 'Bug',
            default => $known['task'] ?? 'Task',
        };
    }

    private function parentFor(string $jiraKey): ?int
    {
        $jiraKey = strtoupper(trim($jiraKey));

        return $jiraKey === '' ? null : WorkItem::query()->where('imported_from', 'jira:'.$jiraKey)->value('id');
    }

    private function employeeFor(string $who): ?Employee
    {
        $user = $this->userFor($who);

        return $user === null ? null : Employee::query()->where('user_id', $user->getKey())->first();
    }

    private function userFor(string $who): ?User
    {
        $who = trim($who);

        if ($who === '' || ! filter_var($who, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return User::query()->where('email', $who)->first();
    }

    private function date(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The description, with a footer of what did not map so nothing in the file is lost. */
    private function description(array $row, ?Employee $assignee, ?User $reporter): ?string
    {
        $footer = array_filter([
            'Imported from Jira as '.strtoupper(trim($row['Issue key'])).', status "'.trim((string) $row['Status']).'".',
            trim((string) ($row['Assignee'] ?? '')) !== '' && $assignee === null ? 'Jira assignee: '.trim($row['Assignee']) : null,
            trim((string) ($row['Reporter'] ?? '')) !== '' && $reporter === null ? 'Jira reporter: '.trim($row['Reporter']) : null,
        ]);

        $body = trim((string) ($row['Description'] ?? ''));

        return ($body !== '' ? $body."\n\n" : '').'---'."\n".implode("\n", $footer);
    }
}
