<?php

namespace App\Modules\Devices\Models;

use App\Models\TenantModel as Model;
use Illuminate\Database\Eloquent\Builder;

/** One punch, as the device sent it — docs/saas-launch-plan.md §8.7. */
class DevicePunch extends Model
{
    protected $fillable = ['serial_no', 'device_pin', 'punched_at', 'status_code', 'verify_code', 'raw', 'folded_at'];

    protected $casts = [
        'punched_at' => 'datetime',
        'folded_at' => 'datetime',
    ];

    public function scopeUnfolded(Builder $query): Builder
    {
        return $query->whereNull('folded_at');
    }

    /**
     * An ATTLOG line: PIN, time, status, verify, then fields this does not read.
     * Null for a line that is not a punch — a blank, a header, a corrupted row.
     *
     * @return array{device_pin: string, punched_at: string, status_code: ?string, verify_code: ?string, raw: string}|null
     */
    public static function parseLine(string $line): ?array
    {
        $line = trim($line, " \r\n");

        if ($line === '') {
            return null;
        }

        $fields = preg_split('/\t/', $line) ?: [];
        $pin = trim((string) ($fields[0] ?? ''));
        $time = trim((string) ($fields[1] ?? ''));

        if ($pin === '' || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time)) {
            return null;
        }

        return [
            'device_pin' => ltrim($pin, '0') ?: '0',
            'punched_at' => $time,
            'status_code' => isset($fields[2]) && $fields[2] !== '' ? substr(trim($fields[2]), 0, 8) : null,
            'verify_code' => isset($fields[3]) && $fields[3] !== '' ? substr(trim($fields[3]), 0, 8) : null,
            'raw' => substr($line, 0, 255),
        ];
    }
}
