<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Livewire\ProfileForm;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/** FR-016: the role-specific field set beyond first/last name and phone. */
final class RoleSpecificProfileFieldsTest extends TestCase
{
    public function test_a_player_edits_school_and_jersey_number(): void
    {
        $user = User::factory()->role(Role::Player)->create();
        PlayerProfile::factory()->selfProfile($user)->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set(['school' => 'Lincoln High', 'jersey_number' => '7'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('player_profiles', [
            'user_id' => $user->id,
            'school' => 'Lincoln High',
            'jersey_number' => '7',
        ]);
    }

    /**
     * FR-016 with guardianship: the contact lives on each child's profile, where the trainer
     * responsible for that child will look for it. A guardian who does not train has no self
     * profile at all, so putting it on their own record would have hidden the field from them.
     */
    public function test_a_guardian_edits_the_emergency_contact_of_each_child(): void
    {
        $parent = User::factory()->create();
        $first = PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Alex']);
        $second = PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Maya']);

        Livewire::actingAs($parent)
            ->test(ProfileForm::class)
            ->set('children.0.emergency_contact', 'Gran, 555-0101')
            ->set('children.1.emergency_contact', 'Uncle, 555-0202')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Gran, 555-0101', $first->fresh()->emergency_contact);
        $this->assertSame('Uncle, 555-0202', $second->fresh()->emergency_contact);
    }

    /** A guardian who does not train themselves still gets the children block. */
    public function test_a_guardian_without_a_self_profile_still_sees_their_children(): void
    {
        $parent = User::factory()->create();
        PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Alex']);

        Livewire::actingAs($parent)
            ->test(ProfileForm::class)
            ->assertSet('has_player_profile', false)
            ->assertSee('Alex');
    }

    /**
     * A tampered child id must not reach another family. The test asserts the outcome rather than
     * the mechanism: guardianship re-resolution skips the row and the policy would refuse it
     * anyway, so this stays green while either guard holds — which is the point of having two.
     */
    public function test_a_submitted_child_id_outside_the_guardianship_is_ignored(): void
    {
        $parent = User::factory()->create();
        PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Alex']);

        $stranger = PlayerProfile::factory()
            ->child()
            ->guardedBy(User::factory()->create())
            ->create(['emergency_contact' => 'Untouched']);

        Livewire::actingAs($parent)
            ->test(ProfileForm::class)
            ->set('children.0.id', $stranger->id)
            ->set('children.0.emergency_contact', 'Injected')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Untouched', $stranger->fresh()->emergency_contact);
    }

    public function test_skill_level_is_displayed_but_never_written(): void
    {
        $user = User::factory()->role(Role::Player)->create();
        PlayerProfile::factory()->selfProfile($user)->create(['skill_level' => 'Beginner']);

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->assertSet('skill_level', 'Beginner')
            ->set('school', 'Northside High')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('player_profiles', [
            'user_id' => $user->id,
            'school' => 'Northside High',
            'skill_level' => 'Beginner',
        ]);
    }

    public function test_a_coach_edits_bio_credentials_certifications_and_public_flag(): void
    {
        $user = User::factory()->coach()->create();
        CoachProfile::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set([
                'bio' => 'Ten years coaching youth soccer.',
                'credentials' => 'USSF License',
                'certifications' => 'First Aid, CPR',
                'is_public' => false,
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('coach_profiles', [
            'user_id' => $user->id,
            'bio' => 'Ten years coaching youth soccer.',
            'credentials' => 'USSF License',
            'certifications' => 'First Aid, CPR',
            'is_public' => false,
        ]);
    }

    public function test_a_trainer_edits_their_business_profile(): void
    {
        $user = User::factory()->trainer()->create();
        TrainerProfile::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set([
                'business_name' => 'Peak Performance Academy',
                'address' => '123 Main St',
                'website' => 'https://peakperformance.example',
                'description' => 'Elite training for young athletes.',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('trainer_profiles', [
            'user_id' => $user->id,
            'business_name' => 'Peak Performance Academy',
            'address' => '123 Main St',
            'website' => 'https://peakperformance.example',
            'description' => 'Elite training for young athletes.',
        ]);
    }

    public function test_an_invalid_website_url_is_rejected(): void
    {
        $user = User::factory()->trainer()->create();
        TrainerProfile::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set('website', 'not-a-url')
            ->call('save')
            ->assertHasErrors(['website' => 'url']);
    }

    public function test_over_length_text_is_rejected(): void
    {
        $user = User::factory()->role(Role::Player)->create();
        PlayerProfile::factory()->selfProfile($user)->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->set('school', str_repeat('a', 256))
            ->call('save')
            ->assertHasErrors(['school' => 'max']);
    }

    public function test_a_user_with_no_matching_profile_sees_only_common_fields(): void
    {
        $user = User::factory()->role(Role::Player)->create();

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->assertSet('has_player_profile', false)
            ->assertSet('has_coach_profile', false)
            ->assertSet('has_trainer_profile', false)
            ->assertDontSee('Jersey number')
            ->assertDontSee('Bio')
            ->assertDontSee('Business name');
    }

    public function test_a_coach_sees_only_the_coach_field_set(): void
    {
        $user = User::factory()->coach()->create();
        CoachProfile::factory()->create(['user_id' => $user->id]);

        Livewire::actingAs($user)
            ->test(ProfileForm::class)
            ->assertSee('Bio')
            ->assertDontSee('Jersey number')
            ->assertDontSee('Business name');
    }

    /** FR-006 makes the business name mandatory at creation; editing must not be a way around it. */
    public function test_a_trainer_cannot_blank_out_their_business_name(): void
    {
        $profile = TrainerProfile::factory()->create(['business_name' => 'Elite Basketball Academy']);

        Livewire::actingAs($profile->user)
            ->test(ProfileForm::class)
            ->set('business_name', '')
            ->call('save')
            ->assertHasErrors(['business_name' => 'required']);

        $this->assertSame('Elite Basketball Academy', $profile->fresh()->business_name);
    }

    /**
     * Every derived or display-only property is locked, so a tampered client write fails loudly
     * instead of being silently discarded — the difference matters when a later slice starts
     * reading one of these flags for something other than which fieldset to render.
     *
     * `children` is deliberately not locked: the guardian edits it. Its ids are re-resolved
     * through the guardianship relation on save, which is what makes tampering harmless.
     */
    public function test_every_derived_property_rejects_a_client_write(): void
    {
        $user = User::factory()->role(Role::Player)->create();
        PlayerProfile::factory()->selfProfile($user)->create(['skill_level' => 'Beginner']);

        $locked = ['has_player_profile', 'skill_level', 'has_coach_profile', 'has_trainer_profile'];
        $unlocked = [];

        foreach ($locked as $property) {
            try {
                Livewire::actingAs($user)->test(ProfileForm::class)->set($property, 'tampered');
                $unlocked[] = $property;
            } catch (CannotUpdateLockedPropertyException) {
                // Expected: the property is locked.
            }
        }

        $this->assertSame([], $unlocked, 'Derived properties accepted a client write.');
    }
}
