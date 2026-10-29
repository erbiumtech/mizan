@extends('layouts.public')
@section('title', $failed ? 'Something went wrong' : 'Preparing your workspace')
@section('head')
    @unless($failed)
        <meta http-equiv="refresh" content="3">
    @endunless
@endsection
@section('body')
    @if($failed)
        <h1>Something went wrong</h1>
        <div class="card">
            <p>We could not set up <strong>{{ $companyName }}</strong>. This has been reported and somebody will look at it; you will get an email when it is ready.</p>
        </div>
    @else
        <h1>Preparing your workspace</h1>
        <div class="card">
            <p>Setting up <strong>{{ $companyName }}</strong> — its own database, the chart of accounts, the tax slabs for this fiscal year. This takes a few seconds to a minute.</p>
            <p class="muted">This page refreshes on its own.</p>
        </div>
    @endif
@endsection
