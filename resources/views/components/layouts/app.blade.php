<?php
    // FR-019 / Slice D final integration (step 11): the CSS custom property every themed surface
    // reads. A Super Admin resolves no tenant (`EnsureTrainerContext` never gives them one), so
    // this is a null-safe read with an explicit fallback, not an unguarded `->primary_color` — that
    // would be a null-dereference on every single admin page.
    $activeTenant = app(\App\Support\Tenancy\TrainerContext::class)->get();
    // Gap 13 (defence-in-depth): every writer today validates `/^#[0-9A-Fa-f]{6}$/` before this
    // column is ever set, so there is no live path to a stored non-hex value — but `{{ }}` only
    // HTML-escapes, and doesn't stop `;`, `{`, `}`, `(`, `)` or `:` from injecting arbitrary CSS
    // into this `<style>` block for every member of the organisation were that ever to change.
    // Re-checking the shape at render, not just at write, means a future writer forgetting that
    // validation fails safe (the platform default) rather than silently reopening this.
    $brandPrimaryColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $activeTenant?->primary_color) === 1
        ? $activeTenant->primary_color
        : config('branding.default_primary_color');
    // Gap 11: `TrainerProfile::logoUrl()` documents itself as meant to render for every member of
    // the organisation on every page load, and until now nothing called it — the same seam bug
    // `primary_color` had before this step wired it in. Same null-safety reasoning: a Super Admin
    // has no active tenant at all, and a tenant with no logo yet is `logoUrl() === null`; both
    // render nothing rather than a broken `<img>`.
    $brandLogoUrl = $activeTenant?->logoUrl();
?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Escaped Blade output, never {!! !!}: primary_color is user-supplied (validated as a hex
         string on save, but a bad stored value must still not break out of this style context). --}}
    <style>:root { --brand-primary: {{ $brandPrimaryColor }}; }</style>
</head>
<body class="bg-field font-sans text-ink">
    {{-- FR-012: the sticky banner is present during impersonation and only then — the component
         itself gates on `session()->has('impersonator_id')`. --}}
    <x-impersonation-banner />

    <a
        href="#main"
        class="sr-only focus-visible:not-sr-only focus-visible:fixed focus-visible:left-4 focus-visible:top-4 focus-visible:z-50 focus-visible:rounded-(--radius) focus-visible:border focus-visible:border-line focus-visible:bg-paper focus-visible:px-4 focus-visible:py-2 focus-visible:text-ink"
    >
        Skip to main content
    </a>

    <div class="lg:grid lg:min-h-screen lg:grid-cols-[15rem_1fr]">
        <header class="ink-surface flex flex-wrap items-center justify-between gap-x-4 gap-y-3 bg-ink px-4 py-3 lg:sticky lg:top-0 lg:h-screen lg:flex-col lg:flex-nowrap lg:items-stretch lg:justify-between lg:gap-0 lg:px-6 lg:py-6">
            <a href="{{ route('dashboard') }}" class="shrink-0 lg:mb-10">
                @if ($brandLogoUrl)
                    <img
                        src="{{ $brandLogoUrl }}"
                        alt="{{ $activeTenant->business_name }} logo"
                        class="mb-2 h-8 w-auto max-w-[10rem] object-contain"
                    >
                @endif
                <p class="font-mono text-[0.65rem] font-bold uppercase tracking-widest text-field/70">Sports training</p>
                <p class="font-display text-xl font-bold uppercase tracking-tight text-field lg:text-2xl">Platform</p>
            </a>

            <div class="flex shrink-0 items-center gap-3 lg:order-last lg:flex-col lg:items-stretch lg:gap-3 lg:border-t lg:border-field/20 lg:pt-4">
                {{-- The active organisation is always visible: session-held context means two tabs
                     on two organisations fight each other, and seeing which one is active is the
                     accepted mitigation for that trade-off. --}}
                <livewire:context.trainer-switcher />
                <livewire:context.profile-switcher />

                {{-- Slice C's first database-channel notifications (AD-011): approvals and a
                     blocked ShareLink both land here for whichever family member is looking. --}}
                <livewire:family.notification-bell />

                <p class="hidden text-sm font-medium text-field lg:block">{{ auth()->user()->name }}</p>

                <x-ui.role-tag :role="auth()->user()->role" inverted />

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn-ghost">Log out</button>
                </form>
            </div>

            {{-- Below lg this is forced onto its own full-width row by basis-full, so it never competes with the wordmark/logout row for space. --}}
            <nav aria-label="Main" class="order-last flex min-w-0 basis-full items-center gap-1 overflow-x-auto lg:order-none lg:basis-auto lg:flex-1 lg:flex-col lg:items-stretch lg:overflow-visible">
                <x-ui.nav-link
                    :href="route('dashboard')"
                    :active="request()->routeIs('dashboard', 'trainer.dashboard', 'coach.dashboard', 'player.dashboard')"
                >
                    Dashboard
                </x-ui.nav-link>

                <x-ui.nav-link :href="route('profile')" :active="request()->routeIs('profile')">
                    Profile
                </x-ui.nav-link>

                @if (auth()->user()->role === \App\Enums\Role::Trainer)
                    <x-ui.nav-link :href="route('trainer.coaches')" :active="request()->routeIs('trainer.coaches')">
                        Coaches
                    </x-ui.nav-link>

                    <x-ui.nav-link :href="route('trainer.share-links')" :active="request()->routeIs('trainer.share-links')">
                        Invitation link
                    </x-ui.nav-link>

                    <x-ui.nav-link :href="route('trainer.branding')" :active="request()->routeIs('trainer.branding')">
                        Branding
                    </x-ui.nav-link>
                @endif

                @if (auth()->user()->role === \App\Enums\Role::Player)
                    <x-ui.nav-link :href="route('family.index')" :active="request()->routeIs('family.*')">
                        Family
                    </x-ui.nav-link>

                    <x-ui.nav-link :href="route('approvals.index')" :active="request()->routeIs('approvals.index')">
                        Approvals
                    </x-ui.nav-link>
                @endif

                @if (auth()->user()->isSuperAdmin())
                    <x-ui.nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                        Users
                    </x-ui.nav-link>

                    <x-ui.nav-link :href="route('admin.impersonation-history')" :active="request()->routeIs('admin.impersonation-history')">
                        Impersonation history
                    </x-ui.nav-link>
                @endif
            </nav>
        </header>

        <main id="main" class="mx-auto w-full max-w-[72rem] px-4 py-8 lg:px-8">
            {{-- verify-email renders the 'verification-link-sent' sentinel itself; showing it here too would duplicate the message. --}}
            @if (session('status') && session('status') !== 'verification-link-sent')
                <p role="status" class="mb-6 rounded-(--radius) border border-line bg-paper px-4 py-3 text-sm text-ink">
                    {{ session('status') }}
                </p>
            @endif

            {{ $slot }}
        </main>
    </div>
</body>
</html>
