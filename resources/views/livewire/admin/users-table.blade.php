<div>
    <x-ui.page-head eyebrow="Admin" title="Users" sub="All platform users">
        <a href="{{ route('admin.users.create') }}" class="btn">Create trainer</a>
    </x-ui.page-head>

    <x-ui.card class="mt-6">
        <form role="search" wire:submit.prevent class="grid gap-4 sm:grid-cols-3">
            <div class="flex flex-col gap-1.5">
                <label for="search" class="font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Search name or email</label>
                <input id="search" type="search" wire:model.live.debounce.400ms="search" class="control">
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="roleFilter" class="font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Role</label>
                <select id="roleFilter" wire:model.live="roleFilter" class="control">
                    <option value="">All roles</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="statusFilter" class="font-mono text-xs font-bold uppercase tracking-wide text-ink-soft">Status</label>
                <select id="statusFilter" wire:model.live="statusFilter" class="control">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}">{{ ucfirst($status->value) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card class="mt-6">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">All platform users</caption>
                <thead>
                    <tr class="border-b border-line">
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">Name</th>
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">Email</th>
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">Role</th>
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">Status</th>
                        <th scope="col" class="px-3 py-2 font-mono text-xs uppercase tracking-wide text-ink-soft">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr class="border-b border-line">
                            <td class="px-3 py-2 text-ink">{{ $user->name }}</td>
                            <td class="px-3 py-2 font-mono text-xs text-ink-soft">{{ $user->email }}</td>
                            <td class="px-3 py-2"><x-ui.role-tag :role="$user->role" /></td>
                            <td class="px-3 py-2"><x-ui.status-chip :status="$user->status" /></td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <div class="flex flex-nowrap items-center gap-2">
                                    <a href="{{ route('admin.users.edit', $user) }}" class="link">Edit</a>
                                    {{-- Slice D: impersonate, deactivate, delete. --}}
                                    <button type="button" disabled class="btn-quiet btn-quiet-compact">Impersonate</button>
                                    <button type="button" disabled class="btn-quiet btn-quiet-compact">Deactivate</button>
                                    <button type="button" disabled class="btn-quiet btn-quiet-compact">Delete</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-center text-ink-soft">No users match these filters. Clear the search or pick another role.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="mt-6">
        {{ $users->links() }}
    </div>
</div>
