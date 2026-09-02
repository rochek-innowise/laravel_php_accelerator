<x-layouts.app title="Dashboard">
    <x-ui.page-head
        :eyebrow="auth()->user()->role->label()"
        :title="auth()->user()->role->label().' dashboard'"
        :sub="'Signed in as '.auth()->user()->name.'.'"
    />

    <x-ui.card class="mt-6">
        <p class="text-sm text-ink-soft">Your schedule and roster will appear here.</p>
    </x-ui.card>

    {{-- Slice B onward fills this in. --}}
</x-layouts.app>
