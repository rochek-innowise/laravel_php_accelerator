@props(['label', 'for'])

<div class="flex flex-col gap-1.5">
    <label for="{{ $for }}" class="text-sm font-medium text-ink">{{ $label }}</label>

    {{ $slot }}

    @isset($error)
        <p role="alert" class="text-sm text-foul">{{ $error }}</p>
    @endisset
</div>
