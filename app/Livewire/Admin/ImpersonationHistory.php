<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\ImpersonationLog;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * FR-012's compliance report. Identity table (AD-001), unscoped — reads every organisation's
 * sessions, which is exactly what a Super Admin auditing impersonation needs to see. Paginated
 * per NFR-002's directory discipline (AD-012), same as UsersTable.
 */
final class ImpersonationHistory extends Component
{
    use WithPagination;

    public function mount(): void
    {
        $this->authorize('viewAny', ImpersonationLog::class);
    }

    public function render(): View
    {
        $this->authorize('viewAny', ImpersonationLog::class);

        return view('livewire.admin.impersonation-history', [
            'logs' => ImpersonationLog::query()
                ->with(['admin', 'target'])
                ->orderByDesc('started_at')
                ->simplePaginate(25),
        ]);
    }
}
