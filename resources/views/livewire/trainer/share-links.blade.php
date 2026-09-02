<div>
    <x-ui.page-head eyebrow="Trainer" title="Invitation link" sub="Share this with players and parents" />

    <x-ui.card class="mt-6">
        @if ($joinUrl !== null)
            <x-ui.field label="Your player invitation link" for="joinUrl">
                <input id="joinUrl" type="text" value="{{ $joinUrl }}" class="control font-mono text-sm" readonly>
            </x-ui.field>

            <p class="mt-3 text-sm text-ink-soft">
                Unlimited uses and no expiry. Anyone with this link can join your organisation, so treat it
                like a key: if it has been shared too widely, replace it below.
            </p>

            <button type="button" wire:click="regenerate" class="btn-ghost mt-4">Replace this link</button>
        @else
            <p class="font-mono text-xs font-bold uppercase tracking-widest text-ink-soft">No invitation link yet</p>
            <p class="mt-3 text-sm text-ink-soft">
                Create one to start inviting players and parents. It never expires and can be used any
                number of times, so share it the way you would share a key.
            </p>

            <button type="button" wire:click="regenerate" class="btn mt-4">Create invitation link</button>
        @endif
    </x-ui.card>
</div>
