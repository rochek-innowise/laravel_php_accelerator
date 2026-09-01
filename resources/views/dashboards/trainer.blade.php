<x-layouts.app title="Dashboard">
    <h1>{{ auth()->user()->role->label() }} dashboard</h1>

    <p>Signed in as {{ auth()->user()->name }}.</p>

    {{-- Slice B onward fills this in. --}}
</x-layouts.app>
