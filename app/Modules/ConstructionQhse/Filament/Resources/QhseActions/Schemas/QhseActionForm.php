<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\QhseActions\Schemas;

use App\Modules\ConstructionQhse\Models\QhseAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Working an action.
 *
 * **Neither completion nor verification is on this form.** They are two separate acts with two separate permissions —
 * "done" is the assignee's claim and "verified" is somebody else's confirmation, and a form that could write either
 * would let whoever caused a finding close it.
 *
 * The subject is shown and not editable: an action belongs to the finding that produced it, and moving one between
 * findings would rewrite two records' histories at once.
 */
class QhseActionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The action')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('subject')
                            ->label('Raised against')
                            ->columnSpanFull()
                            ->content(fn (?QhseAction $record): string => $record === null
                                ? '—'
                                : $record->sourceLabel().': '.($record->subject?->displayName() ?? 'no longer present')),

                        Textarea::make('description')->required()->rows(3)->columnSpanFull(),

                        Select::make('action_type')
                            ->options(QhseAction::TYPES)
                            ->default('corrective')
                            ->required()
                            ->helperText('Containment is not correction — cordoning a hole off is not filling it.'),

                        Select::make('priority')
                            ->options(QhseAction::PRIORITIES)
                            ->default('medium')
                            ->required(),

                        DatePicker::make('due_on')->label('Due')->native(false),

                        Select::make('assigned_contact_id')
                            ->label('Assignee (contact)')
                            ->relationship('assignedContact', 'name')
                            ->searchable()
                            ->preload()
                            ->visible(fn (): bool => modules()->enabled('invoicing')),

                        TextInput::make('assignee_label')
                            ->label('Assignee, in words')
                            ->maxLength(255)
                            ->helperText('The one that always works. On most sites the person who has to do this is a subcontractor\'s foreman, in no table here.'),
                    ]),
            ]);
    }
}
