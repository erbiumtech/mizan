<?php

namespace App\Modules\Support\Models;

use App\Models\TenantModel as Model;
use App\Modules\Employees\Models\Employee;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply on a ticket.
 *
 * `is_internal` is staff-only, and the scope below is what makes that enforceable rather than
 * a convention. docs/crms-plan.md §7 keeps the flag against the day a client portal exists;
 * §12.13 asserts no customer-facing query ever returns these — a guard written before the
 * surface it guards, so the portal decision does not later require going through every reply
 * deciding retrospectively which were private.
 */
class TicketReply extends Model
{
    use Auditable;

    protected $fillable = ['ticket_id', 'body', 'is_internal', 'author_employee_id', 'author_name'];

    protected $casts = ['is_internal' => 'boolean'];

    protected $attributes = ['is_internal' => false];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'author_employee_id');
    }

    /**
     * What a customer may see. **The only scope any customer-facing surface may use.**
     *
     * Named for what it is for rather than as `->where('is_internal', false)` at each call
     * site: one place to be wrong, and a name that makes a review notice when somebody reaches
     * past it.
     */
    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /** Who to show as the author, given it may not be an employee. */
    public function authorLabel(): string
    {
        return $this->author?->display_label ?? $this->author_name ?? 'Unknown';
    }
}
