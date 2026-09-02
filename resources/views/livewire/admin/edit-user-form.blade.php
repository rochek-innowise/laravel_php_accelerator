<div>
    <x-ui.page-head eyebrow="Admin" :title="'Edit '.$user->name" />

    <div class="mt-6 flex flex-col gap-6 lg:flex-row lg:items-start">
        <div class="flex-1">
            <form wire:submit="save" class="flex flex-col gap-6">
                <x-ui.card>
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

                <button type="submit" class="btn self-start">Save</button>
            </form>
        </div>

        <div class="lg:w-80">
            <x-ui.card heading="Identity">
                <div class="flex flex-col gap-4">
                    {{-- Read-only here: changing role or status is Slice D's deactivate/role tooling. --}}
                    <dl class="flex flex-col gap-2 text-sm">
                        <div>
                            <dt class="font-mono text-xs uppercase tracking-wide text-ink-soft">Role</dt>
                            <dd class="mt-1"><x-ui.role-tag :role="$user->role" /></dd>
                        </div>

                        <div>
                            <dt class="font-mono text-xs uppercase tracking-wide text-ink-soft">Status</dt>
                            <dd class="mt-1"><x-ui.status-chip :status="$user->status" /></dd>
                        </div>

                        <div>
                            <dt class="font-mono text-xs uppercase tracking-wide text-ink-soft">Email</dt>
                            <dd class="font-mono text-ink">{{ $user->email }}</dd>
                        </div>
                    </dl>
                </div>
            </x-ui.card>
        </div>
    </div>
</div>
