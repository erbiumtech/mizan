<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where each field sits on the paper, in millimetres — docs/saas-launch-plan.md §8.6.
 * One per account, or the default when the account is null; the shipped
 * default is a common Pakistani cheque leaf and the test print is how it is
 * corrected against a real one.
 */
class ChequeLayout extends Model
{
    use Auditable;

    public const FIELDS = ['payee', 'amount_words', 'amount_figures', 'date', 'crossing'];

    protected $fillable = ['company_bank_account_id', 'name', 'page_width_mm', 'page_height_mm', 'fields'];

    protected $casts = [
        'page_width_mm' => 'float',
        'page_height_mm' => 'float',
        'fields' => 'array',
    ];

    public function companyBankAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class);
    }

    /** @return array<string, array{x: float, y: float, w: float, font: float}> */
    public static function defaultFields(): array
    {
        return [
            'date' => ['x' => 138.0, 'y' => 10.0, 'w' => 36.0, 'font' => 11.0],
            'payee' => ['x' => 24.0, 'y' => 26.0, 'w' => 128.0, 'font' => 11.0],
            'amount_words' => ['x' => 24.0, 'y' => 38.0, 'w' => 148.0, 'font' => 10.0],
            'amount_figures' => ['x' => 136.0, 'y' => 52.0, 'w' => 38.0, 'font' => 12.0],
            'crossing' => ['x' => 10.0, 'y' => 4.0, 'w' => 40.0, 'font' => 8.0],
        ];
    }

    /** The layout for an account: its own, the default, or the shipped one, in that order. */
    public static function for(?int $companyBankAccountId): self
    {
        $own = $companyBankAccountId ? static::query()->where('company_bank_account_id', $companyBankAccountId)->first() : null;
        $default = $own ?? static::query()->whereNull('company_bank_account_id')->first();

        return $default ?? new self([
            'name' => 'Shipped default',
            'page_width_mm' => 180.0,
            'page_height_mm' => 80.0,
            'fields' => static::defaultFields(),
        ]);
    }

    /** @return array{x: float, y: float, w: float, font: float} */
    public function field(string $name): array
    {
        return array_merge(static::defaultFields()[$name] ?? ['x' => 0, 'y' => 0, 'w' => 50, 'font' => 10], (array) ($this->fields[$name] ?? []));
    }
}
