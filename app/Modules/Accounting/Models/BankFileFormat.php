<?php

namespace App\Modules\Accounting\Models;

use App\Models\TenantModel as Model;
use App\Support\Replication\Replicable;
use App\Support\Replication\ReplicatesRecords;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bank's file, as a template — docs/saas-launch-plan.md §14.1. */
class BankFileFormat extends Model implements Replicable
{
    use Auditable, ReplicatesRecords;

    public const KIND_IPAYMENTS = 'ipayments';

    public const KIND_TEMPLATE = 'template';

    protected $fillable = [
        'name', 'kind', 'company_bank_account_id', 'header_template', 'row_template', 'trailer_template',
        'line_ending', 'extension', 'previewed_at', 'is_active',
    ];

    protected $casts = ['previewed_at' => 'datetime', 'is_active' => 'boolean'];

    public function companyBankAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class);
    }

    public function isTemplate(): bool
    {
        return $this->kind === self::KIND_TEMPLATE;
    }

    /** A designed format is previewed against the real rows before it is downloaded; a change needs a fresh look. */
    public function needsPreview(): bool
    {
        return $this->isTemplate() && ($this->previewed_at === null || ($this->updated_at !== null && $this->updated_at->gt($this->previewed_at)));
    }

    public function lineEnding(): string
    {
        return $this->line_ending === 'lf' ? "\n" : "\r\n";
    }

    /** The account's own format, the company default, or the shipped iPayments writer. */
    public static function forAccount(?int $companyBankAccountId): self
    {
        $own = $companyBankAccountId
            ? static::query()->where('is_active', true)->where('company_bank_account_id', $companyBankAccountId)->first()
            : null;

        // A designed default outranks the shipped row: 'template' sorts after 'ipayments'.
        return $own
            ?? static::query()->where('is_active', true)->whereNull('company_bank_account_id')->orderByDesc('kind')->orderBy('id')->first()
            ?? new self(['name' => 'Standard Chartered iPayments', 'kind' => self::KIND_IPAYMENTS]);
    }

    // ─── Replicate — docs/replicate-records.md ───

    public function replicationResets(): array
    {
        return ['company_bank_account_id' => null, 'previewed_at' => null];
    }
}
