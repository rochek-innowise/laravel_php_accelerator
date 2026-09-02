<?php

declare(strict_types=1);

namespace App\Livewire\Family;

use App\Actions\Approval\RespondToPurchaseApproval;
use App\Enums\ApprovalStatus;
use App\Models\PurchaseApproval;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * FR-010. A guardian sees every guarded child's requests with working Approve/Deny; a child login
 * sees only its own, read-only — the same component, branching on who is looking (FR-011: "the
 * child sees the status transition").
 */
final class PendingApprovals extends Component
{
    public function approve(int $approvalId, RespondToPurchaseApproval $respond): void
    {
        $this->respond($approvalId, ApprovalStatus::Approved, $respond);
    }

    public function deny(int $approvalId, RespondToPurchaseApproval $respond): void
    {
        $this->respond($approvalId, ApprovalStatus::Denied, $respond);
    }

    protected function respond(int $approvalId, ApprovalStatus $decision, RespondToPurchaseApproval $respond): void
    {
        $approval = PurchaseApproval::query()->whereKey($approvalId)->firstOrFail();

        $this->authorize('respond', $approval);

        $respond->handle($approval, $this->actor(), $decision);

        session()->flash('status', 'Response recorded.');
    }

    protected function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return Collection<int, PurchaseApproval> */
    protected function approvals(): Collection
    {
        $actor = $this->actor();

        if ($actor->is_child_account) {
            return $actor->playerProfile?->purchaseApprovals()->with('playerProfile')->latest('requested_at')->get()
                ?? collect();
        }

        // Qualified: player_guardians carries its own `id` column, so an unqualified pluck('id')
        // across the join is ambiguous.
        $childIds = $actor->guardedPlayerProfiles()->pluck('player_profiles.id');

        return PurchaseApproval::query()
            ->whereIn('player_profile_id', $childIds)
            ->with('playerProfile')
            ->latest('requested_at')
            ->get();
    }

    public function render(): View
    {
        $actor = $this->actor();

        return view('livewire.family.pending-approvals', [
            'approvals' => $this->approvals(),
            'canRespond' => ! $actor->is_child_account,
        ]);
    }
}
