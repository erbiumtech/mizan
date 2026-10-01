<?php

namespace App\Modules\PersonalFinance\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\PersonalFinance\Services\SoleProprietorReturnPack as PackService;
use App\Support\Pdf\Pdf;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * A sole proprietor's return: business profit taxed on the owner's individual
 * slabs. Shown only for a business-type company marked a sole proprietor — a
 * company proper opens the Corporate pack instead, which excludes this entity.
 */
class SoleProprietorReturnPack extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.sole-proprietor-return-pack';

    protected static string|UnitEnum|null $navigationGroup = 'Personal';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $title = 'Sole Proprietor Return Pack';

    protected static ?int $navigationSort = 7;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        // A business that is a sole proprietor — the one entity whose business
        // profit is the owner's individual income. Not a personal account (that
        // is the ordinary Return Pack), not a company (that is the Corporate one).
        $company = Filament::getTenant() ?? Company::current();

        if (! $company || $company->isPersonal() || ! $company->isSoleProprietor()) {
            return false;
        }

        return auth()->user()?->can('ReportView') ?? false;
    }

    public function mount(): void
    {
        $yearId = FiscalYear::current()?->getKey()
            ?? FiscalYear::orderByDesc('start_date')->value('id');

        $this->form->fill([
            'fiscal_year_id' => $yearId,
            ...($yearId ? app(PackService::class)->worksheet((int) $yearId) : []),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fiscal_year_id')
                    ->label('Tax year')
                    ->options(FiscalYear::orderByDesc('start_date')->pluck('name', 'id'))
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function ($state): void {
                        if ($state) {
                            $this->form->fill([
                                'fiscal_year_id' => $state,
                                ...app(PackService::class)->worksheet((int) $state),
                            ]);
                        }
                    })
                    ->helperText('July to June; FBR names it for the year it ends in.'),

                Repeater::make('adjustments')
                    ->label('Tax adjustments to accounting profit')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('label')
                            ->placeholder('e.g. Accounting depreciation added back / Tax depreciation (Third Schedule)'),
                        TextInput::make('amount')
                            ->numeric()
                            ->placeholder('positive adds back, negative deducts'),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add adjustment')
                    ->live(onBlur: true)
                    ->helperText('What the ledger cannot know: tax vs accounting depreciation, inadmissible '
                        .'expenses, exempt income. Signed — positive increases taxable income.'),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save worksheet')
                ->icon('heroicon-o-check')
                ->action(function (): void {
                    if ($yearId = $this->data['fiscal_year_id'] ?? null) {
                        app(PackService::class)->saveWorksheet((int) $yearId, $this->data);
                        Notification::make()->success()->title('Worksheet saved for this year.')->send();
                    }
                }),

            Action::make('download')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    $pack = $this->getPack();

                    if (! $pack) {
                        return null;
                    }

                    $pdf = Pdf::view('pdfs.sole-proprietor-return-pack', ['pack' => $pack])
                        ->format('a4')
                        ->name('sole-proprietor-return-pack-'.$pack['year']->name.'.pdf');

                    return response()->streamDownload(
                        fn () => print ($pdf->raw()),
                        $pdf->getName(),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPack(): ?array
    {
        $yearId = $this->data['fiscal_year_id'] ?? null;

        return $yearId
            ? app(PackService::class)->build((int) $yearId, $this->data)
            : null;
    }
}
