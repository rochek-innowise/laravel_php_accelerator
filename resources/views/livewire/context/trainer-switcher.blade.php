{{-- G-08: name and logo only. No counts, no badges — an event count here would be one
     organisation's data appearing inside another's context. --}}
<div>
    @if ($visible)
        <div class="flex flex-col gap-1.5">
            <label for="trainerContext" class="font-mono text-[0.65rem] font-bold uppercase tracking-widest text-field/70">
                Organisation
            </label>

            <select id="trainerContext" class="control" wire:change="switch($event.target.value)">
                @foreach ($tenants as $tenant)
                    <option value="{{ $tenant->id }}" @selected($current?->id === $tenant->id)>
                        {{ $tenant->business_name }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif
</div>
