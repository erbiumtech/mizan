<?php

namespace App\Modules\Lifecycle\Filament\Settings;

use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Modules\Lifecycle\Support\DocumentRequirements;
use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/** Which documents a company insists on, and what it will accept — docs/saas-launch-plan.md §14.8. */
class RequiredDocumentsSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'lifecycle.documents';
    }

    public function components(): array
    {
        return [
            Section::make('Employee documents')
                ->description('Nothing is required until you say so. What you tick here is what the compliance '
                    .'report counts, and — if you also switch the check on under Verification — what somebody '
                    .'has to have on file before they are let in.')
                ->schema([
                    CheckboxList::make('lifecycle_documents_required')
                        ->label('Required of every employee')
                        ->options(EmployeeDocument::KINDS_LABELLED)
                        ->columns(4)
                        ->helperText('A kind counts as held once there is a document of that kind that has not '
                            .'been refused and has not lapsed.'),
                    CheckboxList::make('lifecycle_documents_formats')
                        ->label('Accepted file types')
                        ->options(['pdf' => 'PDF', 'jpg' => 'JPG', 'png' => 'PNG'])
                        ->columns(3)
                        ->helperText('A scan of a passport is usually a photo; a contract is usually a PDF.'),
                    TextInput::make('lifecycle_documents_max_kb')
                        ->label('Largest upload (KB)')
                        ->numeric()
                        ->minValue(256)
                        ->maxValue(20480)
                        ->helperText('Phone cameras produce several megabytes a picture. 4096 is a comfortable default.'),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'lifecycle_documents_required' => array_keys(DocumentRequirements::required()),
            'lifecycle_documents_formats' => DocumentRequirements::formats(),
            'lifecycle_documents_max_kb' => DocumentRequirements::maxKb(),
        ];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('lifecycle_documents_required', $state)) {
            return;
        }

        $settings = app(TenantSettings::class);

        $settings->set('lifecycle.documents.required', array_values(array_intersect(
            (array) ($state['lifecycle_documents_required'] ?? []),
            EmployeeDocument::KINDS,
        )));

        $settings->set('lifecycle.documents.formats', array_values(array_intersect(
            (array) ($state['lifecycle_documents_formats'] ?? []),
            DocumentRequirements::DEFAULT_FORMATS,
        )) ?: DocumentRequirements::DEFAULT_FORMATS);

        $settings->set('lifecycle.documents.max_kb', max(256, (int) ($state['lifecycle_documents_max_kb'] ?: DocumentRequirements::DEFAULT_MAX_KB)));
    }
}
