<?php

namespace App\Modules\Campaigns\Filament\Resources\Segments;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Campaigns\Filament\Resources\Segments\Pages\CreateSegment;
use App\Modules\Campaigns\Filament\Resources\Segments\Pages\EditSegment;
use App\Modules\Campaigns\Filament\Resources\Segments\Pages\ListSegments;
use App\Modules\Campaigns\Models\Segment;
use App\Modules\Campaigns\Services\CampaignSender;
use App\Modules\Crm\Models\LeadSource;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Who a campaign is for — the "Send to" list every campaign picks from.
 *
 * A segment is a rule, not a list of names: it is resolved when a campaign is sent, so a campaign
 * reaches everybody who matches then rather than last month's list. Until this screen nothing could
 * make one, and Campaigns → New had an empty required field.
 */
class SegmentResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Segment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 41;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->placeholder('Lahore customers and open leads'),

            Toggle::make('is_active')->default(true)
                ->helperText('Off, it is no longer offered to a new campaign. Campaigns already sent keep it.'),

            Section::make('Who matches')
                ->description('Resolved at the moment a campaign is sent. Consent is checked separately, per channel, for every person it finds.')
                ->columns(2)
                ->schema([
                    Toggle::make('definition.include_leads')->label(__('Open leads'))->default(true)
                        ->helperText('Leads not yet converted or lost.'),
                    Toggle::make('definition.include_contacts')->label(__('Active customers'))->default(false)
                        ->helperText('Customers in Invoicing.')
                        ->visible(fn (): bool => modules()->enabled('invoicing')),
                    Select::make('definition.lead_source_id')->label(__('Only leads from'))
                        ->options(fn (): array => LeadSource::query()->orderBy('sort')->pluck('name', 'id')->all())
                        ->placeholder('Any source')
                        ->helperText('Narrows the leads only.'),
                    TextInput::make('definition.city')->label(__('Only in this city'))->maxLength(120)
                        ->helperText('Matched against the lead\'s city and the customer\'s address. Blank for anywhere.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('audience')->label(__('Matches now'))->alignEnd()
                    ->state(fn (Segment $record): int => app(CampaignSender::class)->audienceFor($record)->count()),
                TextColumn::make('campaigns_count')->label(__('Campaigns'))->counts('campaigns')->alignEnd(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([\Filament\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSegments::route('/'),
            'create' => CreateSegment::route('/create'),
            'edit' => EditSegment::route('/{record}/edit'),
        ];
    }
}
