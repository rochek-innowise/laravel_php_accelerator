<div>
    <x-ui.page-head eyebrow="Trainer" title="Coaches" sub="Your coaching staff and open invitations" />

    <x-ui.card class="mt-6">
        <form wire:submit="invite" class="grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
            <x-ui.field label="Coach email" for="email">
                <input id="email" type="email" wire:model="email" class="control" required>
                <x-slot:error>@error('email'){{ $message }}@enderror</x-slot:error>
            </x-ui.field>

            <x-ui.field label="Message (optional)" for="note">
                <input id="note" type="text" wire:model="note" class="control">
                <x-slot:error>@error('note'){{ $message }}@enderror</x-slot:error>
            </x-ui.field>

            <button type="submit" class="btn">Invite coach</button>
        </form>
    </x-ui.card>

    <x-ui.card class="mt-6">
        <h2 class="font-display text-lg font-bold uppercase tracking-tight text-ink">Staff</h2>

        <table class="mt-4 w-full text-left text-sm">
            <caption class="sr-only">Coaches in your organisation</caption>
            <thead>
                <tr class="border-b border-line">
                    <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Coach</th>
                    <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Status</th>
                    <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Joined</th>
                    <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($coaches as $coach)
                    <tr class="border-b border-line/50">
                        <td class="py-2">{{ $coach->user?->name }} <span class="text-ink-soft">{{ $coach->user?->email }}</span></td>
                        <td class="py-2">{{ $coach->status->label() }}</td>
                        <td class="py-2">{{ $coach->joined_at?->toFormattedDateString() ?? '—' }}</td>
                        <td class="py-2 text-right">
                            @if ($coach->status === \App\Enums\CoachStatus::Active)
                                <button type="button" wire:click="release({{ $coach->id }})" class="btn-ghost">Release</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-4 text-ink-soft">No coaches yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.card>

    <x-ui.card class="mt-6">
        <h2 class="font-display text-lg font-bold uppercase tracking-tight text-ink">Open invitations</h2>

        <table class="mt-4 w-full text-left text-sm">
            <caption class="sr-only">Coach invitations awaiting acceptance</caption>
            <thead>
                <tr class="border-b border-line">
                    <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Email</th>
                    <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Status</th>
                    <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invitations as $invitation)
                    <tr class="border-b border-line/50">
                        <td class="py-2">{{ $invitation->target_email }}</td>
                        <td class="py-2">{{ $invitation->isExpired() ? 'Expired' : 'Pending' }}</td>
                        <td class="py-2 text-right">
                            <button type="button" wire:click="resend({{ $invitation->id }})" class="btn-ghost">Resend</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-4 text-ink-soft">No open invitations.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.card>
</div>
