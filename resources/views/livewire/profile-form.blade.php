{{-- Livewire wraps this in components/layouts/app.blade.php; one root element is required. --}}
<div>
    <x-ui.page-head :eyebrow="$user->role->label()" title="Your profile" />

    @if (session('profile-status'))
        <p role="status" class="mt-6 rounded-(--radius) border border-line border-l-[3px] border-l-court bg-paper px-4 py-3 text-sm text-ink">
            {{ session('profile-status') }}
        </p>
    @endif

    <div class="mt-6 flex flex-col gap-6 lg:flex-row lg:items-start">
        <div class="flex flex-1 flex-col gap-6">
            <form wire:submit="save" class="flex flex-col gap-6">
                <x-ui.card heading="Basic info">
                    <div class="flex flex-col gap-4">
                        <x-ui.field label="First name" for="firstName">
                            <input id="firstName" type="text" wire:model="firstName" required class="control">
                        </x-ui.field>
                        @error('firstName') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

                        <x-ui.field label="Last name" for="lastName">
                            <input id="lastName" type="text" wire:model="lastName" required class="control">
                        </x-ui.field>
                        @error('lastName') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

                        <x-ui.field label="Phone" for="phone">
                            <input id="phone" type="text" wire:model="phone" class="control">
                        </x-ui.field>
                        @error('phone') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror
                    </div>
                </x-ui.card>

                @if ($has_player_profile)
                    <fieldset class="rounded-(--radius) border border-line bg-paper">
                        <legend class="block w-full border-b border-line px-4 py-3 font-display text-sm font-bold uppercase tracking-tight text-ink">Player details</legend>

                        <div class="flex flex-col gap-4 p-4">
                            <x-ui.field label="School" for="school">
                                <input id="school" type="text" wire:model="school" class="control">
                            </x-ui.field>
                            @error('school') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

                            <x-ui.field label="Jersey number" for="jersey_number">
                                <input id="jersey_number" type="text" wire:model="jersey_number" class="control">
                            </x-ui.field>
                            @error('jersey_number') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror
                        </div>
                    </fieldset>
                @endif

                @if ($has_coach_profile)
                    <fieldset class="rounded-(--radius) border border-line bg-paper">
                        <legend class="block w-full border-b border-line px-4 py-3 font-display text-sm font-bold uppercase tracking-tight text-ink">Coach details</legend>

                        <div class="flex flex-col gap-4 p-4">
                            <x-ui.field label="Bio" for="bio">
                                <textarea id="bio" wire:model="bio" class="control"></textarea>
                            </x-ui.field>
                            @error('bio') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

                            <x-ui.field label="Credentials" for="credentials">
                                <textarea id="credentials" wire:model="credentials" class="control"></textarea>
                            </x-ui.field>
                            @error('credentials') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

                            <x-ui.field label="Certifications" for="certifications">
                                <textarea id="certifications" wire:model="certifications" class="control"></textarea>
                            </x-ui.field>
                            @error('certifications') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

                            <label for="is_public" class="flex items-center gap-2 text-sm text-ink">
                                <input id="is_public" type="checkbox" wire:model="is_public" class="accent-court">
                                Public profile
                            </label>
                        </div>
                    </fieldset>
                @endif

                @if ($has_trainer_profile)
                    <fieldset class="rounded-(--radius) border border-line bg-paper">
                        <legend class="block w-full border-b border-line px-4 py-3 font-display text-sm font-bold uppercase tracking-tight text-ink">Trainer details</legend>

                        <div class="flex flex-col gap-4 p-4">
                            <x-ui.field label="Business name" for="business_name">
                                <input id="business_name" type="text" wire:model="business_name" class="control">
                            </x-ui.field>
                            @error('business_name') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

                            <x-ui.field label="Address" for="address">
                                <input id="address" type="text" wire:model="address" class="control">
                            </x-ui.field>
                            @error('address') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

                            <x-ui.field label="Website" for="website">
                                <input id="website" type="url" wire:model="website" class="control">
                            </x-ui.field>
                            @error('website') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

                            <x-ui.field label="Description" for="description">
                                <textarea id="description" wire:model="description" class="control"></textarea>
                            </x-ui.field>
                            @error('description') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror
                        </div>
                    </fieldset>
                @endif

                @if (! empty($children))
                    <fieldset class="rounded-(--radius) border border-line bg-paper">
                        <legend class="block w-full border-b border-line px-4 py-3 font-display text-sm font-bold uppercase tracking-tight text-ink">Emergency contact per child</legend>

                        <div class="flex flex-col gap-4 p-4">
                            @foreach ($children as $index => $child)
                                <x-ui.field :label="$child['name']" :for="'child-'.$child['id']">
                                    <textarea id="child-{{ $child['id'] }}" wire:model="children.{{ $index }}.emergency_contact" class="control"></textarea>
                                </x-ui.field>
                                @error("children.{$index}.emergency_contact") <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <button type="submit" class="btn self-start" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </form>
        </div>

        <div class="lg:w-80">
            <x-ui.card heading="Identity">
                <div class="flex flex-col gap-4">
                    <fieldset class="flex flex-col gap-3">
                        <legend class="text-sm font-medium text-ink">Photo</legend>

                        @if ($user->photo_path)
                            <img src="{{ $user->photoUrl() }}" alt="Your profile photo" width="128" height="128" class="border border-line">
                            <button type="button" wire:click="removePhoto" class="btn-quiet self-start" wire:loading.attr="disabled" wire:target="removePhoto">
                                <span wire:loading.remove wire:target="removePhoto">Remove photo</span>
                                <span wire:loading wire:target="removePhoto">Removing…</span>
                            </button>
                        @endif

                        <div class="flex flex-col gap-1.5">
                            <label for="photo" class="sr-only">Upload a photo</label>
                            <input
                                id="photo"
                                type="file"
                                wire:model="photo"
                                accept="image/jpeg,image/png,image/webp"
                                class="block w-full text-sm text-ink-soft file:mr-3 file:inline-flex file:cursor-pointer file:items-center file:justify-center file:gap-2 file:rounded-(--radius) file:border file:border-line file:bg-transparent file:px-4 file:py-2 file:text-sm file:font-medium file:text-ink file:transition-colors file:duration-[120ms] hover:file:border-ink"
                            >
                        </div>
                        @error('photo') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror

                        <p wire:loading wire:target="photo" class="text-sm text-ink-soft">Checking the file…</p>
                    </fieldset>

                    <x-ui.role-tag :role="$user->role" />

                    {{-- Read-only per FR-016: email needs its own flow, role/skill level/created date are set elsewhere. --}}
                    <dl class="flex flex-col gap-2 border-t border-line pt-4 text-sm">
                        <div>
                            <dt class="font-mono text-xs uppercase tracking-wide text-ink-soft">Email</dt>
                            <dd class="font-mono text-ink">{{ $user->email }}</dd>
                        </div>

                        <div>
                            <dt class="font-mono text-xs uppercase tracking-wide text-ink-soft">Member since</dt>
                            <dd class="font-mono text-ink">{{ $user->created_at?->toFormattedDateString() }}</dd>
                        </div>

                        @if ($has_player_profile)
                            <div>
                                <dt class="font-mono text-xs uppercase tracking-wide text-ink-soft">Skill level</dt>
                                <dd class="font-mono text-ink">{{ $skill_level ?? '—' }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
