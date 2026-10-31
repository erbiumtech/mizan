<?php

namespace App\Modules\Recruitment\Filament\Resources\Applicants\Schemas;

use App\Modules\Recruitment\Models\Applicant;
use App\Support\CompanyCurrency;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * An applicant read in the list's view sheet — docs/side-sheets.md. The CV is a link to the file the
 * form already lets whoever may view an applicant open.
 */
class ApplicantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('name')->label(__('Name')),
            TextEntry::make('email')->label(__('Email'))->copyable()->placeholder('—'),
            TextEntry::make('phone')->label(__('Phone'))->copyable()->placeholder('—'),
            TextEntry::make('cnic')->label(__('CNIC'))->placeholder('—'),
            TextEntry::make('source')->label(__('Source'))->placeholder('—'),
            TextEntry::make('current_employer')->label(__('Current employer'))->placeholder('—'),
            TextEntry::make('notice_period_days')->label(__('Notice period (days)'))->placeholder('—'),
            TextEntry::make('expected_salary')->label(__('Expected salary'))->money(CompanyCurrency::code())->placeholder('—'),
            TextEntry::make('linkedin')
                ->label(__('LinkedIn'))
                ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                ->placeholder('—'),
            TextEntry::make('resume_path')
                ->label(__('CV'))
                ->formatStateUsing(fn (): string => __('Open CV'))
                ->url(fn (Applicant $record): ?string => $record->resume_path
                    ? Storage::disk('public')->url($record->resume_path)
                    : null, shouldOpenInNewTab: true)
                ->placeholder('—'),
            TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
        ]);
    }
}
