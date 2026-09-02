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

    <div class="lg:grid lg:min-h-screen lg:grid-cols-2">
        <div class="ink-surface bg-ink px-6 py-6 lg:flex lg:flex-col lg:justify-center lg:px-12 lg:py-12">
            <p class="font-mono text-xs font-bold uppercase tracking-widest text-field/70">Sports training</p>
            <p class="mt-1 font-display text-2xl font-bold uppercase tracking-tight text-field lg:mt-3 lg:text-7xl">Platform</p>

            <ul class="hidden lg:mt-10 lg:block lg:space-y-6">
                @foreach ([
                    [\App\Enums\Role::Trainer, 'Runs the org'],
                    [\App\Enums\Role::Coach, 'Runs sessions'],
                    [\App\Enums\Role::Player, 'Joins by invitation'],
                    [\App\Enums\Role::SuperAdmin, 'Oversees the platform'],
                ] as [$role, $description])
                    <li class="flex items-baseline gap-4">
                        <x-ui.role-tag :role="$role" inverted class="shrink-0" />
                        <span class="text-sm text-field/70">{{ $description }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="flex justify-center px-4 py-10 lg:items-center lg:px-12 lg:py-12">
            <div class="w-full max-w-[32rem]">
                @if (session('status'))
                    <p role="status" class="mb-6 rounded-(--radius) border border-line bg-paper px-4 py-3 text-sm text-ink">
                        {{ session('status') }}
                    </p>
                @endif

                <main id="main">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </div>
</body>
</html>
