<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Setting;
use App\Support\TenantSettings;

/**
 * Checks the payroll account-code mapping against the chart of accounts, and
 * repairs the cases where falling back to the shipped default is provably safe.
 *
 * A bad code is otherwise only discovered when PayrollPostingService reaches it,
 * which means a payslip save fails for whoever happened to trigger it —
 * production hit exactly that with basic_wage pointing at a non-existent 50000.
 *
 * Kept out of the console command so it can be tested without Spatie's
 * TenantAware wrapper, which needs a real per-tenant database connection.
 */
class PayrollAccountAudit
{
    public const SETTING_KEY = 'accounting.payroll_accounts';

    /**
     * One row per payroll line: the code in force, where it came from, and the
     * account it resolves to (null when it resolves to nothing).
     *
     * @return array<int, array{key: string, code: string|null, source: string, account: Account|null}>
     */
    public function report(): array
    {
        $overrides = $this->overrides();
        $effective = (array) setting(self::SETTING_KEY);

        return collect(array_keys((array) config(self::SETTING_KEY)))
            ->map(function (string $key) use ($overrides, $effective): array {
                $code = $effective[$key] ?? null;

                return [
                    'key' => $key,
                    'code' => filled($code) ? (string) $code : null,
                    'source' => array_key_exists($key, $overrides) && filled($overrides[$key]) ? 'override' : 'default',
                    'account' => filled($code) ? Account::where('code', $code)->first() : null,
                ];
            })
            ->all();
    }

    /**
     * Payroll lines whose code does not exist in this chart.
     *
     * @return array<string, string|null> key => code
     */
    public function broken(): array
    {
        return collect($this->report())
            ->filter(fn (array $row) => $row['account'] === null)
            ->mapWithKeys(fn (array $row) => [$row['key'] => $row['code']])
            ->all();
    }

    /**
     * Drop overrides that point at a missing code, so the shipped default takes
     * over again.
     *
     * A line is left alone when the default is missing from this chart too: that
     * is not a typo but a chart using different numbering, and guessing an
     * account would post payroll to the wrong place.
     *
     * @return array{cleared: array<string, array{0: string|null, 1: string}>, unfixable: array<string, string|null>}
     */
    public function repair(): array
    {
        $defaults = (array) config(self::SETTING_KEY);
        $overrides = $this->overrides();
        $cleared = [];
        $unfixable = [];

        foreach ($this->broken() as $key => $code) {
            $default = $defaults[$key] ?? null;

            $canFallBack = array_key_exists($key, $overrides)
                && filled($default)
                && Account::where('code', $default)->exists();

            if (! $canFallBack) {
                $unfixable[$key] = $code;

                continue;
            }

            unset($overrides[$key]);
            $cleared[$key] = [$code, (string) $default];
        }

        if ($cleared !== []) {
            app(TenantSettings::class)->set(self::SETTING_KEY, $overrides);
        }

        return ['cleared' => $cleared, 'unfixable' => $unfixable];
    }

    /**
     * The stored override map, if any.
     *
     * Read from the settings row rather than through setting(), because that
     * merges the config defaults in and would hide which keys are overridden.
     *
     * @return array<string, mixed>
     */
    protected function overrides(): array
    {
        return (array) (Setting::where('key', self::SETTING_KEY)->first()?->value ?? []);
    }
}
