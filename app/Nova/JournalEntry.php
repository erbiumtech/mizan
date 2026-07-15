<?php

namespace App\Nova;

use App\Nova\Actions\ApproveJournalEntry;
use App\Nova\Actions\PostJournalEntry;
use App\Nova\Actions\RejectJournalEntry;
use App\Nova\Actions\ReverseJournalEntry;
use App\Nova\Actions\SubmitJournalEntry;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;

class JournalEntry extends Resource
{
    public static $model = \App\Models\JournalEntry::class;

    public static $title = 'entry_number';

    public static $search = ['entry_number', 'reference', 'memo'];

    public static $group = 'Accounting';

    public function fields(NovaRequest $request)
    {
        return [
            ID::make()->sortable(),

            Text::make('Entry Number', 'entry_number')->exceptOnForms()->sortable(),

            Date::make('Entry Date', 'entry_date')->rules('required')->sortable(),

            Select::make('Entry Type', 'entry_type')->options([
                'general' => 'General',
                'adjusting' => 'Adjusting',
                'closing' => 'Closing',
                'reversing' => 'Reversing',
            ])->displayUsingLabels()->default('general')->filterable(),

            Badge::make('Status', 'status')->map([
                'draft' => 'info',
                'pending_approval' => 'warning',
                'approved' => 'success',
                'rejected' => 'danger',
                'posted' => 'success',
            ])->sortable()->filterable(),

            Text::make('Reference', 'reference')->nullable()->hideFromIndex(),

            Textarea::make('Memo', 'memo')->nullable()->alwaysShow(),

            Text::make('Rejection Reason', 'rejection_reason')->onlyOnDetail(),

            BelongsTo::make('Fiscal Year', 'fiscalYear', FiscalYear::class)->nullable(),

            BelongsTo::make('Created By', 'creator', User::class)->exceptOnForms(),

            BelongsTo::make('Approved By', 'approver', User::class)->onlyOnDetail(),

            DateTime::make('Approved At', 'approved_at')->onlyOnDetail(),

            DateTime::make('Posted At', 'posted_at')->onlyOnDetail(),

            Currency::make('Total Debits', fn () => $this->total_debits)->onlyOnDetail(),

            Currency::make('Total Credits', fn () => $this->total_credits)->onlyOnDetail(),

            HasMany::make('Lines', 'lines', JournalEntryLine::class),
        ];
    }

    public function actions(NovaRequest $request)
    {
        return [
            SubmitJournalEntry::make()
                ->showInline()
                ->canRun(fn ($request, $entry) => $request->user()->can('submit', $entry)),

            ApproveJournalEntry::make()
                ->showInline()
                ->canRun(fn ($request, $entry) => $request->user()->can('approve', $entry)),

            RejectJournalEntry::make()
                ->showInline()
                ->canRun(fn ($request, $entry) => $request->user()->can('reject', $entry)),

            PostJournalEntry::make()
                ->showInline()
                ->canRun(fn ($request, $entry) => $request->user()->can('post', $entry)),

            ReverseJournalEntry::make()
                ->showInline()
                ->canRun(fn ($request, $entry) => $request->user()->can('reverse', $entry)),
        ];
    }
}
