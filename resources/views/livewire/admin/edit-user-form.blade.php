<div>
    <h1>Edit {{ $user->name }}</h1>

    <form wire:submit="save">
        <label for="firstName">First name</label>
        <input id="firstName" type="text" wire:model="firstName" required>
        @error('firstName') <p role="alert">{{ $message }}</p> @enderror

        <label for="lastName">Last name</label>
        <input id="lastName" type="text" wire:model="lastName" required>
        @error('lastName') <p role="alert">{{ $message }}</p> @enderror

        <label for="phone">Phone</label>
        <input id="phone" type="text" wire:model="phone">
        @error('phone') <p role="alert">{{ $message }}</p> @enderror

        <button type="submit">Save</button>
    </form>

    {{-- Read-only here: changing role or status is Slice D's deactivate/role tooling. --}}
    <dl>
        <dt>Email</dt><dd>{{ $user->email }}</dd>
        <dt>Role</dt><dd>{{ $user->role->label() }}</dd>
        <dt>Status</dt><dd>{{ ucfirst($user->status->value) }}</dd>
    </dl>
</div>
