<?php

namespace App\Modules\Quotations;

use App\Modules\Quotations\Console\Commands\ExpireQuotations;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationLine;
use App\Modules\Quotations\Policies\QuotationLinePolicy;
use App\Modules\Quotations\Policies\QuotationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class QuotationsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        Quotation::class => QuotationPolicy::class,
        QuotationLine::class => QuotationLinePolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->loadRoutesFrom(__DIR__.'/routes/console.php');

        // Laravel only auto-discovers commands in app/Console/Commands, so a command in a
        // module has to be registered here or it disappears from artisan — and from the
        // scheduler, silently.
        $this->commands([ExpireQuotations::class]);
    }
}
