<?php

namespace App\Modules\Employees\Rules;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;

/**
 * The Repeater over the two registries — docs/saas-launch-plan.md §8.9: field,
 * operator, value, and a window for a metric. Nothing to parse; the dropdown is
 * the documentation. "Who matches today" beside it runs the rule and lists the
 * people it would select, which is how a rule is seen before it awards a badge
 * to a hundred people.
 */
class RuleForm
{
    /** @return array<int, \Filament\Schemas\Components\Component> */
    public static function schema(string $statePath = 'rule'): array
    {
        $fieldOptions = static fn (): array => collect(RuleRegistry::fields())
            ->groupBy('group')
            ->map(fn ($fields) => $fields->map(fn (array $f): string => $f['label'])->all())
            ->all();

        return [
            Repeater::make($statePath.'.any')
                ->label('Match anyone who')
                ->addActionLabel('Add another group (or)')
                ->defaultItems(0)
                ->schema([
                    Repeater::make('all')
                        ->label('All of these (and)')
                        ->addActionLabel('Add a condition')
                        ->defaultItems(1)
                        ->columns(4)
                        ->schema([
                            Select::make('field')
                                ->label('Field')
                                ->options($fieldOptions)
                                ->searchable()
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn (callable $set) => $set('operator', null) + $set('value', null)),

                            Select::make('operator')
                                ->label('Is')
                                ->options(fn (Get $get): array => ($f = RuleRegistry::field((string) $get('field'))) ? RuleRegistry::operatorsFor($f['type']) : [])
                                ->required()
                                ->live(),

                            Select::make('value')
                                ->label('Value')
                                ->options(fn (Get $get): array => ($f = RuleRegistry::field((string) $get('field'))) && isset($f['options']) ? ($f['options'])() : [])
                                ->multiple(fn (Get $get): bool => $get('operator') === 'in')
                                ->searchable()
                                ->visible(fn (Get $get): bool => ($f = RuleRegistry::field((string) $get('field'))) !== null && $f['type'] === RuleRegistry::TYPE_SELECT),
                            // `required` only when shown: a hidden select must not block the save.
                            DatePicker::make('value')
                                ->label('Value')
                                ->native(false)
                                ->visible(fn (Get $get): bool => ($f = RuleRegistry::field((string) $get('field'))) !== null && $f['type'] === RuleRegistry::TYPE_DATE && $get('operator') !== 'between'),
                            TextInput::make('value')
                                ->label(fn (Get $get): string => $get('operator') === 'between' ? 'From, to' : 'Value')
                                ->placeholder(fn (Get $get): string => match (true) {
                                    $get('operator') === 'between' => '2, 5',
                                    ($f = RuleRegistry::field((string) $get('field'))) !== null && $f['type'] === RuleRegistry::TYPE_RATIO => '0.9 for 90%',
                                    default => '',
                                })
                                ->visible(fn (Get $get): bool => ($f = RuleRegistry::field((string) $get('field'))) !== null
                                    && ! in_array($f['type'], [RuleRegistry::TYPE_SELECT], true)
                                    && ! ($f['type'] === RuleRegistry::TYPE_DATE && $get('operator') !== 'between')),

                            Select::make('window')
                                ->label('Over')
                                ->options(Window::LABELS)
                                ->default(Window::THIS_MONTH)
                                ->visible(fn (Get $get): bool => RuleRegistry::isMetric((string) $get('field'))),
                        ]),
                ]),

            Actions::make([
                static::previewAction($statePath),
            ]),
        ];
    }

    public static function previewAction(string $statePath = 'rule'): Action
    {
        return Action::make('whoMatches')
            ->label('Who matches today')
            ->icon('heroicon-o-users')
            ->color('gray')
            ->modalHeading('Who matches today')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(function (Get $get) use ($statePath): HtmlString {
                $rule = Rule::from($get($statePath));

                if ($rule->isEmpty()) {
                    return new HtmlString('<p class="text-sm text-gray-500">No complete condition yet. Pick a field, an operator and a value.</p>');
                }

                $people = app(RuleEvaluator::class)->matching($rule);

                if ($people->isEmpty()) {
                    return new HtmlString('<p class="text-sm">Nobody matches today.</p>');
                }

                $items = $people->map(fn ($e): string => '<li>'.e($e->display_label).'</li>')->implode('');

                return new HtmlString('<p class="text-sm font-medium mb-2">'.$people->count().' '.($people->count() === 1 ? 'person' : 'people').'</p><ul class="list-disc pl-5 text-sm">'.$items.'</ul>');
            });
    }
}
