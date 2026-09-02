<div>
    <x-ui.page-head eyebrow="Family" title="Approvals" sub="Purchase requests awaiting a decision" />

    <x-ui.card class="mt-6">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Purchase approval requests</caption>
            <thead>
                <tr class="border-b border-line">
                    <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Child</th>
                    <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Amount</th>
                    <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Status</th>
                    <th scope="col" class="py-2 font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Requested</th>
                    @if ($canRespond)
                        <th scope="col" class="py-2"><span class="sr-only">Actions</span></th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse ($approvals as $approval)
                    <tr class="border-b border-line/50">
                        <td class="py-2">{{ $approval->playerProfile?->name }}</td>
                        <td class="py-2">
                            {{ $approval->payment_type->value === 'usd' ? '$'.number_format($approval->amount_cents / 100, 2) : $approval->amount_cents.' tokens' }}
                        </td>
                        <td class="py-2">{{ $approval->status->label() }}</td>
                        <td class="py-2">{{ $approval->requested_at?->toFormattedDateString() }}</td>
                        @if ($canRespond)
                            <td class="py-2 text-right">
                                @can('respond', $approval)
                                    <button type="button" wire:click="approve({{ $approval->id }})" class="btn">Approve</button>
                                    <button type="button" wire:click="deny({{ $approval->id }})" class="btn-ghost">Deny</button>
                                @endcan
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canRespond ? 5 : 4 }}" class="py-4 text-ink-soft">No purchase requests.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.card>
</div>
