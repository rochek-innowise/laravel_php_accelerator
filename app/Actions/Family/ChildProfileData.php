<?php

declare(strict_types=1);

namespace App\Actions\Family;

/**
 * Input to `CreateChildProfile`. A readonly value object rather than a raw array so the action's
 * signature states exactly what it needs — `Livewire\Family\ChildForm` assembles this from its own
 * bound properties, never passing request input straight through.
 *
 * `gender` and `skill_level` are deliberately absent: neither is exposed anywhere else in the
 * application either (`ProfileForm` locks `skill_level` as something set by someone other than the
 * family, and never surfaces `gender` at all), so a child's creation form does not invent a new
 * capability the rest of the app does not have.
 */
final readonly class ChildProfileData
{
    /**
     * @param  list<int>  $trainerProfileIds
     */
    public function __construct(
        public string $name,
        public string $birthDate,
        public ?string $school,
        public ?string $jerseyNumber,
        public ?string $emergencyContact,
        public array $trainerProfileIds,
        public bool $confirmDuplicate = false,
        public bool $wantsLogin = false,
        public ?string $loginEmail = null,
        public ?string $loginPassword = null,
        public ?string $loginPasswordConfirmation = null,
    ) {}
}
