<?php

namespace App\Modules\Support\Reporting;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketCategory;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;

/**
 * Tickets — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **What this subject can answer and what it deliberately cannot.** Counts, ages and breakdowns by category,
 * priority, channel and assignee: all of those are columns here. What it cannot answer is SLA compliance,
 * because that is not a column — it is a comparison between a ticket's response and resolution times and its
 * *category's* SLA minutes, which is a rule `SupportReports` applies. Offering a derived `breached` flag would
 * be putting that rule in two places, and the version in the builder would be the one that stopped agreeing.
 * So the SLA report stays coded and this subject offers the timestamps it is computed from.
 *
 * **Three timestamps, one period.** Opened is the period, because that is when a ticket enters a month's
 * figures. Resolved and closed are their own columns and filterable, since "what did we close in March"
 * is a different and equally reasonable question — and one whose answer differs from "what was opened in
 * March" by exactly the tickets that make support hard.
 *
 * **`reopened_count` is the column worth knowing about.** A ticket resolved three times is a ticket that was
 * never fixed, and it is the one figure in this subject that a queue count hides completely.
 */
class TicketDataset extends Dataset
{
    public static function label(): string
    {
        return 'Tickets';
    }

    public static function description(): string
    {
        return 'One support ticket: who raised it, who has it, and the times it moved.';
    }

    public static function model(): string
    {
        return Ticket::class;
    }

    public static function permission(): string
    {
        return 'TicketView';
    }

    public static function periodColumn(): ?string
    {
        return 'opened_at';
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::make('number', 'Number', groupable: false),
            DatasetColumn::make('subject', 'Subject', groupable: false),
            DatasetColumn::make('status', 'Status'),
            DatasetColumn::make('priority', 'Priority'),
            DatasetColumn::make('channel', 'Channel'),

            DatasetColumn::related('category', 'Category', 'category.name', groupBy: 'category_id'),
            DatasetColumn::related('contact', 'Customer', 'contact.name', groupBy: 'contact_id'),
            DatasetColumn::related('project', 'Project', 'project.name', groupBy: 'project_id'),
            DatasetColumn::related('assignee', 'Assignee', 'assignee.name', groupBy: 'assignee_employee_id'),

            DatasetColumn::make('opened_at', 'Opened', DatasetColumn::DATE),
            DatasetColumn::make('first_responded_at', 'First reply', DatasetColumn::DATE, groupable: false),
            DatasetColumn::make('resolved_at', 'Resolved', DatasetColumn::DATE, groupable: false),
            DatasetColumn::make('closed_at', 'Closed', DatasetColumn::DATE, groupable: false),

            DatasetColumn::make('reopened_count', 'Reopened', DatasetColumn::NUMBER),
            DatasetColumn::make('satisfaction_rating', 'Rating', DatasetColumn::NUMBER),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::dateRange('period', 'Opened', 'opened_at'),
            DatasetFilter::dateRange('resolved', 'Resolved', 'resolved_at'),
            DatasetFilter::select('status', 'Status', 'status', fn (): array => [
                Ticket::STATUS_NEW => 'New',
                Ticket::STATUS_OPEN => 'Open',
                Ticket::STATUS_PENDING_CUSTOMER => 'Pending customer',
                Ticket::STATUS_RESOLVED => 'Resolved',
                Ticket::STATUS_CLOSED => 'Closed',
            ]),
            DatasetFilter::select(
                'priority',
                'Priority',
                'priority',
                // The model's own list, in its own order — urgent last would be alphabetical and useless.
                fn (): array => collect(Ticket::PRIORITIES)
                    ->mapWithKeys(fn (string $priority): array => [$priority => str($priority)->headline()->toString()])
                    ->all(),
            ),
            DatasetFilter::select(
                'category',
                'Category',
                'category_id',
                fn (): array => TicketCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            ),
            DatasetFilter::search('find', 'Number or subject contains', ['number', 'subject']),
        ];
    }
}
