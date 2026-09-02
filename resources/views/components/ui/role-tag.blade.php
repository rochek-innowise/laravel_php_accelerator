@props(['role', 'inverted' => false])

@php
    $colourClasses = match ($role) {
        \App\Enums\Role::SuperAdmin => $inverted ? 'border-field text-field' : 'border-role-admin text-role-admin',
        \App\Enums\Role::Trainer => $inverted ? 'border-role-trainer text-field' : 'border-role-trainer text-role-trainer',
        \App\Enums\Role::Coach => $inverted ? 'border-role-coach text-field' : 'border-role-coach text-role-coach',
        \App\Enums\Role::Player => $inverted ? 'border-role-player text-field' : 'border-role-player text-role-player',
    };
@endphp

<span {{ $attributes->class(['tag', $colourClasses]) }}>{{ $role->label() }}</span>
