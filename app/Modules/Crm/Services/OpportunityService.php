<?php

namespace App\Modules\Crm\Services;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\LostReason;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\OpportunityStageHistory;
use App\Modules\Crm\Models\PipelineStage;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * Moving deals, and closing them.
 *
 * Every stage change goes through `moveTo()` for one reason: **the history is the product**.
 * A deal whose stage was updated directly leaves no row, so `days_in_stage` is missing for
 * that leg and every velocity figure that reads it is quietly wrong.
 *
 * Winning and losing are their own methods rather than a stage assignment, because each has
 * to record something a stage cannot: an outcome, a close date, and — for a loss — a reason
 * from the table §8's report reads.
 */
class OpportunityService
{
    /**
     * Open a deal in a pipeline's first stage, recording the entry.
     *
     * The first history row has a null `from_stage_id`: a deal enters its first stage from
     * nowhere, and inventing a previous stage would put a phantom leg in every velocity
     * report.
     */
    public function open(Opportunity $opportunity): Opportunity
    {
        return TenantTransaction::run(function () use ($opportunity): Opportunity {
            $opportunity->save();

            $this->recordMove($opportunity, from: null, to: $opportunity->stage, movedBy: auth()->user());

            return $opportunity->refresh();
        });
    }

    /**
     * Move a deal to another stage of the same pipeline.
     *
     * Refuses a cross-pipeline move: the stages of a different process mean different
     * things, and a deal that jumped between them would make both boards lie. Moving a deal
     * to another pipeline is a new deal, deliberately.
     */
    public function moveTo(Opportunity $opportunity, PipelineStage $stage, ?User $by = null): Opportunity
    {
        if ($stage->pipeline_id !== $opportunity->pipeline_id) {
            throw new InvalidArgumentException(
                'That stage belongs to a different pipeline. A deal cannot move between processes — '
                .'open a new one if the deal has genuinely changed shape.'
            );
        }

        if ($stage->getKey() === $opportunity->pipeline_stage_id) {
            return $opportunity;
        }

        if (! $opportunity->isOpen()) {
            throw new InvalidArgumentException(
                "This deal is already {$opportunity->outcome}. Reopen it before moving it."
            );
        }

        $from = $opportunity->stage;

        return TenantTransaction::run(function () use ($opportunity, $stage, $from, $by): Opportunity {
            $opportunity->forceFill([
                'pipeline_stage_id' => $stage->getKey(),
                // The new stage's probability, unless somebody has deliberately overridden
                // this deal's. Overwriting a considered override on every move would make
                // the override useless; keeping a stale one would make the forecast wrong.
                // The stage's figure wins only where the deal was still following it.
                'probability_pct' => $this->probabilityAfterMove($opportunity, $from, $stage),
            ])->save();

            $this->recordMove($opportunity, $from, $stage, $by);

            // A move INTO a terminal stage closes the deal, so a board drag onto Won does
            // what dragging onto Won obviously means rather than leaving an open deal
            // sitting in the won column.
            if ($stage->is_won) {
                $this->markWon($opportunity->refresh(), $by, recordMove: false);
            } elseif ($stage->is_lost) {
                $this->markLost($opportunity->refresh(), null, $by, recordMove: false);
            }

            return $opportunity->refresh();
        });
    }

    /**
     * Win a deal.
     *
     * **Posts nothing, invoices nothing, creates nothing.** §10 lists that three times over
     * because a CRM is where automation is most tempting: a won deal is a sales fact, an
     * invoice is a legal document, and a journal entry is neither. The hand-offs in phase 6
     * are each a separate human action.
     */
    public function markWon(Opportunity $opportunity, ?User $by = null, bool $recordMove = true): Opportunity
    {
        if ($opportunity->isWon()) {
            return $opportunity;
        }

        $stage = $opportunity->pipeline->wonStage();

        return TenantTransaction::run(function () use ($opportunity, $stage, $by, $recordMove): Opportunity {
            $from = $opportunity->stage;

            $opportunity->forceFill([
                'outcome' => Opportunity::OUTCOME_WON,
                'closed_on' => now()->toDateString(),
                'probability_pct' => 100,
                'lost_reason_id' => null,
                'pipeline_stage_id' => $stage?->getKey() ?? $opportunity->pipeline_stage_id,
            ])->save();

            if ($recordMove && $stage && $from && $stage->getKey() !== $from->getKey()) {
                $this->recordMove($opportunity, $from, $stage, $by);
            }

            activity('Opportunity')
                ->performedOn($opportunity)
                ->causedBy($by ?? auth()->user())
                ->event('won')
                ->withProperties(['amount' => (float) $opportunity->amount, 'party' => $opportunity->partyLabel()])
                ->log("Deal won: {$opportunity->title}");

            return $opportunity->refresh();
        });
    }

