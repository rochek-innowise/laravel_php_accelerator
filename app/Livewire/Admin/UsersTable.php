<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Admin\AnonymizeUser;
use App\Actions\Admin\DeactivateUser;
use App\Actions\Admin\ReactivateUser;
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
 *
 * FR-017/FR-018 (Slice D Track C): deactivate, reactivate and delete are Livewire methods on this
 * same component rather than three redirect-only Controller endpoints — matching
 * `Family\Overview::remove()`'s established `wire:click` + `wire:confirm` pattern. Each method
 * authorizes against the matching `UserPolicy` ability and delegates entirely to its Action; the
 * Action owns the "already Deleted" guard, so this component never re-implements it.
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

    public function deactivate(User $user, DeactivateUser $deactivateUser): void
    {
        $this->authorize('deactivate', $user);

        $deactivateUser->handle($user);

        session()->flash('status', "{$user->name} has been deactivated.");
    }

    public function reactivate(User $user, ReactivateUser $reactivateUser): void
    {
        $this->authorize('reactivate', $user);

        $reactivateUser->handle($user);

        session()->flash('status', "{$user->name} has been reactivated.");
    }

    public function delete(User $user, AnonymizeUser $anonymizeUser): void
    {
        $this->authorize('delete', $user);

        $anonymizeUser->handle($user, $this->actor());

        session()->flash('status', "{$user->name}'s personal data has been permanently anonymized.");
    }

    protected function actor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
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
                // Wildcards are escaped: an unescaped `%` in the box matched every row, and the
                // composed name is matched as one string so the term a user reads in the Name
                // column ("Ada Lovelace") is the term that finds the row.
                $term = '%'.addcslashes($this->search, '%_\\').'%';

                $q->where(function (Builder $inner) use ($term): void {
                    $inner->whereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$term])
                        ->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('id');
    }
}
