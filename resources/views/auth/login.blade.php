{{-- Markup and styling are intentionally minimal: presentation belongs to coder-frontend. --}}
<x-layouts.guest title="Sign in">
    <h1 class="font-display text-2xl font-bold uppercase tracking-tight text-ink">Sign in</h1>

    @if ($errors->any())
        <ul role="alert" class="mt-4 space-y-1 rounded-(--radius) border border-line border-l-[3px] border-l-foul bg-paper px-4 py-3 text-sm text-ink">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 flex flex-col gap-4">
        @csrf

        <x-ui.field label="Email" for="email">
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="control">
        </x-ui.field>

        <x-ui.field label="Password" for="password">
            <input id="password" name="password" type="password" required autocomplete="current-password" class="control">
        </x-ui.field>

        <label for="remember" class="flex items-center gap-2 text-sm text-ink">
            <input id="remember" name="remember" type="checkbox" class="accent-court">
            Remember me
        </label>

        <button type="submit" class="btn">Sign in</button>
    </form>

    <a href="{{ route('password.request') }}" class="link mt-4 inline-block text-sm">Forgot your password?</a>
</x-layouts.guest>
