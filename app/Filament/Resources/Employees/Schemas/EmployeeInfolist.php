<?php

namespace App\Filament\Resources\Employees\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Personal')
                ->columns(2)
                ->schema([
                    TextEntry::make('employee_id')->label('Employee ID'),
                    TextEntry::make('user.name')->label('Name'),
                    TextEntry::make('user.email')->label('Company Email'),
                    TextEntry::make('personal_email')->label('Personal Email')->placeholder('—'),
                    TextEntry::make('gender'),
                    TextEntry::make('nic')->label('NIC'),
                    TextEntry::make('date_of_joining')->date(),
                    TextEntry::make('phone'),
                    TextEntry::make('secondary_phone')->label('Secondary Phone')->placeholder('—'),
                    TextEntry::make('address_line_1')->label('Address 1')->placeholder('—'),
                    TextEntry::make('address_line_2')->label('Address 2')->placeholder('—'),
                ]),

            Section::make('NIC Images')
                ->columns(2)
                ->schema([
                    ImageEntry::make('nic_front')->label('NIC (Front)')->disk('public')->visibility('public')->height(180),
                    ImageEntry::make('nic_back')->label('NIC (Back)')->disk('public')->visibility('public')->height(180),
                ]),

            Section::make('Employment')
                ->columns(2)
                ->schema([
                    TextEntry::make('designation')->placeholder('—'),
                    TextEntry::make('department')->placeholder('—'),
                    TextEntry::make('is_active')->label('Status')->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')->badge()->color(fn ($state) => $state ? 'success' : 'gray'),
                    TextEntry::make('manager.display_label')->label('Manager')->placeholder('—'),
                ]),

            Section::make('Bank')
                ->columns(2)
                ->schema([
                    TextEntry::make('bank.bank_name')->label('Bank')->placeholder('—'),
                    TextEntry::make('bank_account_no')->label('Bank A/C No')->placeholder('—'),
                    TextEntry::make('iban_no')->label('IBAN')->placeholder('—'),
                ]),
        ]);
    }
}
