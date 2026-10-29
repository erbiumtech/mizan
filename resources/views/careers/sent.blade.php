@extends('layouts.public')
@section('title', 'Application received')
@section('body')
    <h1>Thank you</h1>
    <div class="card">
        <p>Your application for <strong>{{ $vacancy['title'] }}</strong> at {{ $company['name'] }} has been received. Somebody will be in touch if it goes further.</p>
    </div>
@endsection
