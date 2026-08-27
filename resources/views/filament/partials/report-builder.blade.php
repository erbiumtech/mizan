{{--
    Assembling a report — `docs/reports-expansion-plan.md` Phase 6, item 7.

    A form above the pane's own preview, so the thing being built is drawn by the same renderer that draws
    every other report in this application. Item 7's own words: somebody "assembles a report from a
    **declared** dataset — pick columns, filters, a grouping, what to total — saves it under a name, shares
    it, and reads it in the same pane as every built-in report".

    **Every control here offers what a dataset declared and nothing else.** The subject picker lists the
    subjects this reader may open, the column buttons list that subject's columns, the aggregate picker lists
    the aggregates that column allows. There is no free-text field that reaches a query — the name and the
    description are prose, the search filter is a bound parameter, and the rest are choices from lists the
    registry produced. That is why the boundary needs no validator: nothing here can express a report the
    registry cannot answer.
--}}
<div class="fi-builder">
    <div class="fi-builder-head">
        <div>
            <h2 class="fi-explorer-pane-title">{{ $this->editing ? 'Edit report' : 'New report' }}</h2>
            <p class="fi-explorer-pane-subtitle">
                One subject per report, at most {{ number_format($this->rowCeiling()) }} rows.
            </p>
        </div>

        <div class="fi-builder-actions">
            <button type="button" wire:click="saveReport" class="fi-explorer-open fi-builder-save">
                {{ $this->editing ? 'Save changes' : 'Save report' }}
            </button>

            @if ($this->editing)
                <button
                    type="button"
                    wire:click="deleteReport('{{ $this->editing }}')"
                    wire:confirm="Delete this report? Anybody it is shared with loses it too."
                    class="fi-explorer-open"
                >Delete</button>
            @endif

            <button type="button" wire:click="cancelBuilding" class="fi-explorer-open">Cancel</button>
        </div>
    </div>

    <div class="fi-builder-fields">
        {{-- ------------------------------------------------------------- the subject --}}
        <label class="fi-builder-field">
            <span class="fi-builder-label">Subject</span>
            <select wire:model.live="draft.dataset" class="fi-builder-input">
                <option value="">Choose what this report is about</option>
                @foreach ($this->subjects() as $module => $subjects)
                    <optgroup label="{{ $module }}">
                        @foreach ($subjects as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </label>

        <label class="fi-builder-field">
            <span class="fi-builder-label">Name</span>
            <input type="text" wire:model.blur="draft.name" class="fi-builder-input" placeholder="What people will look for">
        </label>

        <label class="fi-builder-field fi-builder-wide">
            <span class="fi-builder-label">Description</span>
            <input type="text" wire:model.blur="draft.description" class="fi-builder-input" placeholder="What question it answers">
        </label>
    </div>

    @if ($this->subject() === null)
        {{--
            Item 7's refusal, said where somebody would otherwise go looking for the join.

            "A question that needs two subjects joined is a coded report. The builder's answer to it is a
            clear refusal, not a join it cannot secure." The reason is in the plan's *Not doing*: a join the
            registry has not declared is how a payroll figure ends up beside an unrelated employee's name.
        --}}
        <p class="fi-builder-note">
            A report is over one subject. A question that needs two joined — invoices <em>and</em> payslips,
            tickets <em>and</em> timesheets — is a coded report rather than a built one: ask for it, and it
            arrives with tests and a total that reconciles.
        </p>
    @else
        @php($subject = $this->subject())

        {{-- ------------------------------------------------------------- the columns --}}
        <div class="fi-builder-section">
            <span class="fi-builder-label">Columns</span>

            <div class="fi-builder-chips">
                @foreach ($this->subjectColumns() as $column)
                    <button
                        type="button"
                        wire:click="toggleColumn('{{ $column->key }}')"
                        wire:key="col-{{ $column->key }}"
                        @class(['fi-explorer-toggle', 'fi-active' => in_array($column->key, (array) ($this->draft['columns'] ?? []), true)])
                    >{{ $column->label }}</button>
                @endforeach
            </div>

            @if ($this->chosenColumns() !== [])
                {{-- The order the columns were chosen in is the order the report draws them, so it has to be
                     changeable without starting again. --}}
                <div class="fi-builder-chosen">
                    @foreach ($this->chosenColumns() as $index => $column)
                        <span class="fi-explorer-view" wire:key="chosen-{{ $column->key }}">
                            <button type="button" wire:click="moveColumn('{{ $column->key }}', -1)" class="fi-explorer-view-apply" title="Move left" @disabled($index === 0)>‹</button>
                            <span class="fi-builder-chosen-label">{{ $index + 1 }}. {{ $column->label }}</span>
                            <button type="button" wire:click="moveColumn('{{ $column->key }}', 1)" class="fi-explorer-view-apply" title="Move right" @disabled($index === count($this->chosenColumns()) - 1)>›</button>
                            <button type="button" wire:click="toggleColumn('{{ $column->key }}')" class="fi-explorer-view-forget" title="Remove">×</button>
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ------------------------------------------------------- period and filters --}}
        <div class="fi-builder-fields">
            <label class="fi-builder-field">
                <span class="fi-builder-label">Period</span>
                @if ($this->subjectHasPeriod())
                    <select wire:model.live="draft.period" class="fi-builder-input">
                        @foreach ($this->periods() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @else
                    {{-- `Dataset::periodColumn()` is null for a subject a period cannot bound: an employee is
                         a state rather than an event, and a payslip's month is a month *name*. Said out loud
                         rather than offering a picker that would do nothing. --}}
                    <span class="fi-builder-static">Every row — this subject has no date to bound</span>
                @endif
            </label>

            @foreach ($this->subjectFilters() as $filter)
                <label class="fi-builder-field" wire:key="filter-{{ $filter->key }}">
                    <span class="fi-builder-label">{{ $filter->label }}</span>

                    @if ($filter->kind === \App\Support\Reporting\DatasetFilter::SEARCH)
                        <input type="search" wire:model.live.debounce.500ms="draft.filters.{{ $filter->key }}" class="fi-builder-input" placeholder="Contains">
                    @elseif ($filter->kind === \App\Support\Reporting\DatasetFilter::FLAG)
                        <select wire:model.live="draft.filters.{{ $filter->key }}" class="fi-builder-input">
                            <option value="">Either</option>
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    @elseif ($filter->kind === \App\Support\Reporting\DatasetFilter::DATE_RANGE)
                        {{-- A second date range is relative too, because a definition may not hold a date —
                             Phase 6.2's reasoning, and Phase 8 depends on it. --}}
                        <select wire:model.live="draft.filters.{{ $filter->key }}" class="fi-builder-input">
                            <option value="">Any</option>
                            @foreach ($this->periods() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @else
                        <select wire:model.live="draft.filters.{{ $filter->key }}" class="fi-builder-input">
                            <option value="">Any</option>
                            @foreach ($filter->options() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @endif
                </label>
            @endforeach
        </div>

        {{-- --------------------------------------------------- grouping and totalling --}}
        <div class="fi-builder-fields">
            <label class="fi-builder-field">
                <span class="fi-builder-label">Group by</span>
                <select wire:model.live="draft.group_by" class="fi-builder-input">
                    <option value="">A row per record</option>
                    @foreach ($this->subjectColumns() as $column)
                        @if ($column->isGroupable())
                            <option value="{{ $column->key }}">{{ $column->label }}</option>
                        @endif
                    @endforeach
                </select>
            </label>

            <label class="fi-builder-field">
                <span class="fi-builder-label">Sort by</span>
                <select wire:model.live="draft.sort_column" class="fi-builder-input">
                    <option value="">The subject's own order</option>
                    @foreach ($this->subjectColumns() as $column)
                        {{-- Only a real column sorts: a related one would need a join this builder does not
                             write, and a derived one is worked out in PHP after the database has answered. --}}
                        @if ($column->select !== null)
                            <option value="{{ $column->key }}">{{ $column->label }}</option>
                        @endif
                    @endforeach
                </select>
            </label>

            <label class="fi-builder-field">
                <span class="fi-builder-label">Direction</span>
                <select wire:model.live="draft.sort_direction" class="fi-builder-input">
                    <option value="asc">Ascending</option>
                    <option value="desc">Descending</option>
                </select>
            </label>
        </div>

        @if (filled($this->draft['group_by'] ?? null))
            <div class="fi-builder-section">
                <span class="fi-builder-label">Totals per group</span>

                <div class="fi-builder-fields">
                    @foreach ($this->subjectColumns() as $column)
                        @if ($column->aggregates !== [])
                            <label class="fi-builder-field" wire:key="agg-{{ $column->key }}">
                                <span class="fi-builder-label">{{ $column->label }}</span>
                                <select wire:model.live="draft.aggregates.{{ $column->key }}" class="fi-builder-input">
                                    <option value="">Not shown</option>
                                    @foreach ($column->aggregates as $aggregate)
                                        <option value="{{ $aggregate }}">{{ ucfirst($aggregate) }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                    @endforeach
                </div>

                <p class="fi-builder-note">
                    A grouped report shows the group and its totals. A column that is neither grouped on nor
                    totalled has no one value per group, so it is left out rather than shown empty — and only
                    a figure the database can add up may be summed, which is why some columns offer a count
                    and nothing else.
                </p>
            </div>
        @endif

        @if ($this->canShare())
            <label class="fi-builder-check">
                <input type="checkbox" wire:model.live="draft.is_public">
                <span>Share with everybody in this company</span>
            </label>
        @endif
    @endif
</div>
