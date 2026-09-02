<?php

declare(strict_types=1);

namespace App\Livewire\Join;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\ShareLink\RedeemShareLink as RedeemAction;
use App\Actions\Trainer\AcceptCoachInvitation;
use App\Enums\ShareLinkType;
use App\Http\Middleware\EnsureTrainerContext;
use App\Models\PlayerProfile;
use App\Models\ShareLink;
use App\Models\User;
use App\Notifications\ChildShareLinkBlocked;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The application's only registration surface (AD-004).
 *
 * Fortify's `/register` is disabled, so there is no route whose job is to refuse anonymous
 * sign-ups — the ShareLink is a *precondition of the form existing*, not a check inside it.
 * Account creation still goes through Fortify's `CreateNewUser`, so password rules stay first-party.
 */
class RedeemShareLink extends Component
{
    public string $code = '';

    public ?ShareLink $link = null;

    /** @var array<int, int> */
    public array $selectedProfileIds = [];

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $phone = '';

    public string $player_name = '';

    public bool $joined = false;

    /** FR-011: a child login is refused with friendly copy, not a bare 403. */
    public bool $blocked = false;

    public function mount(string $code): void
    {
        $this->code = $code;
        $this->link = app(RedeemAction::class)->find($code);

        if ($this->link !== null && Auth::check()) {
            $this->selectedProfileIds = $this->trainableProfiles()
                ->pluck('id')
                ->all();
        }
    }

    /** @return Collection<int, PlayerProfile> */
    public function trainableProfiles(): Collection
    {
        $user = Auth::user();

        return $user instanceof User ? $user->trainableProfiles() : collect();
    }

    public function isRedeemable(): bool
    {
        return $this->link?->isRedeemable() === true;
    }

    /** An existing account simply joins: BR-007 forbids a second account for the same person. */
    public function join(RedeemAction $redeem): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);
        abort_unless($this->isRedeemable(), 410);

        // FR-011: a child account never joins on its own. This used to fall through to the
        // `trainer.associate` gate below and render as a bare 403 — deliberately replaced with
        // friendly copy plus a guardian notification carrying the link, so the child is told what
        // to do next rather than met with a wall.
        if ($user->is_child_account) {
            $this->blockChildJoin($user);

            return;
        }

        // A child with its own login may view its associations but never add one (FR-011). The
        // deny list is the single source; this is the gate reading it, not a second rule.
        $this->authorize('trainer.associate');

        if ($this->link?->type === ShareLinkType::Coach) {
            app(AcceptCoachInvitation::class)->handle($this->code, $user);
            $this->joined = true;

            $this->redirectRoute('dashboard', navigate: true);

            return;
        }

        $associations = $redeem->forPlayer($this->code, $user, array_map('intval', $this->selectedProfileIds));

        if ($associations->isEmpty()) {
            throw ValidationException::withMessages([
                'selectedProfileIds' => 'Choose at least one family member to enrol.',
            ]);
        }

        // The organisation just joined becomes the active context — "redirect to trainer's events".
        session([EnsureTrainerContext::SESSION_KEY => $this->link?->trainer_profile_id]);

        $this->joined = true;

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * No exception, no association row — just the FR-011 refusal plus a notification carrying the
     * link, so a guardian can complete the registration themselves. The refusal message shows every
     * time; the notification is throttled per child login the same way
     * `guardAgainstRegistrationFlooding()` throttles `register()` below, and for the identical
     * reason — `join()` sits behind Livewire's update endpoint, so a route limiter never sees a
     * child repeatedly calling it, and each call would otherwise mail and database-notify every
     * guardian again.
     */
    protected function blockChildJoin(User $user): void
    {
        $this->blocked = true;

        if ($this->tooManyChildJoinAttempts($user)) {
            return;
        }

        $profile = $user->playerProfile;
        $link = $this->link;

        if ($profile !== null && $link !== null) {
            $profile->guardians->each(
                fn (User $guardian) => $guardian->notify(new ChildShareLinkBlocked($link, $profile))
            );
        }
    }

    /**
     * Keyed on the child's own id, not the IP: unlike `guardAgainstRegistrationFlooding()` (which
     * guards an anonymous, pre-account endpoint), the actor here is already an authenticated child
     * login, so there is a real identity to key on rather than a shared address.
     */
    protected function tooManyChildJoinAttempts(User $user): bool
    {
        $key = 'join:child-blocked:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            return true;
        }

        RateLimiter::hit($key, decaySeconds: 300);

        return false;
    }

    /** Guest branch: create the account, its self profile, then associate. */
    public function register(RedeemAction $redeem, CreateNewUser $createUser): void
    {
        abort_if(Auth::check(), 403);
        abort_unless($this->isRedeemable(), 410);

        $this->guardAgainstRegistrationFlooding();

        $isCoachLink = $this->link?->type === ShareLinkType::Coach;

        $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'player_name' => [$isCoachLink ? 'nullable' : 'required', 'string', 'max:255'],
        ]);

        // One transaction for the whole branch. Account creation and redemption used to be
        // separate: a link spent between the check above and the locked re-check left the person
        // with an account they could not use and could not re-register, because /join/{code} is
        // the only registration surface there is (AD-004).
        $user = DB::transaction(function () use ($redeem, $createUser, $isCoachLink): User {
            // Email and password rules come from Fortify's own action, so they cannot drift from
            // the rules the reset and profile screens enforce.
            $user = $createUser->create([
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'email' => Str::lower(trim($this->email)),
                'phone' => $this->phone,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
            ]);

            // A coach link is NOT accepted here. Acceptance takes the BR-006 active slot and
            // consumes a single-use link, and the address on a seconds-old account is self-
            // asserted — so anyone holding a leaked code and knowing the invitee's address could
            // burn the invitation. Verification first, then the coach reopens the link: consistent
            // with Q-01.05a, where verification is required to *act*, not to log in.
            if ($isCoachLink) {
                return $user;
            }

            // BR-022: every Player/Parent account gets a self profile, so "a parent who also
            // trains" is the ordinary case rather than a branch.
            $profile = $user->playerProfile()->create([
                'name' => $this->player_name,
                'is_child' => false,
            ]);

            $redeem->forPlayer($this->code, $user, [(int) $profile->id]);

            return $user;
        });

        // Fires the verification mail. Nothing else in the application dispatches this, and
        // /join/{code} is the only surface that creates accounts (AD-004), so without it no user
        // would ever receive a verification link unless they thought to ask for a resend.
        event(new Registered($user));

        Auth::login($user);

        if ($isCoachLink) {
            session()->flash('status', 'Check your email to confirm your address, then open your invitation link again to join the coaching staff.');

            $this->redirectRoute('verification.notice', navigate: true);

            return;
        }

        session([EnsureTrainerContext::SESSION_KEY => $this->link?->trainer_profile_id]);

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * A player link never expires and has unlimited uses (BR-008), so one leaked code is a standing
     * account-creation endpoint. The route's `throttle:join` bounds code probing; this bounds the
     * writes, because they arrive on Livewire's update endpoint where a route limiter cannot see
     * them.
     *
     * @throws ValidationException
     */
    protected function guardAgainstRegistrationFlooding(): void
    {
        $key = 'join:register:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many accounts created from here. Try again in a few minutes.',
            ]);
        }

        RateLimiter::hit($key, decaySeconds: 300);
    }

    #[Layout('components.layouts.guest')]
    public function render(): View
    {
        return view('livewire.join.redeem-share-link', [
            'trainer' => $this->link?->trainerProfile()->first(),
            'profiles' => Auth::check() ? $this->trainableProfiles() : collect(),
        ]);
    }
}
