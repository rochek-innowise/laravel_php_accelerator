<?php

declare(strict_types=1);

namespace Tests\Feature\Family;

use App\Livewire\Family\Overview;
use App\Models\PlayerProfile;
use App\Models\ShareLink;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\Tenancy\TenantScope;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FR-009. Both "add" paths reuse Slice B actions unmodified; "remove" (a soft delete) is the one
 * genuinely new piece.
 */
final class OverviewTest extends TestCase
{
    #[Test]
    public function the_family_view_lists_only_the_acting_guardians_own_children_with_their_trainers(): void
    {
        $parent = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create(['name' => 'Mine']);
        $trainer = TrainerProfile::factory()->create();
        $association = TrainerPlayer::factory()->create([
            'trainer_profile_id' => $trainer->id,
            'player_profile_id' => $child->id,
        ]);

        $stranger = PlayerProfile::factory()->child()->create(['name' => 'Not mine']);

        Livewire::actingAs($parent)
            ->test(Overview::class)
            ->assertSee('Mine')
            ->assertDontSee('Not mine')
            ->assertSee($trainer->business_name)
            ->assertSee($association->connected_at->toFormattedDateString());
    }

    #[Test]
    public function removing_an_association_soft_deletes_it_and_the_child_leaves_the_roster(): void
    {
        $parent = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create();
        $trainer = TrainerProfile::factory()->create();
        $association = TrainerPlayer::factory()->create([
            'trainer_profile_id' => $trainer->id,
            'player_profile_id' => $child->id,
        ]);

        Livewire::actingAs($parent)
            ->test(Overview::class)
            ->call('remove', $association->id)
            ->assertHasNoErrors();

        $fresh = TrainerPlayer::withoutGlobalScope(TenantScope::class)->withTrashed()->findOrFail($association->id);
        $this->assertNotNull($fresh->deleted_at);

        $this->assertSame(
            0,
            TrainerPlayer::withoutGlobalScope(TenantScope::class)->where('player_profile_id', $child->id)->count(),
            'The live roster query must not see the removed row.'
        );
        $this->assertSame(
            1,
            TrainerPlayer::withoutGlobalScope(TenantScope::class)->withTrashed()->where('player_profile_id', $child->id)->count(),
            'History is preserved.'
        );
    }

    #[Test]
    public function re_adding_the_same_trainer_after_removal_creates_a_new_row_not_a_collision(): void
    {
        $parent = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($parent)->create();
        $trainer = TrainerProfile::factory()->create();
        $link = ShareLink::factory()->create(['trainer_profile_id' => $trainer->id]);

        $association = TrainerPlayer::factory()->create([
            'trainer_profile_id' => $trainer->id,
            'player_profile_id' => $self->id,
        ]);

        Livewire::actingAs($parent)->test(Overview::class)->call('remove', $association->id);

        Livewire::actingAs($parent)
            ->test(Overview::class)
            ->set("manualCode.{$self->id}", $link->code)
            ->call('addByCode', $self->id)
            ->assertHasNoErrors();

        $this->assertSame(
            2,
            TrainerPlayer::withoutGlobalScope(TenantScope::class)->withTrashed()
                ->where(['player_profile_id' => $self->id, 'trainer_profile_id' => $trainer->id])->count(),
            'Two rows total: the removed one, plus the freshly re-added one.'
        );
        $this->assertSame(
            1,
            TrainerPlayer::withoutGlobalScope(TenantScope::class)
                ->where(['player_profile_id' => $self->id, 'trainer_profile_id' => $trainer->id])->count(),
            'Only the new row is live.'
        );
    }

