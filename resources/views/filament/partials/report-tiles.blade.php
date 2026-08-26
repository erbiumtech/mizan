{{--
    The figures across the top of a report, and the sentence that qualifies them.

    Extracted from the explorer pane so a report's own full page draws the identical thing —
    `docs/reports-expansion-plan.md` Phase 1.2. The five CRM reports are the first to have a page and a
    pane rendering the same payload, and two copies of this markup would drift: the pane would gain a
    tile the page never showed, and the difference would be invisible until somebody compared them.

    Takes `$statement` — any of the four payload kinds. Every one of them carries `tiles`, `note` and
    `balanced`, which is why this is the one part of the pane that needs no branch on the kind.
--}}
<div class="fi-explorer-tiles">
    @foreach ($statement['tiles'] as $tile)
        <div @class(['fi-explorer-tile', 'fi-accent' => $tile['accent']])>
            <span class="fi-explorer-tile-label">{{ $tile['label'] }}</span>
            {{-- Through ReportFigures so a company that reads (1,250) sees it here too — Phase 4.3. --}}
            <span class="fi-explorer-tile-value">{{ \App\Support\Reporting\ReportFigures::money($tile['value']) }}</span>
        </div>
    @endforeach

    {{--
        `fi-warn` where a report says it does not add up. The colour is the whole point: a trial balance
        that is out by a rupee states a note nobody reads unless the note looks wrong.
    --}}
    <div class="fi-explorer-tiles-note">
        <span @class(['fi-explorer-note', 'fi-warn' => ! $statement['balanced']])>{{ $statement['note'] }}</span>
    </div>
</div>
