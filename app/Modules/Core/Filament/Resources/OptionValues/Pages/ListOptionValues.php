<?php

namespace App\Modules\Core\Filament\Resources\OptionValues\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\OptionValues\OptionValueResource;
use App\Support\OptionLists;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Illuminate\Support\Str;

class ListOptionValues extends ListRecords
{
    protected static string $resource = OptionValueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('dropdown-options', 'Dropdown Options: Help'),

            /**
             * The rest of the answer.
             *
             * This screen edits the dropdowns that are lists of words. It is not the only place a dropdown
             * comes from, and somebody who opens it looking for petty cash categories and does not find
             * them has no way of knowing whether they are somewhere else or nowhere at all. So the screen
             * accounts for every dropdown in the application, not only its own.
             */
            Action::make('elsewhere')
                ->label('Other dropdowns')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->slideOver()
                ->modalHeading('Where every other dropdown is edited')
                ->modalWidth(Width::TwoExtraLarge)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn () => view('filament.help.content', [
                    'markdown' => Str::markdown(static::elsewhereMarkdown()),
                ])),

            \Filament\Actions\CreateAction::make(),
        ];
    }

    /**
     * The three places a dropdown's contents can come from, in the order somebody hunting for one should
     * check: a screen of its own, the records it picks from, or nowhere because the value is code.
     *
     * Only the screens this company has and this person may open are listed — a pointer to a screen that
     * 404s is worse than no pointer, and `OptionLists::elsewhere()` filters on the resource's own
     * `canAccess()`.
     */
    protected static function elsewhereMarkdown(): string
    {
        $lines = ['## Lists that keep a screen of their own', '',
            'Some dropdowns pick a **row**, not a word. A petty cash category points at the account it '
            .'posts to; a leave type carries its own day counts, accrual and notice; a ticket category '
            .'carries what you have promised to answer in. None of that survives being a word in a list, '
            .'so each keeps its own screen:', ''];

        $pointers = OptionLists::elsewhere();

        if ($pointers === []) {
            $lines[] = '*None — either this company has no module that declares one, or your role cannot '
                .'open the screens that hold them.*';
        }

        foreach ($pointers as $pointer) {
            $lines[] = '- ['.$pointer['label'].']('.$pointer['url'].')'
                .($pointer['help'] === null ? '' : ' — '.$pointer['help']);
        }

        $lines = [...$lines, '',
            '## Dropdowns that pick a record', '',
            'An account, an employee, a customer, a project, a job, a supplier. There is nothing to '
            .'configure for these: they offer whatever records exist, so adding an employee adds them to '
            .'every employee dropdown in the application at once.', '',
            '## Dropdowns that are fixed, and why', '',
            'Statuses and workflow stages — draft, approved, certified, posted, paid. These are not '
            .'wording, they are **what the application does next**: a certified claim is one the ledger '
            .'has taken, an approved entry is one that may post, a paid settlement is one that is closed '
            .'to further change. A value added to one of those would be a status nothing in the '
            .'application knows how to act on — records could enter it and never leave.', '',
            'So they are deliberately not editable. If one of them is wrong for how your company works, '
            .'that is a change to what the application *does* with it, not a change to a list — worth '
            .'raising as exactly that.',
        ];

        return implode("\n", $lines);
    }
}
