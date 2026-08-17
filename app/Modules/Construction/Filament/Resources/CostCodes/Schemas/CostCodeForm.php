<?php

namespace App\Modules\Construction\Filament\Resources\CostCodes\Schemas;

use App\Modules\Construction\Models\CostCode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A cost code, and its mappings to the standards somebody will ask for.
 *
 * The mapping section is its own block and every field in it is optional, deliberately: §2.1's whole argument
 * is that an unmapped code is a countable row in that standard's report rather than a blocked save. A form that
 * demanded five classifications to save a code is a form people work around, and a code invented outside the
 * library is what §2.2 exists to prevent.
 */
class CostCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The code')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Your own numbering — the library is shared by every job, so a code means one thing everywhere.'),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Select::make('parent_id')
                            ->label('Under')
                            ->relationship('parent', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Leave blank for a top-level heading.'),

                        Select::make('cost_type')
                            ->label('Cost type')
                            ->options([
                                CostCode::TYPE_LABOUR => 'Labour',
                                CostCode::TYPE_MATERIAL => 'Material',
                                CostCode::TYPE_PLANT => 'Plant',
                                CostCode::TYPE_SUBCONTRACT => 'Subcontract',
                                CostCode::TYPE_OTHER => 'Other',
                            ])
                            ->default(CostCode::TYPE_OTHER)
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->helperText('The standard five. Every cost report groups by this before anything else.'),

                        TextInput::make('unit')
                            ->maxLength(16)
                            ->helperText('m3, m2, t, hr, sum — what the rate is per.'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Switch off a one-off provisional item rather than inventing a private code on one job.'),
                    ]),

                Section::make('Classification')
                    ->description('The same money, in the shapes other people ask for it. All optional — an unmapped code shows as one countable "unmapped" row in that standard\'s report, never as a wrong total.')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('masterformat_code')->label('MasterFormat (CSI)')->maxLength(255),
                        TextInput::make('uniformat_code')->label('UniFormat')->maxLength(255),
                        TextInput::make('uniclass_code')->label('Uniclass 2015 (NBS)')->maxLength(255),
                        TextInput::make('omniclass_code')->label('OmniClass')->maxLength(255),
                        TextInput::make('nrm_code')->label('NRM (RICS)')->maxLength(255),
                    ]),

                Section::make('ICMS')
                    ->description('The one classification that is not optional in practice: ICMS 3 is what lets a client compare this job with one in another country.')
                    ->columns(2)
                    ->schema([
                        Select::make('icms_category')
                            ->label('ICMS category (Level 2)')
                            ->options(CostCode::ICMS_CATEGORIES)
                            ->native(false)
                            ->helperText('The six fixed categories.'),

                        TextInput::make('icms_group')
                            ->label('ICMS cost group (Level 3)')
                            ->maxLength(255)
                            ->helperText('The standardised group under that category. Level 4 below it is your own.'),

                        Textarea::make('notes')
                            ->columnSpanFull()
                            ->rows(2),
                    ]),
            ]);
    }
}
