<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Livewire\Admin\CreateTrainerForm;
use App\Models\User;
use App\Notifications\TrainerInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

/** FR-006 / BR-003: only a Super Admin creates trainers, and no password is ever mailed. */
final class CreateTrainerAccountTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    protected function validPayload(): array
    {
        return [
            'businessName' => 'Elite Basketball Academy',
            'firstName' => 'Dana',
            'lastName' => 'Reeve',
            'email' => 'dana@example.test',
            'phone' => '+1 555 0142',
        ];
    }

    public function test_a_super_admin_creates_a_trainer_with_a_profile(): void
    {
        Notification::fake();

        Livewire::actingAs(User::factory()->superAdmin()->create())
            ->test(CreateTrainerForm::class)
            ->set($this->validPayload())
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'dana@example.test',
            'role' => Role::Trainer->value,
            'status' => UserStatus::Active->value,
        ]);

        $this->assertDatabaseHas('trainer_profiles', [
            'business_name' => 'Elite Basketball Academy',
            'slug' => 'elite-basketball-academy',
        ]);
    }

    public function test_the_invitation_is_sent_and_carries_no_password(): void
    {
        Notification::fake();

        Livewire::actingAs(User::factory()->superAdmin()->create())
            ->test(CreateTrainerForm::class)
            ->set($this->validPayload())
            ->call('save');

        $trainer = User::where('email', 'dana@example.test')->firstOrFail();

        Notification::assertSentTo($trainer, TrainerInvitation::class);
        $this->assertFalse(Hash::check('password', $trainer->password));
    }

    /**
     * The invitation carries no token at all: nothing sensitive reaches the serialized queue
     * payload, and the mail cannot go stale — a 60-minute token-bearing link would have been dead
     * for anyone opening the invitation the next morning. The trainer mints their own token from
     * the password-request form when they are ready.
     */
    public function test_the_invitation_carries_no_token_and_cannot_expire(): void
    {
        Notification::fake();

        Livewire::actingAs(User::factory()->superAdmin()->create())
            ->test(CreateTrainerForm::class)
            ->set($this->validPayload())
            ->call('save');

        $trainer = User::where('email', 'dana@example.test')->firstOrFail();

        $this->assertNull((new ReflectionClass(TrainerInvitation::class))->getConstructor());

        $mail = (new TrainerInvitation)->toMail($trainer);

        $this->assertSame(route('password.request'), $mail->actionUrl);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_the_creation_is_audited_with_the_acting_admin(): void
    {
        Notification::fake();
        $admin = User::factory()->superAdmin()->create();

        Livewire::actingAs($admin)
            ->test(CreateTrainerForm::class)
            ->set($this->validPayload())
            ->call('save');

        $trainer = User::where('email', 'dana@example.test')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'trainer.created',
            'actor_user_id' => $admin->id,
            'on_behalf_of_user_id' => null,
            'subject_type' => User::class,
            'subject_id' => $trainer->id,
        ]);
    }

    public function test_a_duplicate_email_is_a_field_error_not_a_server_error(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'dana@example.test']);

        Livewire::actingAs(User::factory()->superAdmin()->create())
            ->test(CreateTrainerForm::class)
            ->set($this->validPayload())
            ->call('save')
            ->assertHasErrors(['email' => 'unique']);

        $this->assertDatabaseMissing('trainer_profiles', ['business_name' => 'Elite Basketball Academy']);
    }

    public function test_required_fields_are_enforced(): void
    {
        Livewire::actingAs(User::factory()->superAdmin()->create())
            ->test(CreateTrainerForm::class)
            ->set(['businessName' => '', 'firstName' => '', 'lastName' => '', 'email' => '', 'phone' => ''])
            ->call('save')
            ->assertHasErrors(['businessName', 'firstName', 'lastName', 'email', 'phone']);
    }

    public function test_a_non_admin_cannot_open_the_form(): void
    {
        $this->actingAs(User::factory()->trainer()->create())
            ->get('/admin/users/create')
            ->assertForbidden();
    }
}
