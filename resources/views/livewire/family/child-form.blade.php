<div>
    <x-ui.page-head eyebrow="Family" title="Add a child" sub="Age 1–18. You can add trainers now or later from Family." />

    <x-ui.card class="mt-6">
        <form wire:submit="save" class="grid gap-4">
            <x-ui.field label="Child's name" for="name">
                <input id="name" type="text" wire:model="name" class="control" required>
                <x-slot:error>@error('name'){{ $message }}@enderror</x-slot:error>
            </x-ui.field>

            <x-ui.field label="Date of birth" for="birth_date">
                <input id="birth_date" type="date" wire:model="birth_date" class="control" required>
                <x-slot:error>@error('birth_date'){{ $message }}@enderror</x-slot:error>
            </x-ui.field>

            @if ($duplicateDetected)
                <div class="rounded-(--radius) border border-line bg-paper px-4 py-3 text-sm text-ink">
                    <p>{{ $errors->first('name') }}</p>
                    <label class="mt-2 flex items-center gap-2">
                        <input type="checkbox" wire:model="confirmDuplicate" class="size-4 rounded border-line">
                        <span>This is a different child — add anyway</span>
                    </label>
                </div>
            @endif

            <x-ui.field label="School (optional)" for="school">
                <input id="school" type="text" wire:model="school" class="control">
                <x-slot:error>@error('school'){{ $message }}@enderror</x-slot:error>
            </x-ui.field>

            <x-ui.field label="Jersey number (optional)" for="jersey_number">
                <input id="jersey_number" type="text" wire:model="jersey_number" class="control">
                <x-slot:error>@error('jersey_number'){{ $message }}@enderror</x-slot:error>
            </x-ui.field>

            <x-ui.field label="Emergency contact (optional)" for="emergency_contact">
                <textarea id="emergency_contact" wire:model="emergency_contact" class="control" rows="2"></textarea>
                <x-slot:error>@error('emergency_contact'){{ $message }}@enderror</x-slot:error>
            </x-ui.field>

            @if ($availableTrainers->count() === 1)
                <label class="flex items-center gap-2 text-sm text-ink">
                    <input type="checkbox" wire:model="singleTrainerJoins" class="size-4 rounded border-line">
                    <span>Join {{ $availableTrainers->first()->business_name }} now</span>
                </label>
            @elseif ($availableTrainers->count() > 1)
                <fieldset>
                    <legend class="text-sm font-medium text-ink">Which trainers will this child join?</legend>

                    <div class="mt-2 space-y-2">
                        @foreach ($availableTrainers as $trainer)
                            <label class="flex items-center gap-3 text-sm text-ink">
                                <input
                                    type="checkbox"
                                    value="{{ $trainer->id }}"
                                    wire:model="selectedTrainerIds"
                                    class="size-4 rounded border-line"
                                >
                                <span>{{ $trainer->business_name }}</span>
                            </label>
                        @endforeach
                    </div>

                    <p class="mt-2 text-sm text-ink-soft">Leave all unchecked to decide later.</p>
                </fieldset>
            @endif

            <label class="flex items-center gap-2 text-sm text-ink">
                <input type="checkbox" wire:model.live="wantsLogin" class="size-4 rounded border-line">
                <span>Give this child their own login</span>
            </label>

            @if ($wantsLogin)
                <div class="grid gap-4 border-l-2 border-line pl-4">
                    <x-ui.field label="Child's email" for="email">
                        <input id="email" type="email" wire:model="email" class="control">
                        <x-slot:error>@error('email'){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <x-ui.field label="Password" for="password">
                        <input id="password" type="password" wire:model="password" class="control">
                        <x-slot:error>@error('password'){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <x-ui.field label="Confirm password" for="password_confirmation">
                        <input id="password_confirmation" type="password" wire:model="password_confirmation" class="control">
                    </x-ui.field>
                </div>
            @endif

            <button type="submit" class="btn mt-2">Add child</button>
        </form>
    </x-ui.card>
</div>
