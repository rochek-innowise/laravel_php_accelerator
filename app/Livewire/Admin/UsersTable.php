<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * FR-005: the Super Admin directory. Search is tool-scoped by requirement — it never fans out
 * across unrelated tables — and pagination stays server-side for the 10k-user target (AD-012).
 */
final class UsersTable extends Component
{
    use WithPagination;

    public string $search = '';

    public string $roleFilter = '';

    public string $statusFilter = '';

    public function render(): View
    {
        // TODO(coder): authorize viewAny, build the filtered query, paginate with simplePaginate().
        throw new \RuntimeException('Not implemented');
    }
}
