<?php

namespace App\Modules\Devices\Filament\Settings;

use App\Modules\Core\Models\Company;
use App\Modules\Devices\Models\AttendanceDevice;
use App\Modules\Devices\Services\DevicePunchFolder;
use App\Support\Contracts\SettingsSection;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Illuminate\Support\HtmlString;

/**
 * The device registry, from a tenant page — docs/saas-launch-plan.md §8.7. A landlord
 * write from Company Settings, which company_modules already is. Beside it the
 * unmapped list: PINs punching with nobody to belong to.
 */
class DevicesSettingsSection implements SettingsSection
{
    public function key(): string
    {
        return 'devices.devices';
    }

    public function components(): array
    {
        return [
            Section::make('Attendance devices')
                ->description('A ZKTeco (or compatible) device in push mode. In the device menu set the cloud server to '
                    .'this address, port 443, and register its serial number here; punches then arrive as they happen '
                    .'and fold into attendance days every few minutes.')
                ->visible(fn (): bool => modules()->enabled('devices'))
                ->schema([
                    Placeholder::make('devices_endpoint')
                        ->label(__('Cloud server address'))
                        ->content(fn (): string => parse_url(url('/'), PHP_URL_HOST) ?: url('/')),

                    Repeater::make('devices_registry')
                        ->label(__('Devices'))
                        ->schema([
                            TextInput::make('serial_no')->label(__('Serial number'))->required()->maxLength(64),
                            TextInput::make('name')->required()->maxLength(80)->placeholder('Main gate'),
                            TextInput::make('model')->maxLength(80)->placeholder('ZKTeco K40'),
                            Toggle::make('is_active')->label(__('Active'))->default(true),
                            Placeholder::make('last_seen')
                                ->label(__('Last seen'))
                                ->content(fn ($get): string => ($seen = $get('last_seen_at')) ? \Illuminate\Support\Carbon::parse($seen)->diffForHumans() : 'never'),
                        ])
                        ->columns(5)
                        ->addActionLabel('Register a device')
                        ->defaultItems(0),

                    Placeholder::make('devices_unmapped')
                        ->label(__('Punching with no employee'))
                        ->content(function (): HtmlString {
                            $rows = app(DevicePunchFolder::class)->unmapped();

                            if ($rows->isEmpty()) {
                                return new HtmlString('<span class="text-gray-500">Every PIN that has punched belongs to an employee.</span>');
                            }

                            $items = $rows->map(fn ($r): string => '<li>PIN <strong>'.e($r->device_pin).'</strong> — '.(int) $r->punches.' punch(es), last '.e((string) $r->last_punch).'</li>')->implode('');

                            return new HtmlString('<ul class="list-disc pl-5">'.$items.'</ul><p class="text-sm text-gray-500 mt-2">Enter the PIN on the employee\'s record (Device PIN) and the punches fold on the next run.</p>');
                        }),
                ]),
        ];
    }

    public function fill(): array
    {
        $company = $this->company();

        return [
            'devices_registry' => $company
                ? AttendanceDevice::query()->where('company_id', $company->getKey())->orderBy('id')
                    ->get(['serial_no', 'name', 'model', 'is_active', 'last_seen_at'])
                    ->map(fn (AttendanceDevice $d): array => ['serial_no' => $d->serial_no, 'name' => $d->name, 'model' => $d->model, 'is_active' => $d->is_active, 'last_seen_at' => $d->last_seen_at?->toDateTimeString()])
                    ->all()
                : [],
        ];
    }

    public function save(array $state): void
    {
        if (! array_key_exists('devices_registry', $state) || ! ($company = $this->company())) {
            return;
        }

        $kept = [];

        foreach ((array) $state['devices_registry'] as $row) {
            $serial = trim((string) ($row['serial_no'] ?? ''));

            if ($serial === '') {
                continue;
            }

            $device = AttendanceDevice::query()->firstOrNew(['serial_no' => $serial]);

            // A serial registered by another company is theirs; this company's row is refused silently
            // rather than stolen, and the serial appears in the next fill under its owner only.
            if ($device->exists && (int) $device->company_id !== (int) $company->getKey()) {
                continue;
            }

            $device->fill([
                'company_id' => $company->getKey(),
                'name' => trim((string) ($row['name'] ?? '')) ?: 'Device',
                'model' => trim((string) ($row['model'] ?? '')) ?: null,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ])->save();

            $kept[] = $device->getKey();
        }

        AttendanceDevice::query()->where('company_id', $company->getKey())->whereNotIn('id', $kept)->delete();
    }

    private function company(): ?Company
    {
        $company = Company::current();

        if ($company === null) {
            $tenant = Filament::getTenant();
            $company = $tenant instanceof Company ? $tenant : null;
        }

        return $company;
    }
}
