<?php

declare(strict_types=1);

namespace App\Actions\Family;

/**
 * Input to `CreateChildProfile`. A readonly value object rather than a raw array so the action's
 * signature states exactly what it needs — `Livewire\Family\ChildForm` assembles this from its own
 * bound properties, never passing request input straight through.
 *
 * `gender` is required by FR-008's stated acceptance criteria. `skill_level` stays absent: it is
 * not exposed anywhere else in the application either — `ProfileForm` locks it as something set by
 * someone other than the family — and no FR asks for it at child creation, unlike gender.
 */
final readonly class ChildProfileData
{
    /**
     * @param  list<int>  $trainerProfileIds
     */
    public function __construct(
        public string $name,
        public string $birthDate,
        public string $gender,
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
