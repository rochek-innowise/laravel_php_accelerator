{{-- Q-01.05a: verification gates actions, not login, so this page is reachable while signed in. --}}
<x-layouts.app title="Verify your email">
    <h1>Verify your email</h1>

    @if (session('status') === 'verification-link-sent')
        <p role="status">A new verification link has been sent to your email address.</p>
    @endif

    <p>Please confirm your email address by clicking the link we sent you.</p>

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit">Resend verification email</button>
    </form>

    <a href="{{ route('profile') }}">Back to your profile</a>
</x-layouts.app>
