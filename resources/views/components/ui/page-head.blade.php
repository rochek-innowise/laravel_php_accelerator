@props(['title', 'eyebrow' => null, 'sub' => null])

<header {{ $attributes->class(['border-b border-line bg-paper px-4 py-6 lg:px-8']) }}>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            @if ($eyebrow)
                <p class="eyebrow">{{ $eyebrow }}</p>
            @endif

            <h1 class="mt-1 font-display text-2xl font-bold uppercase tracking-tight text-ink">{{ $title }}</h1>

            @if ($sub)
                <p class="mt-1 text-sm text-ink-soft">{{ $sub }}</p>
            @endif
        </div>

        @if ($slot->isNotEmpty())
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                {{ $slot }}
            </div>
        @endif
    </div>
</header>
