@props(['heading' => null])

<section {{ $attributes->class(['rounded-(--radius) border border-line bg-paper']) }}>
    @if ($heading)
        <h2 class="border-b border-line px-4 py-3 font-display text-sm font-bold uppercase tracking-tight text-ink">
            {{ $heading }}
        </h2>
    @endif

    <div class="p-4">
        {{ $slot }}
    </div>
</section>