    #[Test]
    public function the_manual_code_path_adds_by_invitation_code(): void
    {
        $parent = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($parent)->create();
        $trainer = TrainerProfile::factory()->create();
        $link = ShareLink::factory()->create(['trainer_profile_id' => $trainer->id]);

        Livewire::actingAs($parent)
            ->test(Overview::class)
            ->set("manualCode.{$self->id}", $link->code)
            ->call('addByCode', $self->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('trainer_players', [
            'player_profile_id' => $self->id,
            'trainer_profile_id' => $trainer->id,
        ]);
    }

    /** The manual-code path rejects an inactive code with the same copy `/join/{code}` uses. */
    #[Test]
    public function the_manual_code_path_rejects_an_inactive_code_with_the_shared_copy(): void
    {
        $parent = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($parent)->create();
        $link = ShareLink::factory()->inactive()->create();

        Livewire::actingAs($parent)
            ->test(Overview::class)
            ->set("manualCode.{$self->id}", $link->code)
            ->call('addByCode', $self->id)
            ->assertHasErrors(["manualCode.{$self->id}"]);

        $this->assertDatabaseCount('trainer_players', 0);
    }

    #[Test]
    public function the_existing_trainer_picker_only_offers_trainers_already_reachable_by_the_family(): void
    {
        $parent = User::factory()->create();
        $self = PlayerProfile::factory()->selfProfile($parent)->create();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create();
        $familyTrainer = TrainerProfile::factory()->create(['business_name' => 'Family Gym']);
        TrainerPlayer::factory()->create(['trainer_profile_id' => $familyTrainer->id, 'player_profile_id' => $self->id]);

        $stranger = TrainerProfile::factory()->create(['business_name' => 'Stranger Gym']);

        Livewire::actingAs($parent)
            ->test(Overview::class)
            ->set("pickerTrainerId.{$child->id}", $familyTrainer->id)
            ->call('addTrainer', $child->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('trainer_players', [
            'player_profile_id' => $child->id,
            'trainer_profile_id' => $familyTrainer->id,
        ]);

        Livewire::actingAs($parent)
            ->test(Overview::class)
            ->set("pickerTrainerId.{$child->id}", $stranger->id)
            ->call('addTrainer', $child->id)
            ->assertForbidden();

        $this->assertDatabaseMissing('trainer_players', [
            'player_profile_id' => $child->id,
            'trainer_profile_id' => $stranger->id,
        ]);
    }

    #[Test]
    public function a_non_guardian_is_refused_every_manage_action(): void
    {
        $parent = User::factory()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create();
        $trainer = TrainerProfile::factory()->create();
        $association = TrainerPlayer::factory()->create([
            'trainer_profile_id' => $trainer->id,
            'player_profile_id' => $child->id,
        ]);

        $stranger = User::factory()->create();

        // 404, not 403: a stranger's own trainableProfiles() never includes this child at all, so
        // the row (and the profile) is not merely forbidden to them — it does not exist as far as
        // they can tell (AD-009's route-model-binding reasoning, applied by hand here).
        Livewire::actingAs($stranger)->test(Overview::class)->call('remove', $association->id)->assertNotFound();
        Livewire::actingAs($stranger)
            ->test(Overview::class)
            ->set("manualCode.{$child->id}", 'whatever')
            ->call('addByCode', $child->id)
            ->assertNotFound();
    }

    #[Test]
    public function a_child_login_is_refused_every_manage_action(): void
    {
        $parent = User::factory()->create();
        $childLogin = User::factory()->childAccount()->create();
        $child = PlayerProfile::factory()->child()->guardedBy($parent)->create(['user_id' => $childLogin->id]);
        $trainer = TrainerProfile::factory()->create();
        $association = TrainerPlayer::factory()->create([
            'trainer_profile_id' => $trainer->id,
            'player_profile_id' => $child->id,
        ]);

        Livewire::actingAs($childLogin)->test(Overview::class)->call('remove', $association->id)->assertForbidden();
        Livewire::actingAs($childLogin)
            ->test(Overview::class)
            ->set("manualCode.{$child->id}", 'whatever')
            ->call('addByCode', $child->id)
            ->assertForbidden();
    }
}
