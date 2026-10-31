{{-- The side sheet's title row — App\Filament\Support\RecordSheetActions. Rendered inside Filament's modal heading. --}}
<span class="fi-record-sheet-title">{{ $title }}</span>
@if ($badge)
    <span class="fi-record-sheet-badge" data-tone="{{ $badge['tone'] }}">{{ $badge['label'] }}</span>
@endif
