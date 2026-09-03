<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Livewire\Admin\UsersTable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        User::factory()->create(['first_name' => 'Zinaida', 'email' => 'zin@example.test']);
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

    /**
     * NFR-002: a page of the directory must cost a constant number of queries, not one that grows
     * with the table. The component issues exactly one today — the paginated `simplePaginate`
     * select, which fetches only the page plus one lookahead row rather than a separate COUNT.
     * The bound is 2, one query of headroom, so a genuine regression (e.g. an eager-loaded
     * relation turning lazy) still fails the test instead of being silently absorbed.
     */
    public function test_rendering_the_directory_issues_a_bounded_number_of_queries(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->count(300)->create();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        Livewire::actingAs($admin)->test(UsersTable::class);

        $this->assertLessThanOrEqual(2, $queryCount);
    }

    /** FR-012: the row action is visible per @can('impersonate', $user), not a blanket button. */
    public function test_the_impersonate_button_is_visible_only_for_an_eligible_target(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $eligible = User::factory()->create();
        $otherAdmin = User::factory()->superAdmin()->create();

        Livewire::actingAs($admin)
            ->test(UsersTable::class)
            ->assertSeeHtml("impersonate({$eligible->id})")
            ->assertDontSeeHtml("impersonate({$otherAdmin->id})");
    }

    /**
     * Finding 1 (Slice D): `$user->name` used to be interpolated into an inline
     * `onsubmit="return confirm('...')"` attribute — an HTML attribute in a JS-parsing context,
     * where `{{ }}`'s escaping decodes right back to a literal `'` before the JS tokenizer ever
     * sees it. A name containing a payload like this one could break out of the confirm() string
     * and run script in the Super Admin's origin. The fix removes the inline handler entirely
     * (wire:confirm reads the attribute as data, never through a JS parser), so this pins that no
     * executable-context payload survives anywhere in the rendered row.
     */
    public function test_a_malicious_name_does_not_reach_an_executable_context(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $payload = "'); alert(1); //";
        User::factory()->create(['first_name' => $payload, 'last_name' => 'Evil']);

        $html = Livewire::actingAs($admin)->test(UsersTable::class)->html();

        $this->assertStringNotContainsString('onsubmit=', $html);
        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringContainsString(e($payload), $html);
    }
}
