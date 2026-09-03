<div>
    <x-ui.page-head eyebrow="Trainer" title="Branding" sub="Your logo and colour apply immediately across your organisation's shell" />

    @if (session('branding-status'))
        <p role="status" class="mt-4 text-sm font-medium text-ink">{{ session('branding-status') }}</p>
    @endif

    <x-ui.card class="mt-6">
        <form wire:submit="save" class="flex flex-col gap-6">
            <fieldset class="flex flex-col gap-3">
                <legend class="text-sm font-medium text-ink">Logo</legend>

                <div class="flex items-center gap-4">
                    @if ($logo)
                        <img src="{{ $logo->temporaryUrl() }}" alt="Logo preview" width="96" height="96" class="border border-line object-contain">
                    @elseif ($trainer->logoUrl())
                        <img src="{{ $trainer->logoUrl() }}" alt="{{ $trainer->business_name }} logo" width="96" height="96" class="border border-line object-contain">
                    @else
                        <div class="flex h-24 w-24 items-center justify-center border border-dashed border-line text-xs text-ink-soft">
                            No logo
                        </div>
                    @endif

                    <div class="flex flex-col gap-1.5">
                        <label for="logo" class="sr-only">Upload a logo</label>
                        <input
                            id="logo"
                            type="file"
                            wire:model="logo"
                            accept="image/jpeg,image/png"
                            class="block w-full text-sm text-ink-soft file:mr-3 file:inline-flex file:cursor-pointer file:items-center file:justify-center file:gap-2 file:rounded-(--radius) file:border file:border-line file:bg-transparent file:px-4 file:py-2 file:text-sm file:font-medium file:text-ink file:transition-colors file:duration-[120ms] hover:file:border-ink"
                        >
                        <p class="text-xs text-ink-soft">PNG or JPEG, up to {{ (int) (config('media.trainer_logos.max_kilobytes') / 1024) }} MB. SVG is not accepted.</p>
                        <p wire:loading wire:target="logo" class="text-sm text-ink-soft">Checking the file…</p>
                    </div>
                </div>

                @error('logo') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror
            </fieldset>

            <x-ui.field label="Primary colour" for="primaryColor">
                <div class="flex items-center gap-3">
                    <input
                        id="primaryColor-swatch"
                        type="color"
                        wire:model.live="primaryColor"
                        aria-label="Pick a primary colour"
                        class="h-10 w-10 cursor-pointer border border-line p-0"
                    >
                    <input
                        id="primaryColor"
                        type="text"
                        wire:model.live="primaryColor"
                        maxlength="7"
                        placeholder="#0EA5E9"
                        class="control w-32 font-mono"
                    >
                    <span
                        aria-hidden="true"
                        style="background-color: {{ $primaryColor }};"
                        class="inline-block h-10 w-10 rounded-(--radius) border border-line"
                    ></span>
                </div>
                @error('primaryColor') <p role="alert" class="text-sm text-foul">{{ $message }}</p> @enderror
            </x-ui.field>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save branding</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>

                <button type="button" wire:click="resetBranding" wire:confirm="Reset branding to the platform default? This clears your logo." class="btn-ghost">
                    Reset to default
                </button>
            </div>
        </form>
    </x-ui.card>
</div>
