{{--
    The branded header shared by the income certificate and the experience letter.
    Styles live in `partials/letterhead-styles`, included from the document's <head>.

    @param array  $company  from App\Support\CompanyLetterhead::data()
    @param string $title    the document's own title, printed in the payslip's green
--}}
@php
    $contact = array_filter([$company['phone'] ?? null, $company['email'] ?? null, $company['website'] ?? null]);
    $numbers = array_filter([
        ($company['registration_no'] ?? null) ? 'Incorporation No.: '.$company['registration_no'] : null,
        ($company['ntn'] ?? null) ? 'NTN: '.$company['ntn'] : null,
    ]);
@endphp
<table class="lh">
    <tr>
        <td>
            <div class="lh-bars">
                <span class="lh-bar lh-bar-1"></span><span class="lh-bar lh-bar-2"></span><span class="lh-bar lh-bar-3"></span>
            </div>
            <div class="lh-name">{{ $company['legal_name'] }}</div>
            @if ($numbers)
                <div class="lh-sub">{{ implode('  |  ', $numbers) }}</div>
            @endif
        </td>
        <td class="lh-right">
            @if ($company['address'])
                <div class="line">{{ $company['address'] }}</div>
            @endif
            @if ($contact)
                <div class="line">{{ implode('  |  ', $contact) }}</div>
            @endif
            @if (($title ?? '') !== '')
                <div class="lh-title">{{ $title }}</div>
            @endif
        </td>
    </tr>
</table>

<div class="lh-divider"></div>
