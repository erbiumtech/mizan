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
                    ->label(__('User'))
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('mpr_date')
                    ->label(__('Date'))
                    ->required()
                    ->default(now())
                    ->displayFormat('d-m-Y'),

                RichEditor::make('feedback')
                    ->label(__('Feedback'))
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),

                RichEditor::make('topics_scope')
                    ->label(__('Topics & Scope'))
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),

                RichEditor::make('recent_module')
                    ->label(__('Recent Module'))
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),

                RichEditor::make('employee_request')
                    ->label(__('Employee Request'))
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),

                RichEditor::make('next_mpr_goal')
                    ->label(__('Next Mpr Goal'))
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),

                RichEditor::make('current_month_learning')
                    ->label(__('What have you learnt this month?'))
                    ->required()
                    ->fileAttachmentsDisk('public')
                    ->fileAttachmentsDirectory('Mpr')
                    ->columnSpanFull(),
            ]);
    }
}
