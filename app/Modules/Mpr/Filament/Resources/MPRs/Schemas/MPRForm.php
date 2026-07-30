<?php

namespace App\Modules\Mpr\Filament\Resources\MPRs\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class MPRForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('mpr_date')
                    ->label('Date')
                    ->required()
                    ->default(now())
                    ->displayFormat('d-m-Y'),

                RichEditor::make('feedback')
                    ->label('Feedback')
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),

                RichEditor::make('topics_scope')
                    ->label('Topics & Scope')
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),

                RichEditor::make('recent_module')
                    ->label('Recent Module')
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),

                RichEditor::make('employee_request')
                    ->label('Employee Request')
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),

                RichEditor::make('next_mpr_goal')
                    ->label('Next Mpr Goal')
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),

                RichEditor::make('current_month_learning')
                    ->label('What have you learnt this month?')
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),
            ]);
    }
}
