<x-layouts.guest title="Reset your password">
    <h1 class="font-display text-2xl font-bold uppercase tracking-tight text-ink">Reset your password</h1>
    <p class="mt-1 text-sm text-ink-soft">We'll email you a link to set a new password.</p>

    @if ($errors->any())
        <ul role="alert" class="mt-4 space-y-1 rounded-(--radius) border border-line border-l-[3px] border-l-foul bg-paper px-4 py-3 text-sm text-ink">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 flex flex-col gap-4">
        @csrf

        <x-ui.field label="Email" for="email">
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="control">
        </x-ui.field>

        <button type="submit" class="btn">Email password reset link</button>
    </form>
</x-layouts.guest>
