<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use Illuminate\Database\Eloquent\Model;

/** Writes a field set already authorized and validated by the caller; shared by every role-specific profile shape. */
final class UpdateRoleSpecificProfile
{
    /** @param  array<string, mixed>  $data */
    public function handle(Model $profile, array $data): void
    {
        $profile->update($data);
    }
}
