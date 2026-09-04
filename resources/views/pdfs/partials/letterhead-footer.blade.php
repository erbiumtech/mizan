{{--
    The payslip's green footer bar, shared by the letters.

    Company identity only — who sent this and how to reach them — because that is what a recipient looks
    down the page for. The document's own reference is in the header's meta row, where they read it first.

    @param array $company  from App\Support\CompanyLetterhead::data()
--}}
@php
    $footerContact = array_filter([
        ($company['phone'] ?? null) ? 'Phone: '.$company['phone'] : null,
        $company['email'] ?? null,
        $company['website'] ?? null,
    ]);
@endphp
<div class="footer-bar">
    <table>
        <tr>
            <td>
                <strong>{{ strtoupper($company['legal_name']) }}</strong>
                @if ($company['address'])
                    <br>{{ $company['address'] }}
                @endif
            </td>
            <td class="right">
                @foreach ($footerContact as $line)
                    {{ $line }}@if (! $loop->last)<br>@endif
                @endforeach
            </td>
        </tr>
    </table>
</div>
