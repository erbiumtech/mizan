@extends('layouts.public')
@section('title', 'Check your inbox')
@section('body')
    <h1>Check your inbox</h1>
    <div class="card">
        <p>We have sent a confirmation link. Open it and we will set up <strong>{{ $companyName }}</strong> for you.</p>
        <p class="muted">The link works for a day. Nothing is created until you use it. If it has not arrived in a few minutes, check your spam folder, or submit the form again with the same email and we will resend it.</p>
    </div>
@endsection
