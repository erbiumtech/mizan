<?php

namespace App\Modules\Devices\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Devices\Services\DevicePunchFolder;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

class FoldDevicePunches extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'devices:fold {--tenant=*}';

    protected $description = 'Fold pushed device punches into attendance days — first punch in, last punch out';

    public function handle(DevicePunchFolder $folder): int
    {
        if ($this->skipsDisabledModule('devices')) {
            return self::SUCCESS;
        }

        $result = $folder->fold();

        $this->info("{$result['folded']} punch(es) folded into {$result['days']} day(s).");

        foreach ($result['unmapped'] as $pin => $count) {
            $this->warn("PIN {$pin}: {$count} punch(es) with no employee carrying that PIN.");
        }

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return self::SUCCESS;
    }
}
