<?php

namespace App\Modules\Employees\Filament\Resources\LetterTemplates;

use App\Filament\Concerns\BelongsToModule;
use App\Modules\Employees\Filament\Resources\LetterTemplates\Pages\CreateLetterTemplate;
use App\Modules\Employees\Filament\Resources\LetterTemplates\Pages\EditLetterTemplate;
use App\Modules\Employees\Filament\Resources\LetterTemplates\Pages\ListLetterTemplates;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\LetterTemplate;
use App\Modules\Employees\Services\TemplatedLetters;
use App\Support\Locales;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/** Letter templates — docs/saas-launch-plan.md §14.7. */
class LetterTemplateResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = LetterTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Letter Templates';

    protected static ?string $modelLabel = 'Letter template';

    protected static ?int $navigationSort = 45;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->options(LetterTemplate::KINDS)->required()
                ->helperText('Increment, promotion, transfer and confirmation letters issue themselves when the thing happens. The rest are requested.'),
            Select::make('locale')->label('Language')->options(Locales::options())->default('en')->required()
                ->helperText('One template per kind per language. A letter falls back to English when its language has none.'),
            TextInput::make('name')->required()->maxLength(120)->placeholder('Increment letter, 2026 wording'),
            TextInput::make('subject')->maxLength(190)->placeholder('Revision of salary with effect from {effective_from}'),
            Toggle::make('is_active')->default(true),
            Textarea::make('body')->required()->rows(14)->columnSpanFull()
                ->helperText('Plain text; a blank line starts a new paragraph. It prints on the company letterhead, as the other letters do.'),
            Placeholder::make('placeholders')
                ->label('Placeholders')
                ->columnSpanFull()
                ->content(new HtmlString('<ul class="text-sm grid gap-1 sm:grid-cols-3">'.collect(LetterTemplate::PLACEHOLDERS)
                    ->map(fn (string $what, string $key): string => '<li><code>{'.$key.'}</code> — '.e($what).'</li>')->implode('').'</ul>')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (LetterTemplate $t): string => $t->kindLabel()),
                TextColumn::make('locale')->label('Language')->formatStateUsing(fn (string $state): string => Locales::AVAILABLE[$state]['label'] ?? $state),
                TextColumn::make('subject')->limit(50)->placeholder('—'),
                TextColumn::make('issues')->label('Issues itself')
                    ->state(fn (LetterTemplate $t): string => in_array($t->kind, LetterTemplate::EVENT_KINDS, true) ? 'on the event' : 'on request'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->filters([SelectFilter::make('kind')->options(LetterTemplate::KINDS)])
            ->recordActions([
                Action::make('preview')
                    ->label('Preview')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Preview')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(function (LetterTemplate $record): HtmlString {
                        $employee = Employee::query()->where('is_active', true)->with('user')->orderBy('employee_id')->first();

                        if ($employee === null) {
                            return new HtmlString('<p class="text-sm text-gray-500">Add an employee first; a preview is the template filled in from a real record.</p>');
                        }

                        $values = app(TemplatedLetters::class)->values($employee, ['effective_from' => now()->format('d F Y'), 'old_value' => '100,000.00', 'new_value' => '115,000.00', 'amount' => '115,000.00', 'purpose' => 'a visa application']);

                        return new HtmlString('<p class="text-sm font-medium mb-2">'.e($record->renderSubject($values)).'</p><pre class="text-sm whitespace-pre-wrap">'.e($record->render($values)).'</pre>');
                    }),
                Action::make('download')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (LetterTemplate $record) {
                        $employee = Employee::query()->where('is_active', true)->first();

                        if ($employee === null) {
                            Notification::make()->warning()->title('Add an employee first.')->send();

                            return null;
                        }

                        try {
                            $pdf = app(TemplatedLetters::class)->renderPdf($employee, $record->kind, ['effective_from' => now()->format('d F Y'), 'old_value' => '100,000.00', 'new_value' => '115,000.00']);
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return null;
                        }

                        return response()->streamDownload(fn () => print $pdf->raw(), $pdf->getName(), ['Content-Type' => 'application/pdf']);
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLetterTemplates::route('/'),
            'create' => CreateLetterTemplate::route('/create'),
            'edit' => EditLetterTemplate::route('/{record}/edit'),
        ];
    }
}
