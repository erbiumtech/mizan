<?php

namespace App\Modules\Accounting\Filament\Resources\BankFileFormats;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Filament\Resources\BankFileFormats\Pages\CreateBankFileFormat;
use App\Modules\Accounting\Filament\Resources\BankFileFormats\Pages\EditBankFileFormat;
use App\Modules\Accounting\Filament\Resources\BankFileFormats\Pages\ListBankFileFormats;
use App\Modules\Accounting\Models\BankFileFormat;
use App\Modules\Accounting\Models\Payment;
use App\Modules\Accounting\Services\BankFileRenderer;
use App\Modules\Accounting\Services\BankPaymentExportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** The bank-file designer — docs/saas-launch-plan.md §14.1. */
class BankFileFormatResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = BankFileFormat::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Bank File Formats';

    protected static ?int $navigationSort = 28;

    public static function form(Schema $schema): Schema
    {
        $legend = fn (array $placeholders): HtmlString => new HtmlString('<ul class="text-sm grid gap-1 sm:grid-cols-2">'.collect($placeholders)
            ->map(fn (string $what, string $key): string => '<li><code>{'.$key.'}</code> — '.e($what).'</li>')->implode('').'</ul>');

        return $schema->components([
            Section::make('Format')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(80),
                Select::make('company_bank_account_id')
                    ->label(__('For bank account'))
                    ->relationship('companyBankAccount', 'title')
                    ->preload()
                    ->unique(ignoreRecord: true)
                    ->helperText('Leave empty for the format every account without its own uses.'),
                Select::make('line_ending')->options(['crlf' => 'Windows (CRLF)', 'lf' => 'Unix (LF)'])->default('crlf')->required(),
                TextInput::make('extension')->default('csv')->maxLength(8)->required(),
                Toggle::make('is_active')->default(true),
            ]),
            Section::make('Lines')
                ->description('One line each. The row is repeated per payment; the header and trailer once, and either may be empty.')
                ->schema([
                    Textarea::make('header_template')->label(__('Header'))->rows(2)->placeholder('H,{count},{total},{value_date:Ymd}'),
                    Textarea::make('row_template')->label(__('Row'))->rows(3)->required(fn ($record): bool => $record === null || $record->isTemplate())
                        ->placeholder('{row_no},{beneficiary_name:35},{beneficiary_account},{beneficiary_bank_code},{amount},{details:50}'),
                    Textarea::make('trailer_template')->label(__('Trailer'))->rows(2)->placeholder('T,{count},{total}'),
                    Placeholder::make('row_placeholders')->label(__('Row placeholders'))->content($legend(BankFileRenderer::ROW_PLACEHOLDERS)),
                    Placeholder::make('file_placeholders')->label(__('Header and trailer placeholders'))->content($legend(BankFileRenderer::FILE_PLACEHOLDERS)),
                ])
                ->visible(fn ($record): bool => $record === null || $record->isTemplate()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state): string => $state === BankFileFormat::KIND_IPAYMENTS ? 'iPayments (shipped)' : 'Designed'),
                TextColumn::make('companyBankAccount.title')->label(__('Account'))->placeholder('Default'),
                TextColumn::make('previewed_at')->label(__('Previewed'))->since()->placeholder('never')
                    ->description(fn (BankFileFormat $r): ?string => $r->needsPreview() ? 'changed since — preview before download' : null),
                IconColumn::make('is_active')->boolean()->label(__('Active')),
            ])
            ->recordActions([static::previewAction(), EditAction::make(), DeleteAction::make(), ReplicateRecordAction::make()]);
    }

    /** Against the real pending payments — what the bank would receive, before it does. */
    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('Preview'))
            ->icon('heroicon-o-eye')
            ->visible(fn (BankFileFormat $record): bool => $record->isTemplate())
            ->modalHeading(fn (BankFileFormat $record): string => 'Preview: '.$record->name)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (BankFileFormat $record): HtmlString {
                $payments = Payment::query()->with(['payable', 'companyBankAccount.bank', 'payslip'])->unreleased()->orderBy('id')->limit(5)->get();

                try {
                    $text = app(BankPaymentExportService::class)->renderWith($record, $payments, null, preview: true);
                } catch (\Throwable $e) {
                    return new HtmlString('<p class="text-sm text-danger-600">'.e($e->getMessage()).'</p>');
                }

                $record->forceFill(['previewed_at' => now()])->saveQuietly();

                return new HtmlString('<p class="text-sm text-gray-500 mb-2">The first '.$payments->count().' pending payment(s), as the file would carry them. Previewed now; download is open until the format next changes.</p><pre class="text-xs overflow-x-auto p-3 rounded bg-gray-50 dark:bg-gray-900">'.e($text).'</pre>');
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankFileFormats::route('/'),
            'create' => CreateBankFileFormat::route('/create'),
            'edit' => EditBankFileFormat::route('/{record}/edit'),
        ];
    }
}
