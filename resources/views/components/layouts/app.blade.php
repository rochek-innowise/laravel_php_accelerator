<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-field font-sans text-ink">
    <a
        href="#main"
        class="sr-only focus-visible:not-sr-only focus-visible:fixed focus-visible:left-4 focus-visible:top-4 focus-visible:z-50 focus-visible:rounded-(--radius) focus-visible:border focus-visible:border-line focus-visible:bg-paper focus-visible:px-4 focus-visible:py-2 focus-visible:text-ink"
    >
        Skip to main content
    </a>

    <div class="lg:grid lg:min-h-screen lg:grid-cols-[15rem_1fr]">
        <header class="ink-surface flex flex-wrap items-center justify-between gap-x-4 gap-y-3 bg-ink px-4 py-3 lg:sticky lg:top-0 lg:h-screen lg:flex-col lg:flex-nowrap lg:items-stretch lg:justify-between lg:gap-0 lg:px-6 lg:py-6">
            <a href="{{ route('dashboard') }}" class="shrink-0 lg:mb-10">
                <p class="font-mono text-[0.65rem] font-bold uppercase tracking-widest text-field/70">Sports training</p>
                <p class="font-display text-xl font-bold uppercase tracking-tight text-field lg:text-2xl">Platform</p>
            </a>

            <div class="flex shrink-0 items-center gap-3 lg:order-last lg:flex-col lg:items-stretch lg:gap-3 lg:border-t lg:border-field/20 lg:pt-4">
                {{-- The active organisation is always visible: session-held context means two tabs
                     on two organisations fight each other, and seeing which one is active is the
                     accepted mitigation for that trade-off. --}}
                <livewire:context.trainer-switcher />
                <livewire:context.profile-switcher />

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
                @endif

                @if (auth()->user()->isSuperAdmin())
                    <x-ui.nav-link :href="route('admin.users.index')" :active="request()->routeIs('admin.users.*')">
                        Users
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
