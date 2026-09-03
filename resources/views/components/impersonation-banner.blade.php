{{--
    FR-012's sticky, colour-coded banner. Not yet included anywhere — Slice D's final integration
    step (11) adds `<x-impersonation-banner />` to the shared layout, once both Track B and Track D
    have landed and the layout can be touched once instead of by two agents concurrently.

    Reads the session directly rather than taking a prop: every page that will ever render this
    is inside the `web` middleware group, so `session('impersonator_id')` is always the same
    single source of truth `EnforceImpersonationTimeout` and `AuditLogger` already read.
--}}
@if (session()->has('impersonator_id'))
    <div
        role="alert"
        class="sticky top-0 z-50 flex flex-wrap items-center justify-between gap-3 border-b-2 border-foul bg-foul px-4 py-3 text-sm font-medium text-paper"
    >
        <p>
            <span class="font-mono text-xs font-bold uppercase tracking-widest">Viewing as</span>
            {{ auth()->user()?->name }}
        </p>

        <form method="POST" action="{{ route('impersonate.stop') }}">
            @csrf
            <button type="submit" class="btn-ghost border-paper/60 text-paper hover:bg-paper/10">
                Exit Impersonation
            </button>
        </form>
    </div>
@endif
