@extends('layouts.public')

@section('title', __('Offline'))

@section('body')
    <h1>{{ __("You're offline") }}</h1>
    <p class="muted">{{ __('Nothing here is stored on this device; the panel needs a connection. It will load again as soon as you have one.') }}</p>
    <div class="card">
        <button type="button" onclick="location.reload()">{{ __('Try again') }}</button>
    </div>
@endsection
