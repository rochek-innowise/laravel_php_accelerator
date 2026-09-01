<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\ProfileForm;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/** FR-016: a user edits their own profile; email and role stay read-only. */
final class ProfileTest extends TestCase
{
    public function test_a_user_updates_their_own_profile(): void
    {
        $user = User::factory()->create(['first_name' => 'Old', 'last_name' => 'Name']);

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set(['firstName' => 'New', 'lastName' => 'Person', 'phone' => '+1 555 0199'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'New',
            'last_name' => 'Person',
            'phone' => '+1 555 0199',
        ]);
    }

    public function test_the_display_name_is_derived_from_first_and_last_name(): void
    {
        $user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->assertSame('Ada Lovelace', $user->name);
    }

    public function test_names_are_required(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set(['firstName' => '', 'lastName' => ''])
            ->call('save')
            ->assertHasErrors();

        $this->assertNotEmpty($user->fresh()->first_name);
    }

    public function test_the_form_loads_the_current_values(): void
    {
        $user = User::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->assertSet('firstName', 'Grace')
            ->assertSet('lastName', 'Hopper');
    }

    public function test_a_guest_cannot_reach_the_profile(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }
}
