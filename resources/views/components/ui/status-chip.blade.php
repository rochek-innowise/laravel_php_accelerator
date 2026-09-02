@props(['status'])

@php
    $colourClasses = match ($status) {
        \App\Enums\UserStatus::Active => 'border-ink text-ink',
        \App\Enums\UserStatus::Inactive => 'border-dashed border-ink-soft text-ink-soft',
        \App\Enums\UserStatus::Deleted => 'border-foul text-foul',
    };
@endphp

<span {{ $attributes->class(['tag', $colourClasses]) }}>{{ ucfirst($status->value) }}</span>
