{{-- Q-01.05a: verification gates actions, not login, so this page is reachable while signed in. --}}
<x-layouts.app title="Verify your email">
    <x-ui.page-head :eyebrow="auth()->user()->role->label()" title="Verify your email" />

    <x-ui.card class="mt-6 max-w-[32rem]">
        @if (session('status') === 'verification-link-sent')
            <p role="status" class="mb-4 text-sm text-ink">A new verification link has been sent to your email address.</p>
        @endif

        <p class="text-sm text-ink-soft">Please confirm your email address by clicking the link we sent you.</p>

        <form method="POST" action="{{ route('verification.send') }}" class="mt-4">
            @csrf
            <button type="submit" class="btn">Resend verification email</button>
        </form>

        <a href="{{ route('profile') }}" class="link mt-4 inline-block text-sm">Back to your profile</a>
    </x-ui.card>
</x-layouts.app>
