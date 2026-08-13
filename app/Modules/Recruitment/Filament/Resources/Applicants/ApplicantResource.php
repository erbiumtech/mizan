<?php

namespace App\Modules\Recruitment\Filament\Resources\Applicants;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Recruitment\Filament\Resources\Applicants\Pages\CreateApplicant;
use App\Modules\Recruitment\Filament\Resources\Applicants\Pages\EditApplicant;
use App\Modules\Recruitment\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Modules\Recruitment\Models\Applicant;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * People who applied.
 *
 * Separate from their applications because one person applies twice, and a system that
 * cannot see that has no memory.
 *
 * **The most sensitive data here, held about people the company never hired.** Records are
 * pruned automatically two years after rejection, and the CV file goes with the row.
 */
class ApplicantResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Applicant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Hiring';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->maxLength(255)
                ->helperText('Search for this before adding somebody — they may have applied before.'),
            TextInput::make('phone')->tel()->maxLength(50),
            TextInput::make('cnic')->label('CNIC')->maxLength(50),
            TextInput::make('source')->maxLength(255)->placeholder('Referral'),
            TextInput::make('current_employer')->maxLength(255),
            TextInput::make('notice_period_days')->label('Notice period (days)')->numeric(),
            TextInput::make('expected_salary')->numeric(),
            TextInput::make('linkedin')->url()->maxLength(255),

            FileUpload::make('resume_path')
                ->label('CV')
                ->disk('public')
                ->directory('applicant-resumes')
                ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                ->maxSize(8192)
                ->helperText('Deleted automatically with the record two years after a rejection. Your company is the data controller for this.'),

            Textarea::make('notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    // The whole point of the split table, surfaced where it is useful.
                    ->description(fn (Applicant $record): ?string => $record->hasAppliedBefore()
                        ? 'has applied before'
                        : null),

                TextColumn::make('email')->searchable()->placeholder('—'),
                TextColumn::make('phone')->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('source')->badge()->color('gray')->placeholder('—')->toggleable(),

                TextColumn::make('applications_count')
                    ->label('Applications')
                    ->counts('applications')
                    ->alignEnd(),

                TextColumn::make('created_at')->label('Added')->date('d M Y')->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplicants::route('/'),
            'create' => CreateApplicant::route('/create'),
            'edit' => EditApplicant::route('/{record}/edit'),
        ];
    }
}
