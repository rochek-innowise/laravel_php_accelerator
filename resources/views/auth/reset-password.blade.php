<x-layouts.guest title="Set your password">
    <h1 class="font-display text-2xl font-bold uppercase tracking-tight text-ink">Set your password</h1>

    @if ($errors->any())
        <ul role="alert" class="mt-4 space-y-1 rounded-(--radius) border border-line border-l-[3px] border-l-foul bg-paper px-4 py-3 text-sm text-ink">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-ui.field label="Email" for="email">
            <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autocomplete="username" class="control">
        </x-ui.field>

        <x-ui.field label="New password" for="password">
            <input id="password" name="password" type="password" required autofocus autocomplete="new-password" class="control">
        </x-ui.field>

        <x-ui.field label="Confirm password" for="password_confirmation">
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="control">
        </x-ui.field>

        <button type="submit" class="btn">Save password</button>
    </form>
</x-layouts.guest>
