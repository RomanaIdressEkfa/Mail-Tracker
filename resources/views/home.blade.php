@extends('layouts.site')

@section('content')
    <h1>Know when your Gmail emails are opened.</h1>
    <p>{{ config('app.name') }} adds read receipts to Gmail. Install the Chrome extension, open Gmail, and every email you send shows
        ✓ when it's sent and ✓✓ when it's opened, with how many times, when, and which links were clicked.</p>

    <div class="card">
        <h2 style="margin-top:0">How it works</h2>
        <ol>
            <li>Install the {{ config('app.name') }} extension in Chrome and create a free account.</li>
            <li>Write emails in Gmail as usual. Each one gets a tracking toggle next to Send.</li>
            <li>See opens and clicks right in Gmail, plus a desktop notification when someone opens your email.</li>
        </ol>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Free for everyone</h2>
        <p>Unlimited tracked emails, full open history and link clicks. No credit card.</p>
    </div>

    <p class="muted">Server status: <strong>running</strong> · API at <code>{{ url('/api') }}</code></p>
@endsection
