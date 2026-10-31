<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a visitor said through the contact form — docs/company-website-plan.md W4.
 *
 * Landlord: there is no company on the public side, and the people who read these are the
 * installation's super admins. Two states only. A message is new until somebody marks it
 * handled; "replied" and "spam" were in the first draft and would have been two more things
 * to keep up to date about a form that gets a message a week.
 */
class ContactMessage extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_HANDLED = 'handled';

    protected $fillable = ['name', 'email', 'company', 'message', 'ip_address', 'status', 'handled_by', 'handled_at'];

    protected $casts = ['handled_at' => 'datetime'];

    protected $attributes = ['status' => self::STATUS_NEW];

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeNew(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_NEW);
    }

    public function markHandled(User $by): void
    {
        $this->forceFill([
            'status' => self::STATUS_HANDLED,
            'handled_by' => $by->getKey(),
            'handled_at' => now(),
        ])->save();
    }
}
