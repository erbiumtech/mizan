@extends('layouts.public')
@section('title', 'Document verification')
@section('body')
    <h1>Document verification</h1>
    <p class="muted">{{ $companyName }} · {{ $kind }} · checked {{ $checkedAt }}</p>

    @if($verdict === \App\Support\DocumentVerification::MATCHES)
        <div class="card" style="border-color:#16a34a">
            <p style="font-weight:600;margin:0 0 .5rem">This document matches the company's record.</p>
            <table style="width:100%;font-size:.95rem">
                @foreach($facts as $label => $value)
                    <tr><th style="text-align:left;padding:.25rem .5rem .25rem 0;color:var(--muted);font-weight:500">{{ \Illuminate\Support\Str::headline((string) $label) }}</th><td style="padding:.25rem 0">{{ is_numeric($value) ? number_format((float) $value, 2) : $value }}</td></tr>
                @endforeach
            </table>
        </div>
    @elseif($verdict === \App\Support\DocumentVerification::MISMATCH)
        <div class="card" style="border-color:#dc2626">
            <p style="font-weight:600;margin:0 0 .5rem">This document does not match the company's record.</p>
            <p class="muted">The record exists, but what is printed is not what it says now. Ask the company for a fresh copy.</p>
        </div>
    @else
        <div class="card" style="border-color:#dc2626">
            <p style="font-weight:600;margin:0 0 .5rem">No such document.</p>
            <p class="muted">The company has no record this code points at.</p>
        </div>
    @endif
@endsection
