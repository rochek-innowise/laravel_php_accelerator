<div>
    <x-ui.page-head eyebrow="Admin" title="Impersonation history" sub="Every session, started or still open" />

    <x-ui.card class="mt-6">
        <form role="search" wire:submit.prevent class="flex flex-col gap-1.5 sm:max-w-sm">
            <label for="targetEmail" class="font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Filter by target email</label>
            <input id="targetEmail" type="search" wire:model.live.debounce.400ms="targetEmail" placeholder="every session against this user" class="control">
        </form>
    </x-ui.card>

    <x-ui.card class="mt-6">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Impersonation history</caption>
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">Admin</th>
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">Target</th>
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">Started</th>
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">Ended</th>
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">Duration</th>
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr class="border-b border-line">
                            <td class="px-3 py-2 text-ink">{{ $log->admin?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-ink">{{ $log->target?->name ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-ink-soft">{{ $log->started_at->toDayDateTimeString() }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-ink-soft">
                                @if ($log->ended_at)
                                    {{ $log->ended_at->toDayDateTimeString() }}
                                @else
                                    <span class="tag border-foul text-foul">Active</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-ink-soft">
                                {{ $log->duration_seconds !== null ? gmdate('H:i:s', $log->duration_seconds) : '—' }}
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-ink-soft">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-ink-soft">No impersonation sessions recorded yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-6">
        {{ $logs->links() }}
    </div>
</div>
