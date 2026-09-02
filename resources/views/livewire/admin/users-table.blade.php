<div>
    <h1>Users</h1>

    <a href="{{ route('admin.users.create') }}">Create trainer</a>

    <form role="search" wire:submit.prevent>
        <label for="search">Search name or email</label>
        <input id="search" type="search" wire:model.live.debounce.400ms="search">

        <label for="roleFilter">Role</label>
        <select id="roleFilter" wire:model.live="roleFilter">
            <option value="">All roles</option>
            @foreach ($roles as $role)
                <option value="{{ $role->value }}">{{ $role->label() }}</option>
            @endforeach
        </select>

        <label for="statusFilter">Status</label>
        <select id="statusFilter" wire:model.live="statusFilter">
            <option value="">All statuses</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ ucfirst($status->value) }}</option>
            @endforeach
        </select>
    </form>

    <table>
        <caption>All platform users</caption>
        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">Role</th>
                <th scope="col">Status</th>
                <th scope="col">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td>{{ $user->role->label() }}</td>
                    <td>{{ ucfirst($user->status->value) }}</td>
                    <td>
                        <a href="{{ route('admin.users.edit', $user) }}">Edit</a>
                        {{-- Slice D: impersonate, deactivate, delete. --}}
                        <button type="button" disabled>Impersonate</button>
                        <button type="button" disabled>Deactivate</button>
                        <button type="button" disabled>Delete</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">No users match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{ $users->links() }}
</div>
