@extends('layouts.site')

@section('title', 'Privacy policy · '.config('app.name'))

@section('content')
    <h1>Privacy policy</h1>
    <p>Last updated: {{ \Illuminate\Support\Carbon::parse('2026-10-27')->format('j F Y') }}</p>

    <h2>What the extension does</h2>
    <p>When tracking is switched on for an email, the {{ config('app.name') }} Chrome extension adds a tiny invisible image to that
        email and, if you choose, replaces its links with redirect links. When the recipient's mail program loads the image or a
        link is clicked, our server records that it happened.</p>

    <h2>Data we store about you (the sender)</h2>
    <ul>
        <li>Your name, email address and a hashed password.</li>
        <li>For each tracked email: subject, recipient addresses, send time, and the destination of tracked links.</li>
    </ul>
    <p>We never read, store or send the body of your emails, your inbox, or emails you did not choose to track.</p>

    <h2>Data we store about recipients</h2>
    <ul>
        <li>The time of each open or click.</li>
        <li>The mail program and device type, derived from the browser user agent.</li>
        <li>A one-way salted hash of the IP address, used only to merge duplicate loads. The IP address itself is not stored.</li>
    </ul>
    <p>We do not set cookies on recipients, build profiles across senders, or sell or share data with anyone.</p>

    <h2>Your responsibilities as a sender</h2>
    <p>Some countries, including those in the EU and the UK, require consent before tracking email opens. You can switch tracking
        off for any email in Gmail. You are responsible for using tracking lawfully.</p>

    <h2>Deleting your data</h2>
    <p>Signing out removes the token from your browser. To delete your account and all tracking data, email the address below.</p>

    <h2>Contact</h2>
    <p>{{ config('mail.from.address') }}</p>
@endsection
