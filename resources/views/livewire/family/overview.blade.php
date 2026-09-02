<div>
    <x-ui.page-head eyebrow="Family" title="Family" sub="Everyone in your family and who they train with">
        @if ($canManage)
            <a href="{{ route('family.children.create') }}" wire:navigate class="btn">Add a child</a>
        @endif
    </x-ui.page-head>

    @forelse ($children as $row)
        @php [$profile, $associations, $availableTrainers] = [$row['profile'], $row['associations'], $row['availableTrainers']]; @endphp

        <x-ui.card class="mt-6">
            <h2 class="font-display text-lg font-bold uppercase tracking-tight text-ink">{{ $profile->name }}</h2>

            <table class="mt-4 w-full text-left text-sm">
                <caption class="sr-only">Trainers {{ $profile->name }} is connected to</caption>
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Trainer</th>
                        <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Status</th>
                        <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Connected</th>
                        @if ($canManage)
                            <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($associations as $association)
                        <tr class="border-b border-line/50">
                            <td class="py-2">{{ $association->trainerProfile?->business_name }}</td>
                            <td class="py-2">{{ $association->status->label() }}</td>
                            <td class="py-2">{{ $association->connected_at?->toFormattedDateString() ?? '—' }}</td>
                            @if ($canManage)
                                <td class="py-2 text-right">
                                    @can('delete', $association)
                                        <button
                                            type="button"
                                            wire:click="remove({{ $association->id }})"
                                            wire:confirm="Remove this trainer? Any of {{ $profile->name }}'s upcoming events with them will be cancelled."
                                            class="btn-ghost"
                                        >
                                            Remove
                                        </button>
                                    @endcan
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canManage ? 4 : 3 }}" class="py-4 text-ink-soft">No trainers yet.</td></tr>
                    @endforelse
                </tbody>
            </table>

            @can('manageTrainerAssociations', $profile)
                <div class="mt-4 grid gap-4 border-t border-line pt-4 sm:grid-cols-2">
                    <form wire:submit="addByCode({{ $profile->id }})" class="flex items-end gap-2">
                        <x-ui.field label="Add by invitation code" for="manualCode-{{ $profile->id }}">
                            <input
                                id="manualCode-{{ $profile->id }}"
                                type="text"
                                wire:model="manualCode.{{ $profile->id }}"
                                class="control"
                                placeholder="Invitation code"
                            >
                            <x-slot:error>@error('manualCode.'.$profile->id){{ $message }}@enderror</x-slot:error>
                        </x-ui.field>
                        <button type="submit" class="btn-ghost">Add</button>
                    </form>

                    @if ($availableTrainers->isNotEmpty())
                        <form wire:submit="addTrainer({{ $profile->id }})" class="flex items-end gap-2">
                            <x-ui.field label="Add an existing family trainer" for="pickerTrainerId-{{ $profile->id }}">
                                <select id="pickerTrainerId-{{ $profile->id }}" wire:model="pickerTrainerId.{{ $profile->id }}" class="control">
                                    <option value="">Choose a trainer</option>
                                    @foreach ($availableTrainers as $trainer)
                                        <option value="{{ $trainer->id }}">{{ $trainer->business_name }}</option>
                                    @endforeach
                                </select>
                                <x-slot:error>@error('pickerTrainerId.'.$profile->id){{ $message }}@enderror</x-slot:error>
                            </x-ui.field>
                            <button type="submit" class="btn-ghost">Add</button>
                        </form>
                    @endif
                </div>
            @endcan
        </x-ui.card>
    @empty
        <x-ui.card class="mt-6">
            <p class="text-ink-soft">No family members yet.</p>
        </x-ui.card>
    @endforelse
</div>
