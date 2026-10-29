<?php

namespace App\Modules\Core\Filament\Settings;

use App\Support\Contracts\SettingsSection;
use App\Support\DocumentLayout;
use App\Support\TenantSettings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * How the company's documents are laid out — docs/saas-launch-plan.md §14.7.
 *
 * Deliberately not a template editor. See {@see DocumentLayout} for why free markup
 * is not on offer: it would bypass the per-engine overrides and print differently
 * depending on which PDF engine the server has.
 */
class DocumentLayoutSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'documents.layout';
    }

    public function components(): array
    {
        return [
            Section::make('Payslips and letters')
                ->description('Two lines of your own wording, and which parts of a payslip print. The sheet '
                    .'itself stays the same across payslips, certificates and letters, so documents from this '
                    .'company look like they came from one.')
                ->schema([
                    TextInput::make('documents_header_note')
                        ->label('Line under the letterhead')
                        ->maxLength(190)
                        ->placeholder('Registered under the Companies Act 2017')
                        ->helperText('Printed on every document, under the company name. Leave empty for none.'),
                    TextInput::make('documents_footer_note')
                        ->label('Line in the footer')
                        ->maxLength(190)
                        ->placeholder('This is a computer-generated document and needs no signature.')
                        ->helperText('Printed in the coloured bar at the foot of the page.'),
                    CheckboxList::make('documents_payslip_hidden')
                        ->label('Leave these off the payslip')
                        ->options(DocumentLayout::PAYSLIP_BLOCKS)
                        ->columns(3)
                        ->helperText('Everything prints unless you say otherwise. Bank details are the usual one '
                            .'to drop: a payslip gets forwarded to a landlord more often than people expect.'),
                ]),
        ];
    }

    public function fill(): array
    {
        $hidden = setting('documents.payslip_hidden', []);

        return [
            'documents_header_note' => setting('documents.header_note'),
            'documents_footer_note' => setting('documents.footer_note'),
            'documents_payslip_hidden' => is_array($hidden) ? $hidden : [],
        ];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('documents_header_note', $state)) {
            return;
        }

        $settings = app(TenantSettings::class);
        $settings->set('documents.header_note', $state['documents_header_note'] ?: null);
        $settings->set('documents.footer_note', $state['documents_footer_note'] ?: null);
        $settings->set('documents.payslip_hidden', array_values(array_intersect(
            (array) ($state['documents_payslip_hidden'] ?? []),
            array_keys(DocumentLayout::PAYSLIP_BLOCKS),
        )));
    }
}
