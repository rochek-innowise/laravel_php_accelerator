{{-- "Which family member am I acting as, here?" — scoped to the current organisation, so a child
     who trains elsewhere does not appear in this one. --}}
<div>
    @if ($visible)
        <div class="flex flex-col gap-1.5">
            <label for="playerContext" class="font-mono text-[0.65rem] font-bold uppercase tracking-widest text-field/70">
                Training as
            </label>

            <select id="playerContext" class="control" wire:change="switch($event.target.value)">
                @foreach ($profiles as $profile)
                    <option value="{{ $profile->id }}" @selected($current?->id === $profile->id)>
                        {{ $profile->name }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif
</div>
