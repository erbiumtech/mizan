<?php

namespace App\Modules\Core\Filament\Pages;

use App\Modules\Core\Services\CsvImportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Getting existing records in at setup.
 *
 * The GnuCash importer is the hard version of this and nobody setting up needs it.
 * What they have is a spreadsheet of clients, a spreadsheet of products, and a trial
 * balance from whatever they used before.
 *
 * Nothing is written until the preview has been read: an import that half-succeeds
 * leaves somebody guessing which half.
 */
class CsvImport extends Page
{
    protected string $view = 'filament.pages.csv-import';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $title = 'Import from CSV';

    public ?array $data = [];

    /** Set once a file has been read, so the page can show what would happen. */
    public ?array $preview = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'type' => CsvImportService::TYPE_CONTACTS,
            'opening_date' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('What are you importing?')
                    ->options(CsvImportService::LABELS)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn () => $this->preview = null),

                DatePicker::make('opening_date')
                    ->label('Balances as at')
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('type') === CsvImportService::TYPE_OPENING_BALANCES)
                    ->helperText('The date the opening entry is posted on — usually the day before your first month here.'),

                FileUpload::make('file')
                    ->label('CSV file')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv'])
                    ->maxSize(4096)
                    ->live()
                    ->afterStateUpdated(fn () => $this->preview = null)
                    ->required(),
            ])
            ->statePath('data')
            ->columns(2);
    }

    public function columnsFor(): array
    {
        return CsvImportService::COLUMNS[$this->data['type'] ?? CsvImportService::TYPE_CONTACTS] ?? [];
    }

    protected function contents(): ?string
    {
        $path = $this->data['file'] ?? null;
        $path = is_array($path) ? reset($path) : $path;

        if (! $path) {
            return null;
        }

        // Uploaded through the tenant-aware public disk, so a file waiting to be
        // imported is no more readable than anything else a company stores.
        return Storage::disk('public')->exists($path)
            ? Storage::disk('public')->get($path)
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('template')
                ->label('Download template')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => response()->streamDownload(
                    fn () => print(app(CsvImportService::class)->template($this->data['type'])),
                    $this->data['type'].'-template.csv',
                    ['Content-Type' => 'text/csv'],
                )),

            Action::make('preview')
                ->label('Check the file')
                ->icon('heroicon-o-eye')
                ->action(function (): void {
                    if (! $contents = $this->contents()) {
                        Notification::make()->danger()->title('Choose a CSV file first.')->send();

                        return;
                    }

                    try {
                        $this->preview = app(CsvImportService::class)->preview($contents, $this->data['type']);
                    } catch (\InvalidArgumentException $e) {
                        $this->preview = null;
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),

            Action::make('import')
                ->label('Import')
                ->icon('heroicon-o-check')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $this->preview
                    ? $this->preview['ready'].' row(s) will be imported and '.$this->preview['skipped'].' skipped.'
                    : 'Check the file first so you can see what will happen.')
                ->visible(fn (): bool => $this->preview !== null && $this->preview['ready'] > 0)
                ->action(function (): void {
                    try {
                        $result = app(CsvImportService::class)->import(
                            $this->contents(),
                            $this->data['type'],
                            $this->data['opening_date'] ?? null,
                        );
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $this->preview = null;

                    Notification::make()
                        ->success()
                        ->title("Imported {$result['imported']} row(s).")
                        ->body($result['skipped'] === []
                            ? null
                            : count($result['skipped']).' skipped: '.implode('; ', array_slice($result['skipped'], 0, 3)))
                        ->send();
                }),
        ];
    }
}
