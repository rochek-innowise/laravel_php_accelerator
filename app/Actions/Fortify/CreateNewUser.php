<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Fortify's registration route is disabled (AD-004). This action stays registered because
 * Slice B's /join/{code} component calls it, so password rules remain first-party.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = new User([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'phone' => $input['phone'] ?? null,
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
        ]);

        // Privilege columns are not mass-assignable; this action decides them.
        $user->forceFill([
            'role' => Role::Player,
            'status' => UserStatus::Active,
        ])->save();

        return $user;
    }
}
