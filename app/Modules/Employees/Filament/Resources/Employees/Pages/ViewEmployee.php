<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Pages;

use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Employees\Filament\Resources\Employees\Schemas\EmployeeInfolist;
use App\Modules\Employees\Models\Employee;
use App\Support\Pdf\Pdf;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    public function infolist(Schema $schema): Schema
    {
        return EmployeeInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label('Download PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->action(function (Employee $record) {
                    $fileName = 'employees/employee-'.$record->id.'-'.time().'.pdf';

                    Storage::disk('public')->makeDirectory('employees');

                    Pdf::view('pdfs.employee', ['employee' => $record->load('user', 'bank', 'manager.user')])
                        ->format('a4')
                        ->save(Storage::disk('public')->path($fileName));

                    Notification::make()->title('PDF generated.')->success()->send();

                    return redirect()->away(Storage::disk('public')->url($fileName));
                }),

            EditAction::make(),
        ];
    }
}
