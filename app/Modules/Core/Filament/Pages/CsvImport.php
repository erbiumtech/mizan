<?php

namespace App\Modules\Core\Filament\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Services\CsvImportService;
use App\Support\CsvImporters;
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
 *
 * The types on offer are whatever the installed modules registered — see `App\Support\CsvImporters` and
 * docs/module-packaging-plan.md §9 — so this page no longer names an import type of its own, including the
 * one that needed a date.
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
        // Nothing registered means no module that owns importable records is installed, and the page would
        // offer an empty dropdown.
        return (auth()->user()?->isAdministrator() ?? false) && CsvImporters::keys() !== [];
    }

    public function mount(): void
    {
        $this->form->fill([
            'type' => $this->defaultType(),
            'as_at' => now()->toDateString(),
        ]);
    }

    /** The first registered type, since which types exist is no longer known here. */
    protected function defaultType(): ?string
    {
        return CsvImporters::keys()[0] ?? null;
    }

    protected function chosenType(): ?string
    {
        return $this->data['type'] ?? $this->defaultType();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('What are you importing?')
                    ->options(CsvImporters::labels())
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn () => $this->preview = null),

                // Shown only for the imports that asked for a date, with the wording they asked for: an
                // opening trial balance is posted on a day, a list of clients is not.
                DatePicker::make('as_at')
                    ->label(fn (Get $get): string => $this->dateField($get('type'))['label'] ?? 'As at')
                    ->native(false)
                    ->visible(fn (Get $get): bool => $this->dateField($get('type')) !== null)
                    ->helperText(fn (Get $get): ?string => $this->dateField($get('type'))['help'] ?? null),

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
        $type = $this->chosenType();

        return $type !== null && CsvImporters::has($type)
            ? CsvImporters::get($type)->columns()
            : [];
    }

    /**
     * The date field this type asked for, or null.
     *
     * @return null|array{label: string, help: string}
     */
    protected function dateField(?string $type): ?array
    {
        return $type !== null && CsvImporters::has($type)
            ? CsvImporters::get($type)->dateField()
            : null;
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
            HelpAction::make('csv-import', 'CSV Import: Help'),

            Action::make('template')
                ->label('Download template')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => response()->streamDownload(
                    fn () => print (app(CsvImportService::class)->template($this->data['type'])),
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
                            $this->data['as_at'] ?? null,
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
