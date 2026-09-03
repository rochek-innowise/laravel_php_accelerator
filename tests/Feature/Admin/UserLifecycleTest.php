<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Admin\AnonymizeUser;
use App\Actions\Admin\DeactivateUser;
use App\Actions\Admin\ReactivateUser;
use App\Enums\UserStatus;
use App\Exceptions\UserLifecycleException;
use App\Models\AuditLog;
use App\Models\CoachProfile;
use App\Models\PlayerProfile;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Models\UserDeletionLog;
use App\Notifications\PurchaseApprovalRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FR-017 (deactivate/reactivate) and FR-018 (GDPR anonymization) at the Action level — the
 * Livewire UI that calls these is covered separately by UsersDirectoryLifecycleTest.
 */
final class UserLifecycleTest extends TestCase
{
    public function test_deactivating_flips_status_and_is_audited(): void
    {
        $target = User::factory()->create();

        app(DeactivateUser::class)->handle($target);

        $this->assertSame(UserStatus::Inactive, $target->fresh()->status);
        $this->assertNotNull(AuditLog::where('action', 'user.deactivated')->where('subject_id', $target->id)->first());
    }

    /** AD-015: EnsureAccountRemainsActive already re-checks status per request — reused, not rebuilt. */
    public function test_deactivating_ends_the_users_live_session_on_the_very_next_request(): void
    {
        $target = User::factory()->create();

        $this->actingAs($target)->get('/profile')->assertOk();

        app(DeactivateUser::class)->handle($target);

        $this->get('/profile')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => UserStatus::DEACTIVATED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_deactivating_an_already_deleted_user_is_refused(): void
    {
        $target = User::factory()->status(UserStatus::Deleted)->create();

        $this->expectException(UserLifecycleException::class);

        app(DeactivateUser::class)->handle($target);
    }

    public function test_reactivating_flips_status_and_is_audited(): void
    {
        $target = User::factory()->status(UserStatus::Inactive)->create();

        app(ReactivateUser::class)->handle($target);

        $this->assertSame(UserStatus::Active, $target->fresh()->status);
        $this->assertNotNull(AuditLog::where('action', 'user.reactivated')->where('subject_id', $target->id)->first());
    }

    public function test_reactivating_restores_login(): void
    {
        $target = User::factory()->status(UserStatus::Inactive)->create();

        app(ReactivateUser::class)->handle($target);

        $this->post('/login', ['email' => $target->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($target);
    }

    /** BR-018: reactivation of a GDPR-anonymized account must be impossible. */
    public function test_reactivating_an_already_deleted_user_is_refused(): void
    {
        $target = User::factory()->status(UserStatus::Deleted)->create();

        $this->expectException(UserLifecycleException::class);

        app(ReactivateUser::class)->handle($target);
    }

    public function test_anonymizing_maps_every_field_exactly(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->create([
            'first_name' => 'Zinaida',
            'last_name' => 'Petrenko',
            'email' => 'zin@example.test',
            'phone' => '+1-555-0100',
        ]);
        $originalHash = $target->password;

        app(AnonymizeUser::class)->handle($target, $actor, 'User requested erasure.');

        $target->refresh();

        $this->assertSame('Deleted', $target->first_name);
        $this->assertSame('User', $target->last_name);
        $this->assertSame("deleted_{$target->id}@deleted.invalid", $target->email);
        $this->assertStringEndsWith('.invalid', $target->email);
        $this->assertNotSame($originalHash, $target->password);
        $this->assertNull($target->phone);
        // Raw attribute access, not the typed property: remember_token being unconditionally
        // non-null by the model's own docblock is exactly the "never null" guarantee under test.
        $this->assertNull($target->getAttributes()['remember_token'] ?? null);
        $this->assertSame(UserStatus::Deleted, $target->status);
    }

    public function test_anonymizing_deletes_the_stored_photo_from_disk(): void
    {
        Storage::fake('local');

        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['photo_path' => 'profile-photos/users/999/original.jpg']);

        Storage::disk('local')->put($target->photo_path, 'fake-image-bytes');

        app(AnonymizeUser::class)->handle($target, $actor);

        $this->assertNull($target->fresh()->photo_path);
        Storage::disk('local')->assertMissing('profile-photos/users/999/original.jpg');
    }

    public function test_anonymizing_clears_sessions_and_password_reset_tokens_for_the_original_address(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['email' => 'zin@example.test']);

        DB::table('sessions')->insert([
            'id' => 'a-session-id',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'irrelevant',
            'last_activity' => now()->timestamp,
        ]);
        DB::table('password_reset_tokens')->insert([
            'email' => 'zin@example.test',
            'token' => 'a-token',
            'created_at' => now(),
        ]);

        app(AnonymizeUser::class)->handle($target, $actor);

        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'zin@example.test']);
    }

    public function test_anonymizing_writes_the_deletion_log_before_the_scrub_using_the_original_email(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['email' => 'zin@example.test']);

        app(AnonymizeUser::class)->handle($target, $actor, 'GDPR request');

        $log = UserDeletionLog::where('original_user_id', $target->id)->sole();

        // The hash was computed from the *original* address, not the post-scrub deleted_{id}@…
        // one — proof the log write happened before the field overwrite, not after.
        $this->assertSame(UserDeletionLog::hashEmail('zin@example.test'), $log->email_hash);
        $this->assertSame($actor->id, $log->deleted_by_user_id);
        $this->assertSame('GDPR request', $log->reason);
    }

    /**
     * Comparable across rows: two different people who used the same address, anonymized at
     * different times, hash identically — this is what makes "was this address ever erased" /
     * "is this person re-registering" answerable across the whole table, not just within one
     * call. (This replaces a version of this test that only asserted `hashEmail($x) ===
     * hashEmail($x)`, passing for any deterministic implementation including one returning a
     * constant, and involving no row at all despite its name — already pinned, more narrowly, by
     * UserDeletionLogTest's own case/whitespace test.)
     */
    public function test_the_email_hash_is_comparable_across_two_actual_deletion_log_rows(): void
    {
        $actor = User::factory()->superAdmin()->create();

        $first = User::factory()->create(['email' => 'zin@example.test']);
        app(AnonymizeUser::class)->handle($first, $actor);

        // Free again once $first was anonymized off it (deleted_{id}@deleted.invalid).
        $second = User::factory()->create(['email' => 'zin@example.test']);
        app(AnonymizeUser::class)->handle($second, $actor);

        $firstLog = UserDeletionLog::where('original_user_id', $first->id)->sole();
        $secondLog = UserDeletionLog::where('original_user_id', $second->id)->sole();

        $this->assertSame($firstLog->email_hash, $secondLog->email_hash);
    }

    /**
     * Gap 4: the only assertion here used to be on `name`. Decision 7's mapping table names six
     * fields (plus the photo); deleting any one of them from `anonymizeProfile()`'s `forceFill`
     * must fail this test.
     */
    public function test_anonymizing_scrubs_the_targets_own_self_profile(): void
    {
        Storage::fake('local');

        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->create();
        $profile = PlayerProfile::factory()->selfProfile($target)->create([
            'name' => 'Zinaida Petrenko',
            'birth_date' => '1990-05-01',
            'gender' => 'female',
            'school' => 'Kyiv School #5',
            'jersey_number' => '7',
            'emergency_contact' => '+1-555-0199',
        ]);
        $photoPath = 'profile-photos/players/'.$profile->id.'/original.jpg';
        Storage::disk('local')->put($photoPath, 'fake-image-bytes');
        $profile->forceFill(['photo_path' => $photoPath])->save();

        app(AnonymizeUser::class)->handle($target, $actor);

        $profile->refresh();

        $this->assertSame('Deleted User', $profile->name);
        $this->assertNull($profile->birth_date);
        $this->assertNull($profile->gender);
        $this->assertNull($profile->school);
        $this->assertNull($profile->jersey_number);
        $this->assertNull($profile->emergency_contact);
        $this->assertNull($profile->photo_path);
        // Full-size only, no thumbnail — Slice C Decision 5.
        Storage::disk('local')->assertMissing($photoPath);
    }

    /** Gap 6 — the regression most likely to be silently skipped. */
    public function test_anonymizing_scrubs_a_child_only_the_target_solely_guards(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $soleGuardian = User::factory()->create();
        $coGuardian1 = User::factory()->create();
        $coGuardian2 = User::factory()->create();

        $solelyGuardedChild = PlayerProfile::factory()
            ->child()
            ->guardedBy($soleGuardian)
            ->create(['name' => 'Solo Child']);

        $coGuardedChild = PlayerProfile::factory()->child()->create(['name' => 'Shared Child']);
        $coGuardedChild->guardians()->attach([$coGuardian1->id, $coGuardian2->id]);

        app(AnonymizeUser::class)->handle($soleGuardian, $actor);
        app(AnonymizeUser::class)->handle($coGuardian1, $actor);

        $this->assertSame('Deleted User', $solelyGuardedChild->fresh()->name);
        $this->assertSame('Shared Child', $coGuardedChild->fresh()->name, 'A co-guarded child must survive untouched.');
    }

    public function test_anonymizing_an_already_deleted_user_is_refused(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->status(UserStatus::Deleted)->create();

        $this->expectException(UserLifecycleException::class);

        app(AnonymizeUser::class)->handle($target, $actor);
    }

    public function test_anonymizing_is_audited(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        app(AnonymizeUser::class)->handle($target, $actor, 'A stated reason');

        $log = AuditLog::where('action', 'user.anonymized')->where('subject_id', $target->id)->sole();

        $this->assertSame('A stated reason', $log->metadata['reason'] ?? null);
    }

    /**
     * Gap 1: `notifications.data` persists a plaintext `child_name` in four notification classes.
     * Both leaks close: the target's own notification history, and any other guardian's
     * notification that still names a child whose real name was just scrubbed elsewhere.
     * `test_anonymizing_purges_notifications_...but_leaves_others_alone` below proves the second
     * half doesn't over-reach into an untouched child's notifications.
     */
    public function test_anonymizing_deletes_the_targets_own_notifications(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->create();

        $this->insertNotification($target, 999, 'Somebody Else\'s Child');

        app(AnonymizeUser::class)->handle($target, $actor);

        $this->assertSame(
            0,
            DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $target->id)->count()
        );
    }

    public function test_anonymizing_purges_notifications_naming_an_anonymized_child_but_leaves_others_alone(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $target = User::factory()->create();
        $selfProfile = PlayerProfile::factory()->selfProfile($target)->create(['name' => 'Zinaida Petrenko']);

        $coGuardian1 = User::factory()->create();
        $coGuardian2 = User::factory()->create();
        $untouchedChild = PlayerProfile::factory()->child()->create(['name' => 'Untouched Child']);
        $untouchedChild->guardians()->attach([$coGuardian1->id, $coGuardian2->id]);

        // Addressed to another guardian, but names the profile the target's own erasure scrubs.
        $this->insertNotification($coGuardian1, $selfProfile->id, 'Zinaida Petrenko');
        // Addressed to, and about, a child untouched by this erasure — must survive untouched.
        $survivingId = $this->insertNotification($coGuardian1, $untouchedChild->id, 'Untouched Child');

        app(AnonymizeUser::class)->handle($target, $actor);

        $this->assertSame(0, DB::table('notifications')->where('data->player_profile_id', $selfProfile->id)->count());
        $this->assertDatabaseHas('notifications', ['id' => $survivingId]);
    }

    /**
     * Gap 2. `bio`/`credentials`/`certifications` are free text and routinely self-identifying;
     * `status`/`trainer_profile_id`/`joined_at` are left alone — roster and attendance history
     * read those.
     */
    public function test_anonymizing_scrubs_the_targets_own_coach_identity(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $trainerProfile = TrainerProfile::factory()->create();
        $coach = CoachProfile::factory()->create([
            'trainer_profile_id' => $trainerProfile->id,
            'bio' => 'Ex-professional midfielder, coaching since 2010.',
            'credentials' => 'UEFA A License',
            'certifications' => 'First Aid, Safeguarding',
            'is_public' => true,
        ]);
        $target = $coach->user;

        app(AnonymizeUser::class)->handle($target, $actor);

        $coach->refresh();
        $this->assertNull($coach->bio);
        $this->assertNull($coach->credentials);
        $this->assertNull($coach->certifications);
        // Not personal/identifying data (Gap 2) — attendance/roster rendering still needs these.
        $this->assertSame('active', $coach->status->value);
        $this->assertSame($trainerProfile->id, $coach->trainer_profile_id);
    }

    /**
     * Gap 2. No carve-out: a trainer's business identity is scrubbed the same way a deleted
     * user's own name is — "Deleted Organisation", matching BR-018's "rendering as Deleted User"
     * pattern — since for a sole trader `business_name`/`address` unambiguously are personal data.
     * `logo_path`/`primary_color` are untouched: the finding names free text/identifying columns
     * only, and those two are visual branding rather than identifying text.
     */
    public function test_anonymizing_scrubs_the_targets_own_trainer_business_identity(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $trainer = TrainerProfile::factory()->create([
            'business_name' => 'Acme Sports Academy',
            'address' => '123 Main Street',
            'website' => 'https://acme-sports.test',
            'description' => 'A friendly neighbourhood club.',
            'primary_color' => '#0EA5E9',
        ]);
        $target = $trainer->user;

        app(AnonymizeUser::class)->handle($target, $actor);

        $trainer->refresh();
        $this->assertSame('Deleted Organisation', $trainer->business_name);
        $this->assertSame('deleted-organisation-'.$trainer->id, $trainer->slug);
        $this->assertNull($trainer->address);
        $this->assertNull($trainer->website);
        $this->assertNull($trainer->description);
        $this->assertSame('#0EA5E9', $trainer->primary_color);
    }

    protected function insertNotification(User $notifiable, int $playerProfileId, string $childName): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => PurchaseApprovalRequested::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $notifiable->id,
            'data' => json_encode(['player_profile_id' => $playerProfileId, 'child_name' => $childName]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
