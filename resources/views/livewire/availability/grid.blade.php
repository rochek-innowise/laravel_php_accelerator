<div>
    <x-ui.page-head
        eyebrow="{{ $isCoach ? 'Coach' : 'Player' }}"
        title="{{ $isCoach ? 'My Times' : 'Best Times' }}"
        sub="{{ $isCoach ? 'The weekly schedule you are available to coach.' : 'The weekly times you are usually free to train.' }}"
    />

    <x-ui.card class="mt-6">
        @if (! $isCoach)
            <p class="text-sm text-ink-soft">
                @if ($usingDefault)
                    Using your default times.
                @else
                    Custom times for this trainer.
                    <button type="button" wire:click="resetToDefault" wire:confirm="Reset to your default times? This removes the custom times for this trainer." class="btn-ghost">
                        Reset to default
                    </button>
                @endif
            </p>
        @endif

        @if (session('status'))
            <p class="mt-2 text-sm text-ink">{{ session('status') }}</p>
        @endif

        <form wire:submit="save" class="mt-4 grid gap-4">
            @foreach ($ranges as $index => $range)
                <div class="flex flex-wrap items-end gap-3 border-b border-line/50 pb-3">
                    <x-ui.field label="Day" for="ranges.{{ $index }}.day_of_week">
                        <select wire:model="ranges.{{ $index }}.day_of_week" class="control">
                            @foreach (\App\Enums\DayOfWeek::cases() as $day)
                                <option value="{{ $day->value }}">{{ $day->label() }}</option>
                            @endforeach
                        </select>
                        <x-slot:error>@error("ranges.{$index}.day_of_week"){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <x-ui.field label="Start" for="ranges.{{ $index }}.start_time">
                        <input type="time" wire:model="ranges.{{ $index }}.start_time" class="control">
                        <x-slot:error>@error("ranges.{$index}.start_time"){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <x-ui.field label="End" for="ranges.{{ $index }}.end_time">
                        <input type="time" wire:model="ranges.{{ $index }}.end_time" class="control">
                        <x-slot:error>@error("ranges.{$index}.end_time"){{ $message }}@enderror</x-slot:error>
                    </x-ui.field>

                    <button type="button" wire:click="removeRange({{ $index }})" class="btn-ghost">Remove</button>
                </div>
            @endforeach

            @if (empty($ranges))
                <p class="text-sm text-ink-soft">No times set yet — add one below.</p>
            @endif

            <div class="flex items-center gap-3">
                <button type="button" wire:click="addRange" class="btn-ghost">Add a time range</button>
                <button type="submit" class="btn">Save</button>
            </div>
        </form>
    </x-ui.card>
</div>
