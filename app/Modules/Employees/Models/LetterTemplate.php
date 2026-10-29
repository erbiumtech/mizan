<?php

namespace App\Modules\Employees\Models;

use App\Models\TenantModel as Model;
use App\Support\Locales;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A letter in the company's own words — docs/saas-launch-plan.md §14.7.
 *
 * The body is text with `{placeholders}`, never markup: whatever is typed
 * prints as typed, on the letterhead the shipped letters already use, so one
 * company's letters still look like one company's. One row per kind per
 * language (§8.10), so an Urdu letter is a row rather than a second screen.
 */
class LetterTemplate extends Model
{
    use Auditable;

    /** The letter that carries an address verification code — docs/saas-launch-plan.md §14.8. */
    public const KIND_ADDRESS_VERIFICATION = 'address_verification';

    /** The kinds a company may word for itself, and what each is issued for. */
    public const KINDS = [
        'increment' => 'Increment letter',
        'promotion' => 'Promotion letter',
        'transfer' => 'Transfer letter',
        'confirmation' => 'Confirmation letter',
        'warning' => 'Warning letter',
        'appreciation' => 'Appreciation letter',
        'noc' => 'No-objection certificate',
        'address_verification' => 'Address verification letter',
    ];

    /** The kinds a letter issues itself for, on the event that records the fact. */
    public const EVENT_KINDS = ['increment', 'promotion', 'transfer', 'confirmation'];

    /** What a body may carry. The list is the documentation — there is nothing to parse. */
    public const PLACEHOLDERS = [
        'employee' => "The person's name",
        'code' => 'The verification code, on an address verification letter',
        'address' => 'The address being verified, on an address verification letter',
        'employee_code' => 'Their employee code',
        'designation' => 'Their designation',
        'department' => 'Their department',
        'location' => 'Their location',
        'date_of_joining' => 'The day they joined',
        'company' => "The company's legal name",
        'today' => "Today's date",
        'effective_from' => 'The date the change takes effect',
        'old_value' => 'What it was',
        'new_value' => 'What it becomes',
        'amount' => 'The amount, where the letter is about one',
        'purpose' => 'What the employee asked the letter for',
    ];

    protected $fillable = ['kind', 'locale', 'name', 'subject', 'body', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst(str_replace('_', ' ', (string) $this->kind));
    }

    /** The template for a kind in the document's language, falling back to English then to nothing. */
    public static function for(string $kind, ?string $locale = null): ?self
    {
        $locale ??= Locales::forDocument('letter');

        return static::query()->active()->where('kind', $kind)->where('locale', $locale)->first()
            ?? static::query()->active()->where('kind', $kind)->where('locale', 'en')->first();
    }

    /** @param  array<string, string|null>  $values */
    public function render(array $values): string
    {
        return self::substitute((string) $this->body, $values);
    }

    public function renderSubject(array $values): string
    {
        return self::substitute((string) ($this->subject ?: $this->kindLabel()), $values);
    }

    /**
     * `{placeholder}` and nothing else — docs/saas-launch-plan.md §14.7. An unknown
     * placeholder renders empty rather than printing its own name on a letter that
     * leaves the company.
     *
     * Not named `fill()`: Eloquent already has one, and a static override of it is a
     * fatal error rather than a surprise later.
     *
     * @param  array<string, string|null>  $values
     */
    public static function substitute(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn (array $m): string => (string) ($values[$m[1]] ?? ''), $text);
    }
}
