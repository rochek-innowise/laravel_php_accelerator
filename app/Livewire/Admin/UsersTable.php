<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * FR-005: the Super Admin directory. Search is tool-scoped by requirement — it never fans out
 * across unrelated tables — and pagination stays server-side for the 10k-user target (AD-012).
 */
final class UsersTable extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $roleFilter = '';

    #[Url]
    public string $statusFilter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'roleFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $this->authorize('viewAny', User::class);

        return view('livewire.admin.users-table', [
            'users' => $this->query()->simplePaginate(25),
            'roles' => Role::cases(),
            'statuses' => UserStatus::cases(),
        ]);
    }

    /** @return Builder<User> */
    protected function query(): Builder
    {
        return User::query()
            ->when($this->roleFilter !== '', fn (Builder $q) => $q->where('role', $this->roleFilter))
            ->when($this->statusFilter !== '', fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', function (Builder $q): void {
                $term = '%'.$this->search.'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('id');
    }
}
