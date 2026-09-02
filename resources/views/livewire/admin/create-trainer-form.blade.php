<div>
    <x-ui.page-head eyebrow="Admin" title="Create trainer" />

    <x-ui.card class="mt-6 max-w-[32rem]">
        <form wire:submit="save" class="flex flex-col gap-4">
            <x-ui.field label="Business name" for="businessName">
                <input id="businessName" type="text" wire:model="businessName" required autofocus class="control">
            </x-ui.field>
            @error('businessName') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

            <x-ui.field label="First name" for="firstName">
                <input id="firstName" type="text" wire:model="firstName" required class="control">
            </x-ui.field>
            @error('firstName') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

            <x-ui.field label="Last name" for="lastName">
                <input id="lastName" type="text" wire:model="lastName" required class="control">
            </x-ui.field>
            @error('lastName') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

            <x-ui.field label="Email" for="email">
                <input id="email" type="email" wire:model="email" required class="control">
            </x-ui.field>
            @error('email') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

            <x-ui.field label="Phone" for="phone">
                <input id="phone" type="text" wire:model="phone" required class="control">
            </x-ui.field>
            @error('phone') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

            <button type="submit" class="btn self-start">Create and send invitation</button>
        </form>
    </x-ui.card>
</div>
