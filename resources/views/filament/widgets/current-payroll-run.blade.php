{{--
    The payroll run in progress — App\Modules\Payroll\Filament\Widgets\CurrentPayrollRun.
    A filled card: the month, its status and pay day, how many employees have accepted their payslip.
--}}
<x-filament-widgets::widget class="md-dash-payroll">
    @php($run = $this->run())
    <section class="md-dash-panel md-dash-panel--filled">
        @if ($run)
            @php($summary = $this->summary($run))
            <h2 class="md-dash-panel__title">{{ __(':month payroll', ['month' => $summary['label']]) }}</h2>
            <p class="md-dash-panel__meta">
                {{ $summary['status'] }}
                · {{ trans_choice(':count payslip|:count payslips', $summary['payslips'], ['count' => $summary['payslips']]) }}
                @if ($summary['payDay'])
                    · {{ __('pay date :date', ['date' => $summary['payDay']]) }}
                @endif
            </p>

            <div
                class="md-dash-progress"
                role="progressbar"
                aria-label="{{ __('Payslips accepted by employees') }}"
                aria-valuenow="{{ $summary['percent'] }}"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div class="md-dash-progress__bar" style="inline-size: {{ $summary['percent'] }}%"></div>
            </div>

            <div class="md-dash-chips">
                <span class="md-dash-chip md-dash-chip--{{ $summary['statusTone'] }}">{{ $summary['status'] }}</span>
                <span class="md-dash-chip">{{ __(':accepted of :payslips accepted', ['accepted' => $summary['accepted'], 'payslips' => $summary['payslips']]) }}</span>
            </div>

            <div class="md-dash-panel__actions">
                @if ($url = $this->runsUrl())
                    <a href="{{ $url }}" class="md-dash-button md-dash-button--filled" wire:navigate>{{ __('Review run') }}</a>
                @endif
                @if ($url = $this->payslipsUrl())
                    <a href="{{ $url }}" class="md-dash-button md-dash-button--text" wire:navigate>{{ __('Open payroll') }}</a>
                @endif
            </div>
        @else
            <h2 class="md-dash-panel__title">{{ __('Payroll') }}</h2>
            <p class="md-dash-empty">{{ __('No payroll run is in progress.') }}</p>
            @if ($url = $this->runsUrl())
                <div class="md-dash-panel__actions">
                    <a href="{{ $url }}" class="md-dash-button md-dash-button--text" wire:navigate>{{ __('Payroll runs') }}</a>
                </div>
            @endif
        @endif
    </section>
</x-filament-widgets::widget>
