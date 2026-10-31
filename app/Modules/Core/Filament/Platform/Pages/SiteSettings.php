<?php

namespace App\Modules\Core\Filament\Platform\Pages;

use App\Modules\Core\Models\SiteSetting;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * The few sentences on the public site that are written rather than generated —
 * docs/company-website-plan.md W1.
 *
 * Landlord, tenant-less, super-admin only, like everything on this panel. Each field falls back to
 * SiteSetting::DEFAULTS when left blank, so the site reads sensibly on an installation where nobody
 * has opened this page yet.
 */
class SiteSettings extends Page
{
    protected string $view = 'filament.platform.pages.site-settings';

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $title = 'Site settings';

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $values = SiteSetting::values();

        $this->form->fill(array_map(fn (string $key) => $values[$key] ?? null, array_combine(
            array_keys(SiteSetting::DEFAULTS),
            array_keys(SiteSetting::DEFAULTS),
        )));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Home page'))
                    ->description(__('Blank fields fall back to the built-in wording.'))
                    ->components([
                        TextInput::make('tagline')
                            ->label(__('Tagline'))
                            ->placeholder(SiteSetting::DEFAULTS['tagline'])
                            ->maxLength(160),
                        Textarea::make('intro')
                            ->label(__('Introduction'))
                            ->placeholder(SiteSetting::DEFAULTS['intro'])
                            ->rows(4)
                            ->maxLength(600),
                    ]),
                Section::make(__('Contact details'))
                    ->description(__('Shown on the contact page and in the footer when filled in.'))
                    ->components([
                        TextInput::make('contact_email')->label(__('Contact email'))->email()->maxLength(190),
                        TextInput::make('contact_phone')->label(__('Contact phone'))->maxLength(40),
                        Textarea::make('address')->label(__('Office address'))->rows(3)->maxLength(300),
                        TextInput::make('footer')->label(__('Footer line'))->maxLength(200),
                    ]),
            ]);
    }

    public function save(): void
    {
        foreach ($this->form->getState() as $key => $value) {
            if (array_key_exists($key, SiteSetting::DEFAULTS)) {
                SiteSetting::put($key, $value === '' ? null : $value);
            }
        }

        Notification::make()->title(__('Site settings saved'))->success()->send();
    }
}
