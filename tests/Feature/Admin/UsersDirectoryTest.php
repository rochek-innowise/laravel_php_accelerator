<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Livewire\Admin\UsersTable;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/** FR-005: the Super Admin directory, with tool-scoped search and server-side pagination. */
final class UsersDirectoryTest extends TestCase
{
    public function test_a_super_admin_can_open_the_directory(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }

    public function test_a_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->trainer()->create())->get('/admin/users')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
    }

    public function test_the_search_matches_name_and_email(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $match = User::factory()->create(['first_name' => 'Zinaida', 'email' => 'zin@example.test']);
        $other = User::factory()->create(['first_name' => 'Bogdan', 'email' => 'bog@example.test']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->set('search', 'Zinaida')
            ->assertSee('zin@example.test')
            ->assertDontSee('bog@example.test');

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->set('search', 'bog@example.test')
            ->assertSee($other->email);
    }

    public function test_the_role_and_status_filters_narrow_the_list(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $trainer = User::factory()->trainer()->create(['email' => 'trainer@example.test']);
        $inactive = User::factory()->status(UserStatus::Inactive)->create(['email' => 'gone@example.test']);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->set('roleFilter', Role::Trainer->value)
            ->assertSee($trainer->email)
            ->assertDontSee($inactive->email);

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->set('statusFilter', UserStatus::Inactive->value)
            ->assertSee($inactive->email)
            ->assertDontSee($trainer->email);
    }

    /** The Name column shows a composed name, so that is the string the search has to match. */
    public function test_the_search_matches_a_full_name(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->create([
            'first_name' => 'Zinaida',
            'last_name' => 'Petrenko',
            'email' => 'zin@example.test',
        ]);

        foreach (['Zinaida Petrenko', 'Zinaida', 'Petrenko', 'zin@example.test'] as $term) {
            $users = Livewire::actingAs($admin)
                ->test(UsersTable::class)
                ->set('search', $term)
                ->viewData('users');

            $this->assertTrue(
                $users->contains(fn (User $user): bool => $user->email === 'zin@example.test'),
                "Search for [{$term}] missed the row.",
            );
        }
    }

    /** An unescaped wildcard turned the search box into "show me everything". */
    public function test_a_wildcard_in_the_search_term_is_escaped(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->count(3)->create();

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->set('search', '%')
            ->assertViewHas('users', fn ($users): bool => $users->isEmpty());
    }

    /** NFR-002: the listing must stay paginated rather than loading every row. */
    public function test_the_listing_is_paginated(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->count(30)->create();

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->assertViewHas('users', fn ($users): bool => $users->count() === 25);
    }
}
