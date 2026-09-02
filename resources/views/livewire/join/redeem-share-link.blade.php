<div>
    @if (! $this->isRedeemable())
        <x-ui.card>
            <h1 class="font-display text-2xl font-bold uppercase tracking-tight text-ink">Invitation not valid</h1>
            <p class="mt-3 text-sm text-ink-soft">
                This invitation link is no longer valid. It may have been replaced, already used, or expired.
                Ask your trainer for a fresh link.
            </p>
            <a href="{{ route('login') }}" class="btn mt-6 inline-block">Go to sign in</a>
        </x-ui.card>
    @else
        <x-ui.card>
            <p class="font-mono text-xs font-bold uppercase tracking-widest text-ink-soft">Invitation</p>
            <h1 class="mt-1 font-display text-2xl font-bold uppercase tracking-tight text-ink">
                Join {{ $trainer?->business_name }}
            </h1>

            @auth
                @if ($blocked)
                    <p class="mt-3 text-sm text-ink-soft" role="alert">
                        Ask your parent to register you with this trainer. We've let them know you tried.
                    </p>
                @else
                <p class="mt-3 text-sm text-ink-soft">
                    You are signed in as {{ auth()->user()->email }}. Joining adds this organisation to your
                    account — it never creates a second one.
                </p>

                @if ($profiles->count() > 0)
                    <fieldset class="mt-6">
                        <legend class="text-sm font-medium text-ink">
                            Who will train with {{ $trainer?->business_name }}?
                        </legend>

                        <div class="mt-3 space-y-2">
                            @foreach ($profiles as $profile)
                                <label class="flex items-center gap-3 text-sm text-ink">
                                    <input
                                        type="checkbox"
                                        value="{{ $profile->id }}"
                                        wire:model="selectedProfileIds"
                                        class="size-4 rounded border-line"
                                    >
                                    <span>{{ $profile->id === auth()->user()->playerProfile?->id ? $profile->name.' (Me)' : $profile->name }}</span>
                                </label>
                            @endforeach
                        </div>

                        @error('selectedProfileIds')
                            <p role="alert" class="mt-2 text-sm text-foul">{{ $message }}</p>
                        @enderror
                    </fieldset>
                @endif

                <button type="button" wire:click="join" class="btn mt-6">Join {{ $trainer?->business_name }}</button>
                @endif
            @endauth

            @guest
                <form wire:submit="register" class="mt-6 grid gap-4">
                    <x-ui.field label="First name" for="first_name">
                        <input id="first_name" type="text" wire:model="first_name" class="control" required>
                        <x-slot:error>@error('first_name'){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <x-ui.field label="Last name" for="last_name">
                        <input id="last_name" type="text" wire:model="last_name" class="control" required>
                        <x-slot:error>@error('last_name'){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <x-ui.field label="Email" for="email">
                        <input id="email" type="email" wire:model="email" class="control" required>
                        <x-slot:error>@error('email'){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <x-ui.field label="Phone" for="phone">
                        <input id="phone" type="tel" wire:model="phone" class="control">
                        <x-slot:error>@error('phone'){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <x-ui.field label="Player name" for="player_name">
                        <input id="player_name" type="text" wire:model="player_name" class="control">
                        <x-slot:error>@error('player_name'){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <x-ui.field label="Password" for="password">
                        <input id="password" type="password" wire:model="password" class="control" required>
                        <x-slot:error>@error('password'){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <x-ui.field label="Confirm password" for="password_confirmation">
                        <input id="password_confirmation" type="password" wire:model="password_confirmation" class="control" required>
                    </x-ui.field>

                    <button type="submit" class="btn mt-2">Create account and join</button>
                </form>

                <p class="mt-4 text-sm text-ink-soft">
                    Already have an account? <a href="{{ route('login') }}" class="underline">Sign in</a> and open this link again.
                </p>
            @endguest
        </x-ui.card>
    @endif
</div>