    /**
     * Lose a deal, with a reason.
     *
     * The reason is why `lost_reasons` is a table: win/loss by reason is the report §8 says
     * is worth more than the forecast, and it cannot be produced from a blank.
     */
    public function markLost(
        Opportunity $opportunity,
        ?LostReason $reason,
        ?User $by = null,
        bool $recordMove = true,
    ): Opportunity {
        if ($opportunity->isWon()) {
            throw new InvalidArgumentException(
                'This deal was won. Losing the customer later is a lost renewal, which is its own deal.'
            );
        }

        $stage = $opportunity->pipeline->lostStage();

        return TenantTransaction::run(function () use ($opportunity, $reason, $stage, $by, $recordMove): Opportunity {
            $from = $opportunity->stage;

            $opportunity->forceFill([
                'outcome' => Opportunity::OUTCOME_LOST,
                'closed_on' => now()->toDateString(),
                'probability_pct' => 0,
                'lost_reason_id' => $reason?->getKey(),
                'pipeline_stage_id' => $stage?->getKey() ?? $opportunity->pipeline_stage_id,
            ])->save();

            if ($recordMove && $stage && $from && $stage->getKey() !== $from->getKey()) {
                $this->recordMove($opportunity, $from, $stage, $by);
            }

            return $opportunity->refresh();
        });
    }

    /** Put a closed deal back in play. Deals do come back. */
    public function reopen(Opportunity $opportunity, PipelineStage $stage, ?User $by = null): Opportunity
    {
        if ($opportunity->isOpen()) {
            return $opportunity;
        }

        return TenantTransaction::run(function () use ($opportunity, $stage, $by): Opportunity {
            $from = $opportunity->stage;

            $opportunity->forceFill([
                'outcome' => null,
                'closed_on' => null,
                'lost_reason_id' => null,
                'pipeline_stage_id' => $stage->getKey(),
                'probability_pct' => $stage->probability_pct,
            ])->save();

            $this->recordMove($opportunity, $from, $stage, $by);

            return $opportunity->refresh();
        });
    }

    /**
     * Write the history row, with how long the deal sat where it was.
     *
     * `days_in_stage` is measured from the previous move, falling back to creation for the
     * first one. Recorded on every move including backwards ones — a deal that went to
     * Proposal, back to Qualification and forward again spent real time in each, and
     * overwriting would report the round trip as one fast passage.
     */
    private function recordMove(
        Opportunity $opportunity,
        ?PipelineStage $from,
        ?PipelineStage $to,
        ?User $movedBy,
    ): void {
        if (! $to) {
            return;
        }

        $previousMove = $opportunity->stageHistory()->latest('moved_at')->first();
        $since = $previousMove?->moved_at ?? $opportunity->created_at ?? now();

        OpportunityStageHistory::create([
            'opportunity_id' => $opportunity->getKey(),
            'from_stage_id' => $from?->getKey(),
            'to_stage_id' => $to->getKey(),
            'moved_by' => ($movedBy ?? auth()->user())?->getKey(),
            'moved_at' => now(),
            'days_in_stage' => $from ? (int) $since->diffInDays(now()) : null,
        ]);
    }

    /**
     * The probability after a move.
     *
     * The new stage's figure when the deal was still following its old stage's, and the
     * deal's own when somebody has deliberately set it. Overwriting a considered override on
     * every move makes the override pointless; keeping a stale one makes the forecast wrong.
     */
    private function probabilityAfterMove(Opportunity $opportunity, ?PipelineStage $from, PipelineStage $to): int
    {
        $wasFollowingStage = $from === null
            || (int) $opportunity->probability_pct === (int) $from->probability_pct;

        return $wasFollowingStage ? (int) $to->probability_pct : (int) $opportunity->probability_pct;
    }
}
