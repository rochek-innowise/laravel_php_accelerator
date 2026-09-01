<div>
    <h1>Create trainer</h1>

    <form wire:submit="save">
        <label for="businessName">Business name</label>
        <input id="businessName" type="text" wire:model="businessName" required autofocus>
        @error('businessName') <p role="alert">{{ $message }}</p> @enderror

        <label for="firstName">First name</label>
        <input id="firstName" type="text" wire:model="firstName" required>
        @error('firstName') <p role="alert">{{ $message }}</p> @enderror

        <label for="lastName">Last name</label>
        <input id="lastName" type="text" wire:model="lastName" required>
        @error('lastName') <p role="alert">{{ $message }}</p> @enderror

        <label for="email">Email</label>
        <input id="email" type="email" wire:model="email" required>
        @error('email') <p role="alert">{{ $message }}</p> @enderror

        <label for="phone">Phone</label>
        <input id="phone" type="text" wire:model="phone" required>
        @error('phone') <p role="alert">{{ $message }}</p> @enderror

        <button type="submit">Create and send invitation</button>
    </form>
</div>
