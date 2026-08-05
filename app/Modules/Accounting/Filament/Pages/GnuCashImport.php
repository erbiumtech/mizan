<?php

namespace App\Modules\Accounting\Filament\Pages;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\GnuCashImportService;
use App\Modules\Accounting\Services\RegisterEntryService;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

class GnuCashImport extends Page
{
    use BelongsToModule;

    protected string $view = 'filament.pages.gnucash-import';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    // Reached from the Reports hub, not the sidebar. See Core\Filament\Pages\Reports.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static ?string $title = 'GnuCash Import';

    protected static ?int $navigationSort = 7;

    public ?array $data = [];

    public ?array $preview = null;

    public ?string $token = null;

    public ?array $result = null;

    public static function canAccess(): bool
    {
        if (! static::moduleIsAvailable()) {
            return false;
        }

        return auth()->user()?->can('GnuCashImport') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function registerAccounts(): Collection
    {
        return app(RegisterEntryService::class)->registerAccounts();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('csv')
                    ->label('GnuCash CSV export')
                    ->acceptedFileTypes(['text/csv', 'text/plain'])
                    ->maxSize(10240)
                    ->storeFiles(false)
                    ->required(),
                Select::make('target_account_id')
                    ->label('Register target account (register exports only)')
                    ->placeholder('— not a register export —')
                    ->options($this->registerAccounts()->mapWithKeys(fn (Account $a) => [$a->id => $a->code.' '.$a->name])->all())
                    ->native(false),
            ])
            ->statePath('data')
            ->columns(2);
    }

    public function preview(): void
    {
        $state = $this->form->getState();

        $upload = $state['csv'] ?? null;
        $file = is_array($upload) ? reset($upload) : $upload;

        if (! $file instanceof TemporaryUploadedFile) {
            Notification::make()->danger()->title('Please choose a CSV file.')->send();

            return;
        }

        $csv = $file->get();
        $target = ! empty($state['target_account_id']) ? Account::find($state['target_account_id']) : null;

        try {
            $preview = app(GnuCashImportService::class)->preview($csv, $target);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $token = Str::uuid()->toString();
        Storage::put("gnucash-imports/{$token}.csv", $csv);

        $this->preview = $preview;
        $this->token = $token.($target ? ':'.$target->id : '');
        $this->result = null;
    }

    public function confirm(): void
    {
        if (! $this->token) {
            return;
        }

        [$token, $targetId] = array_pad(explode(':', $this->token), 2, null);
        $path = 'gnucash-imports/'.basename($token).'.csv';

        if (! Storage::exists($path)) {
            Notification::make()->danger()->title('Uploaded file expired — upload again.')->send();
            $this->reset(['preview', 'token']);

            return;
        }

        $target = $targetId ? Account::find($targetId) : null;

        try {
            $result = app(GnuCashImportService::class)->import(Storage::get($path), $target);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } finally {
            Storage::delete($path);
        }

        activity('gnucash-import')
            ->causedBy(auth()->user())
            ->withProperties($result)
            ->log('GnuCash import: '.json_encode($result));

        $this->result = $result;
        $this->reset(['preview', 'token']);
        $this->form->fill();

        Notification::make()->success()->title('Import complete ('.$result['kind'].').')->send();
    }
}
