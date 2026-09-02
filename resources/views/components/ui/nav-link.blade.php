@props(['href', 'active' => false])

<a
    href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'flex items-center gap-2 whitespace-nowrap border-l-[3px] px-3 py-2 text-sm transition-colors duration-[120ms]',
        'border-court text-field' => $active,
        'border-transparent text-field/60 hover:text-field' => ! $active,
    ]) }}
>{{ $slot }}</a>
