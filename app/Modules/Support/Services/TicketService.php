<?php

namespace App\Modules\Support\Services;

use App\Modules\Core\Models\User;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketReply;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Replying to tickets, resolving them, and reporting SLA.
 *
 * The one behaviour worth stating: **replying starts the response clock, and only a
 * customer-visible reply does.** An internal note is staff talking to each other — counting it
 * as a first response would let a company meet its commitment by writing a note to itself,
 * which is the exact failure a measured SLA exists to make visible.
 */
class TicketService
{
    /**
     * Add a reply.
     *
     * Stamps `first_responded_at` on the first customer-visible one, and never again — a
     * reopened ticket keeps the response the customer actually experienced.
     */
    public function reply(Ticket $ticket, string $body, User $author, bool $internal = false): TicketReply
    {
        if (trim($body) === '') {
            throw new InvalidArgumentException('An empty reply is not a reply.');
        }

        $reply = $ticket->replies()->create([
            'body' => trim($body),
            'is_internal' => $internal,
            'author_employee_id' => modules()->enabled('employees')
                ? \App\Modules\Employees\Models\Employee::where('user_id', $author->getKey())->value('id')
                : null,
            'author_name' => $author->name,
        ]);

        if (! $internal && $ticket->first_responded_at === null) {
            $ticket->forceFill(['first_responded_at' => now()])->save();
        }

        // A reply moves a new ticket into open: somebody has picked it up.
        if ($ticket->status === Ticket::STATUS_NEW && ! $internal) {
            $ticket->forceFill(['status' => Ticket::STATUS_OPEN])->save();
        }

        return $reply;
    }

    /**
     * Resolve a ticket.
     *
     * **Never refused for an SLA breach.** The breach is a fact about the past and is reported;
     * refusing to record that the problem was fixed would make the register wrong as well as
     * late.
     */
    public function resolve(Ticket $ticket): Ticket
    {
        if ($ticket->status === Ticket::STATUS_CLOSED) {
            throw new InvalidArgumentException('This ticket is closed. Reopen it before resolving it again.');
        }

        $ticket->forceFill([
            'status' => Ticket::STATUS_RESOLVED,
            'resolved_at' => $ticket->resolved_at ?? now(),
        ])->save();

        return $ticket;
    }

    public function close(Ticket $ticket): Ticket
    {
        $ticket->forceFill([
            'status' => Ticket::STATUS_CLOSED,
            // Resolved is implied by closed: a ticket closed without ever being marked resolved
            // was still resolved at that moment, and leaving the column null would make the
            // resolution SLA unmeasurable for it.
            'resolved_at' => $ticket->resolved_at ?? now(),
            'closed_at' => now(),
        ])->save();

        return $ticket;
    }

    /**
     * Reopen a closed or resolved ticket.
     *
     * `reopened_count` is incremented and the original response time is left alone. "This has
     * been reopened three times" is the useful figure, and it survives the ticket being closed
     * again — which is why it is a counter rather than something inferred from status history.
     */
    public function reopen(Ticket $ticket): Ticket
    {
        if ($ticket->isOpen()) {
            return $ticket;
        }

        $ticket->forceFill([
            'status' => Ticket::STATUS_OPEN,
            'resolved_at' => null,
            'closed_at' => null,
            'reopened_count' => $ticket->reopened_count + 1,
        ])->save();

        return $ticket;
    }

    /**
     * Tickets in breach, or heading for one.
     *
     * Includes unanswered tickets whose time is already up rather than only past failures: a
     * breach that has not finished happening is the one still worth acting on.
     *
     * @return Collection<int, array{ticket: Ticket, note: string}>
     */
    public function breaches(): Collection
    {
        return Ticket::query()
            ->with(['category', 'contact', 'assignee.user'])
            ->open()
            ->get()
            ->map(fn (Ticket $ticket): ?array => $ticket->slaNote()
                ? ['ticket' => $ticket, 'note' => $ticket->slaNote()]
                : null)
            ->filter()
            ->values();
    }

    /**
     * SLA performance over a window, per category.
     *
     * @return array<int, array{category: string, tickets: int, met_response: int, met_resolution: int}>
     */
    public function performance(?string $from = null, ?string $to = null): array
    {
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->endOfMonth()->toDateString();

        return Ticket::query()
            ->with('category')
            ->whereBetween('opened_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->get()
            ->groupBy(fn (Ticket $ticket): string => $ticket->category?->name ?? 'Uncategorised')
            ->map(fn (Collection $group, string $category): array => [
                'category' => $category,
                'tickets' => $group->count(),
                'met_response' => $group->reject(fn (Ticket $t): bool => $t->hasBreachedResponse())->count(),
                'met_resolution' => $group->reject(fn (Ticket $t): bool => $t->hasBreachedResolution())->count(),
            ])
            ->sortByDesc('tickets')
            ->values()
            ->all();
    }
}
