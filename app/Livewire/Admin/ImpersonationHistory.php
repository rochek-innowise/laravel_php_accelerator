<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * FR-012's compliance report. Identity table (AD-001), unscoped — reads every organisation's
 * sessions, which is exactly what a Super Admin auditing impersonation needs to see. Paginated
 * per NFR-002's directory discipline (AD-012), same as UsersTable.
 *
 * Gap 9: the migration's `(target_user_id, started_at)` index was documented as serving "every
 * session for one target", but no per-target filter was ever built — the query it was meant for
 * simply didn't exist, leaving the composite dead and the unfiltered `orderByDesc('started_at')`
 * an unindexed full sort. `targetEmail` is that filter: a Super Admin auditing "every session
 * against this user" is the obvious use of a compliance report, and it's genuinely useful, so this
 * adds the query rather than replacing the index with something plainer.
 */
final class ImpersonationHistory extends Component
{
    use WithPagination;

    #[Url]
    public string $targetEmail = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ImpersonationLog::class);
    }

    public function updated(string $property): void
    {
        if ($property === 'targetEmail') {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $this->authorize('viewAny', ImpersonationLog::class);

        $targetId = $this->targetEmail !== ''
            ? User::where('email', $this->targetEmail)->value('id')
            : null;

        return view('livewire.admin.impersonation-history', [
            'logs' => ImpersonationLog::query()
                ->with(['admin', 'target'])
                // An email that matches nobody must show no rows, not every row: filtering on a
                // literal-false condition rather than skipping the `when()` keeps that true without
                // a separate empty-state branch.
                ->when(
                    $this->targetEmail !== '',
                    fn (Builder $query): Builder => $query->where('target_user_id', $targetId ?? 0)
                )
                ->orderByDesc('started_at')
                ->simplePaginate(25),
        ]);
    }
}
