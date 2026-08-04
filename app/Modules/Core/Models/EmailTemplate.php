<?php

namespace App\Modules\Core\Models;

use App\Models\TenantModel as Model;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A company's wording for one notification.
 *
 * Placeholders are written {like_this} and filled from whatever the notification
 * knows: a template naming something the notification does not provide is left as it
 * is rather than blanked, because a visible {employee_name} in a test email is a
 * better failure than a sentence with a hole in it reaching an employee.
 */
class EmailTemplate extends Model
{
    use Auditable;

    /** The notifications that can be overridden, and what each can fill in. */
    public const PLACEHOLDERS = [
        'payslip_issued' => ['employee_name', 'period', 'net_salary', 'company'],
        'payslip_rejected' => ['employee_name', 'period', 'reason', 'company'],
        'expense_claim_submitted' => ['employee_name', 'amount', 'description', 'claimed_on', 'company'],
        'expense_claim_decided' => ['employee_name', 'amount', 'description', 'decision', 'reason', 'company'],
        'employee_change_request' => ['employee_name', 'requester', 'company'],
    ];

    protected $fillable = ['key', 'subject', 'greeting', 'body', 'action_label', 'closing', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The company's override for this notification, if it has written one. */
    public static function for(string $key): ?self
    {
        return static::active()->where('key', $key)->first();
    }

    /**
     * One field with its placeholders filled.
     *
     * Null when the template does not set this field, which the caller reads as "use
     * your own wording" — a company that only wants to change the subject line should
     * not have to retype the body.
     *
     * @param  array<string, string|null>  $values
     */
    public function render(string $field, array $values): ?string
    {
        $text = trim((string) ($this->{$field} ?? ''));

        if ($text === '') {
            return null;
        }

        foreach ($values as $placeholder => $value) {
            if ($value === null) {
                continue;
            }

            $text = str_replace('{'.$placeholder.'}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * The body as paragraphs.
     *
     * @param  array<string, string|null>  $values
     * @return array<int, string>
     */
    public function paragraphs(array $values): array
    {
        $body = $this->render('body', $values);

        if ($body === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R+/', $body))));
    }
}
