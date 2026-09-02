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

        @if ($has_player_profile)
            <fieldset>
                <legend>Player details</legend>

                <label for="school">School</label>
                <input id="school" type="text" wire:model="school">
                @error('school') <p role="alert">{{ $message }}</p> @enderror

                <label for="jersey_number">Jersey number</label>
                <input id="jersey_number" type="text" wire:model="jersey_number">
                @error('jersey_number') <p role="alert">{{ $message }}</p> @enderror
            </fieldset>
        @endif

        @if ($has_coach_profile)
            <fieldset>
                <legend>Coach details</legend>

                <label for="bio">Bio</label>
                <textarea id="bio" wire:model="bio"></textarea>
                @error('bio') <p role="alert">{{ $message }}</p> @enderror

                <label for="credentials">Credentials</label>
                <textarea id="credentials" wire:model="credentials"></textarea>
                @error('credentials') <p role="alert">{{ $message }}</p> @enderror

                <label for="certifications">Certifications</label>
                <textarea id="certifications" wire:model="certifications"></textarea>
                @error('certifications') <p role="alert">{{ $message }}</p> @enderror

                <label for="is_public">
                    <input id="is_public" type="checkbox" wire:model="is_public">
                    Public profile
                </label>
            </fieldset>
        @endif

        @if ($has_trainer_profile)
            <fieldset>
                <legend>Trainer details</legend>

                <label for="business_name">Business name</label>
                <input id="business_name" type="text" wire:model="business_name">
                @error('business_name') <p role="alert">{{ $message }}</p> @enderror

                <label for="address">Address</label>
                <input id="address" type="text" wire:model="address">
                @error('address') <p role="alert">{{ $message }}</p> @enderror

                <label for="website">Website</label>
                <input id="website" type="url" wire:model="website">
                @error('website') <p role="alert">{{ $message }}</p> @enderror

                <label for="description">Description</label>
                <textarea id="description" wire:model="description"></textarea>
                @error('description') <p role="alert">{{ $message }}</p> @enderror
            </fieldset>
        @endif

        <button type="submit">Save</button>
    </form>

    @if (! empty($children))
        <fieldset>
            <legend>Emergency contact per child</legend>

            @foreach ($children as $index => $child)
                <label for="child-{{ $child['id'] }}">{{ $child['name'] }}</label>
                <textarea id="child-{{ $child['id'] }}" wire:model="children.{{ $index }}.emergency_contact"></textarea>
                @error("children.{$index}.emergency_contact") <p role="alert">{{ $message }}</p> @enderror
            @endforeach
        </fieldset>
    @endif

    {{-- Read-only per FR-016: email needs its own flow, role/skill level/created date are set elsewhere. --}}
    <dl>
        <dt>Email</dt><dd>{{ $user->email }}</dd>
        <dt>Role</dt><dd>{{ $user->role->label() }}</dd>
        <dt>Member since</dt><dd>{{ $user->created_at?->toFormattedDateString() }}</dd>
        @if ($has_player_profile)
            <dt>Skill level</dt><dd>{{ $skill_level ?? '—' }}</dd>
        @endif
    </dl>

    {{-- TODO(file-storage): profile photo upload, resize, non-public disk, signed serving route. --}}
</div>
