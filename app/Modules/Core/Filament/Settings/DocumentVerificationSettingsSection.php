<?php

namespace App\Modules\Core\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\TenantSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

/**
 * The verification page's two gates — docs/saas-launch-plan.md §7.5.
 */
class DocumentVerificationSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'documents.verification';
    }

    public function components(): array
    {
        return [
            Section::make('Document verification')
                ->description('Prints a QR code on payslips and HR letters. Whoever scans it sees whether the '
                    .'document still matches the record — the facts printed, and nothing else.')
                ->schema([
                    Toggle::make('documents_verification_enabled')
                        ->label('Print verification codes')
                        ->helperText('Off by default. Documents rendered while this is off carry no code.'),
                    TextInput::make('documents_verification_token')
                        ->label('Verification token')
                        ->helperText('Part of every code\'s address. Changing it breaks the codes already printed.')
                        ->suffixAction(
                            Action::make('generateVerificationToken')
                                ->icon('heroicon-m-arrow-path')
                                ->label('Generate')
                                ->action(fn (Set $set) => $set('documents_verification_token', Str::random(32)))
                        ),
                ]),
        ];
    }

    public function fill(): array
    {
        return [
            'documents_verification_enabled' => (bool) setting('documents.verification.enabled', false),
            'documents_verification_token' => setting('documents.verification.token'),
        ];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('documents_verification_enabled', $state)) {
            return;
        }

        $settings = app(TenantSettings::class);
        $settings->set('documents.verification.enabled', (bool) ($state['documents_verification_enabled'] ?? false));
        $settings->set('documents.verification.token', $state['documents_verification_token'] ?: null);
    }
}
