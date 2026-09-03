<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\DayOfWeek;
use App\Enums\TrainerPlayerStatus;
use App\Jobs\CloseStaleImpersonationLogsJob;
use App\Livewire\Admin\ImpersonationHistory;
use App\Models\Availability;
use App\Models\ImpersonationLog;
use App\Models\PlayerProfile;
use App\Models\TrainerPlayer;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Services\Availability\AvailabilityResolver;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NFR-002's Database half of the Test Plan (10,000 users, under 3s), the one half nothing pinned:
 * the `impersonation_logs` indexes exercised by the sweep and history queries, and the two
 * `availabilities` indexes exercised by `AvailabilityResolver`. Query count/shape (the
 * `TenancyQueryBudgetTest` pattern) can't catch a full table scan on its own, so this asserts the
 * actual `EXPLAIN` plan MariaDB chooses instead — captured off the real SQL the production code
 * paths issue (via `DB::listen()`), never a hand-reconstructed query that could drift from what
 * actually runs.
 *
 * Row counts here are representative, not literally 10,000 — large enough that MariaDB's optimizer
 * has a real choice to make and a full scan would show up as one, not so large that the suite
 * pays for it every run.
 */
final class IndexUsageTest extends TestCase
{
    #[Test]
    public function the_stale_impersonation_sweep_is_served_by_the_ended_at_started_at_index(): void
    {
        ImpersonationLog::factory()->count(50)->create();
        ImpersonationLog::factory()->count(50)->ended()->create();

        $plan = $this->explainFirstQueryAgainst('impersonation_logs', function (): void {
            (new CloseStaleImpersonationLogsJob)->handle();
        });

        $this->assertIndexServed($plan, 'impersonation_logs', ['impersonation_logs_ended_at_started_at_index']);
    }

    #[Test]
    public function the_impersonation_history_report_is_served_by_the_target_user_id_index_when_filtered(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['email' => 'audited@example.test']);

        ImpersonationLog::factory()->count(100)->create();
        ImpersonationLog::factory()->count(5)->create(['target_user_id' => $target->id]);

        // Mounted (and its own unfiltered render) before capture starts — the query under test is
        // the one the *filtered* re-render issues, not the initial unfiltered one.
        $component = Livewire::actingAs($admin)->test(ImpersonationHistory::class);

        $plan = $this->explainFirstQueryAgainst('impersonation_logs', function () use ($component, $target): void {
            $component->set('targetEmail', $target->email);
        });

        $this->assertIndexServed($plan, 'impersonation_logs', ['impersonation_logs_target_user_id_started_at_index']);
    }

    /**
     * The Grid's own read path (`resolve()`/`isUsingDefault()`, both routed through the same
     * protected `query()`): keyed on `available_for_type`/`available_for_id`/`trainer_profile_id`,
     * so it is the three-column composite that serves it.
     */
    #[Test]
    public function the_grid_read_path_is_served_by_the_available_for_trainer_composite_index(): void
    {
        $this->seedAvailabilityNoise(400);

        $subject = PlayerProfile::factory()->create();
        Availability::factory()->forSubject($subject)->create(['day_of_week' => DayOfWeek::Monday]);

        $plan = $this->explainFirstQueryAgainst('availabilities', function () use ($subject): void {
            app(AvailabilityResolver::class)->resolve($subject, null);
        });

        $this->assertIndexServed($plan, 'availabilities', ['availabilities_available_for_trainer_index']);
    }

    /**
     * FR-014's CRM filter (Gap 5): `rosterAvailableAt()`'s correlated sub-selects are keyed
     * primarily on `trainer_profile_id`/`day_of_week`/`start_time`, so MariaDB reaches for that
     * composite here even though `available_for_trainer` is an equally valid candidate key (both
     * appear in `possible_keys`) — this is the same index Decision 3 names, exercised from the
     * other caller.
     */
    #[Test]
    public function roster_available_at_is_index_served_with_no_full_table_scan_on_availabilities(): void
    {
        $this->seedAvailabilityNoise(400);

        $trainer = TrainerProfile::factory()->create();

        foreach (range(1, 50) as $i) {
            $player = PlayerProfile::factory()->create();
            TrainerPlayer::factory()->create([
                'trainer_profile_id' => $trainer->id,
                'player_profile_id' => $player->id,
                'status' => TrainerPlayerStatus::Active,
            ]);
            Availability::factory()->forSubject($player)->create([
                'day_of_week' => DayOfWeek::Monday,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
            ]);
        }

        $query = app(AvailabilityResolver::class)->rosterAvailableAt($trainer, DayOfWeek::Monday, '10:00:00', '11:00:00');

        $rows = collect($query->explain());
        $availabilityRows = $rows->filter(fn (object $row): bool => ($row->table ?? null) === 'availabilities');

        $this->assertNotEmpty($availabilityRows, 'Expected the plan to touch `availabilities` at all.');

        foreach ($availabilityRows as $row) {
            $this->assertNotSame(
                'ALL',
                $row->type,
                'A full table scan against `availabilities` — expected an index-served access. Row: '.json_encode($row)
            );
            $this->assertContains(
                $row->key,
                ['availabilities_available_for_trainer_index', 'availabilities_trainer_profile_id_day_of_week_start_time_index'],
                'Expected one of Decision 3\'s two named indexes to serve this row. Row: '.json_encode($row)
            );
        }
    }

    /** Unrelated rows spread across every day, so the optimizer is choosing over a real table size rather than a handful of rows. */
    protected function seedAvailabilityNoise(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $profile = PlayerProfile::factory()->create();
            Availability::factory()->forSubject($profile)->create([
                'day_of_week' => DayOfWeek::from($i % 7),
                'start_time' => '08:00:00',
                'end_time' => '18:00:00',
            ]);
        }
    }

    /**
     * Runs `$work`, captures the first SELECT issued against `$table`, and returns its `EXPLAIN`
     * plan — the real SQL and bindings the production code path issued, never a hand-reconstructed
     * stand-in that could silently drift from what actually runs.
     *
     * @return list<object>
     */
    protected function explainFirstQueryAgainst(string $table, \Closure $work): array
    {
        $captured = null;

        $listener = function ($query) use ($table, &$captured): void {
            if ($captured === null && str_contains($query->sql, "from `{$table}`") && str_starts_with(trim($query->sql), 'select')) {
                $captured = [$query->sql, $query->bindings];
            }
        };

        DB::listen($listener);
        $work();

        $this->assertNotNull($captured, "No SELECT against `{$table}` was captured.");

        [$sql, $bindings] = $captured;

        return DB::select('EXPLAIN '.$sql, $bindings);
    }

    /** @param  list<object>  $plan */
    protected function assertIndexServed(array $plan, string $table, array $allowedKeys): void
    {
        $rows = collect($plan)->filter(fn (object $row): bool => ($row->table ?? null) === $table);

        $this->assertNotEmpty($rows, "Expected the plan to touch `{$table}` at all.");

        foreach ($rows as $row) {
            $this->assertNotSame('ALL', $row->type, "A full table scan against `{$table}`. Row: ".json_encode($row));
            $this->assertContains($row->key, $allowedKeys, 'Row did not use an expected index. Row: '.json_encode($row));
        }
    }
}
