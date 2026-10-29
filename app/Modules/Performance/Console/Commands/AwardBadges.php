<?php

namespace App\Modules\Performance\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Performance\Services\Badges;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

class AwardBadges extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'badges:award {--tenant=*} {--date= : Judge the rules as of this date}';

    protected $description = 'Award every rule-bearing badge to the people its rule matches today';

    public function handle(Badges $badges): int
    {
        if ($this->skipsDisabledModule('performance')) {
            return self::SUCCESS;
        }

        $result = $badges->awardByRule($this->option('date') ? Carbon::parse($this->option('date')) : null);

        $this->info("{$result['awarded']} badge(s) awarded across {$result['badges']} rule(s).");

        return self::SUCCESS;
    }
}
