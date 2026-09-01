{{-- Livewire wraps this in components/layouts/app.blade.php; one root element is required. --}}
<div>
    <h1>Your profile</h1>

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

    {{-- Read-only per FR-016: email needs its own flow, role and skill level are set elsewhere. --}}
    <dl>
        <dt>Email</dt><dd>{{ $user->email }}</dd>
        <dt>Role</dt><dd>{{ $user->role->label() }}</dd>
        <dt>Member since</dt><dd>{{ $user->created_at?->toFormattedDateString() }}</dd>
    </dl>

    {{-- TODO(file-storage): profile photo upload, resize, non-public disk, signed serving route. --}}
</div>
