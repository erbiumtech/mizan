<?php

namespace App\Modules\Core\Filament\Resources\EmailTemplates\Schemas;

use App\Modules\Core\Models\EmailTemplate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EmailTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('key')
                ->label('Email')
                ->options(fn (): array => collect(array_keys(EmailTemplate::PLACEHOLDERS))
                    ->mapWithKeys(fn (string $key): array => [
                        $key => ucfirst(str_replace('_', ' ', $key)),
                    ])
                    ->all())
                ->required()
                ->unique(ignoreRecord: true)
                ->live()
                ->helperText('Which email this rewords.'),

            TextInput::make('subject')
                ->maxLength(255)
                ->helperText('Leave empty to keep the subject the application uses.'),

            TextInput::make('greeting')
                ->maxLength(255)
                ->helperText('e.g. "Dear {employee_name}," — empty keeps the default.'),

            Textarea::make('body')
                ->rows(6)
                ->helperText(fn (Get $get): string => 'One paragraph per line. Replaces the whole message body — '
                    .'leave empty to keep the shipped wording. Placeholders available here: '
                    .collect(EmailTemplate::PLACEHOLDERS[$get('key')] ?? [])
                        ->map(fn (string $p): string => '{'.$p.'}')
                        ->implode(', '))
                ->columnSpanFull(),

            Textarea::make('closing')
                ->rows(2)
                ->helperText('Added after the body — a sign-off, or who to reply to.')
                ->columnSpanFull(),

            Toggle::make('is_active')
                ->label('In use')
                ->default(true)
                ->helperText('Switch off to go back to the shipped wording without losing what you wrote.'),
        ]);
    }
}
