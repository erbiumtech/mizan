<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Recruitment\Filament\Resources\Applications\ApplicationResource;
use App\Modules\Recruitment\Filament\Resources\Applications\Schemas\ApplicationInfolist;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One candidate, from applied to hired.
 *
 * **This page exists because the rounds had nowhere to live.** An application could already carry as
 * many interview rounds as a company wanted — `Interviews::schedule()` has always numbered them — and
 * the only place to see them was the Interviews list, where rounds for one candidate sat among rounds
 * for everybody else and had to be filtered out by hand. The pipeline was a badge in a table cell.
 *
 * The five actions are the resource's own definitions, not copies: the list triages a pipeline and
 * this page works one candidate, and the same button has to mean the same thing on both.
 */
class ViewApplication extends ViewRecord
{
    protected static string $resource = ApplicationResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->record->applicant?->name ?? 'Application';
    }

    /** What they applied for and when — the two facts the name alone leaves out. */
    public function getSubheading(): string|Htmlable|null
    {
        $vacancy = $this->record->vacancy?->title;
        $on = $this->record->applied_on?->format('d M Y');

        return trim(
            ($vacancy ? 'Applied for '.$vacancy : 'Applied')
            .($on ? ' on '.$on : '')
        );
    }

    public function infolist(Schema $schema): Schema
    {
        return ApplicationInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            ...ApplicationResource::pipelineActions(),
            EditAction::make(),
            HelpAction::make('applications', 'Applications: Help'),
        ];
    }
}
