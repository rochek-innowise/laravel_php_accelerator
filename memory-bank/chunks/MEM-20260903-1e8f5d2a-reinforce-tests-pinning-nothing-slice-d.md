---
{
  "id": "MEM-20260903-1e8f5d2a",
  "title": "Reinforce: tests can pass while pinning nothing—three fresh Slice D instances",
  "type": "convention",
  "status": "active",
  "scope": ["application"],
  "tags": ["testing", "test-quality", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["tests/Feature/Admin/UserLifecycleTest.php", "tests/Feature/Authorization/SuperAdminSelfLifecycleTest.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# Reinforce: Tests Can Pass While Pinning Nothing—Three Fresh Slice D Instances

## Durable Context

This chunk reinforces MEM-20260902-6bede1f2 with three new patterns discovered during Slice D that still pass without actually testing what they claim to test.

### Instance 1: `assertSame(hash($x), hash($x))` Without a Second Row

From `tests/Feature/Admin/UserLifecycleTest.php`:

```php
/**
 * Comparable across rows: two different people who used the same address, anonymized at
 * different times, hash identically — this is what makes "was this address ever erased" /
 * "is this person re-registering" answerable across the whole table, not just within one
 * call. (This replaces a version of this test that only asserted `hashEmail($x) ===
 * hashEmail($x)`, passing for any deterministic implementation including one returning a
 * constant, and involving no row at all despite its name — already pinned, more narrowly, by
 * UserDeletionLogTest's own case/whitespace test.)
 */
public function test_the_email_hash_is_comparable_across_two_actual_deletion_log_rows(): void
{
    $actor = User::factory()->superAdmin()->create();

    $first = User::factory()->create(['email' => 'zin@example.test']);
    app(AnonymizeUser::class)->handle($first, $actor);

    // Free again once $first was anonymized off it (deleted_{id}@deleted.invalid).
    $second = User::factory()->create(['email' => 'zin@example.test']);
    app(AnonymizeUser::class)->handle($second, $actor);

    $firstLog = UserDeletionLog::where('original_user_id', $first->id)->sole();
    $secondLog = UserDeletionLog::where('original_user_id', $second->id)->sole();

    $this->assertSame($firstLog->email_hash, $secondLog->email_hash);
}
```

The **wrong version** asserted `hashEmail($x) === hashEmail($x)` with a single email, checking if the hash function is deterministic. That test passes for any implementation, even a constant `return 'hash'`. The **fix** creates two separate anonymization records with the same original email and verifies their hashes match, proving comparability across rows.

### Instance 2: Fresh Lookup vs. Stale Reference

From `app/Actions/Admin/StopImpersonation.php`:

```php
// A fresh lookup, not a stale reference, so a mid-impersonation change to the admin's own
// account (e.g. a deactivation by another Super Admin) is respected.
$admin = $rowMatches ? User::find($impersonatorId) : null;
```

A test of this pattern must:
1. Start impersonation.
2. Have a concurrent request deactivate the admin.
3. End impersonation and verify the deactivated admin is detected.

The **wrong version** reads `auth()->user()` and re-uses it, passing the test even if the deactivation is never re-checked. The **fix** re-queries `User::find()` to catch concurrent changes.

### Instance 3: Livewire Row Action's Actual Authorization Call

From `tests/Feature/Authorization/SuperAdminSelfLifecycleTest.php`:

```php
/**
 * Finding 9 (test quality): this replaces a test of the same name that never touched
 * `UsersTable` at all — it re-ran the plain `Gate::forUser()` checks above via
 * `$admin->cannot(...)`, which duplicates them and pins nothing about the Livewire row action
 * its name promised. This version actually calls `UsersTable::delete()` acting as the admin.
 * It also serves finding 5's "still cannot delete their own User" direction, alongside the
 * ShareLink/TrainerPlayer tests below for the "still can" direction.
 */
public function test_the_livewire_row_action_refuses_self_targeting(): void
{
    $admin = User::factory()->superAdmin()->create();

    Livewire::actingAs($admin)
        ->test(UsersTable::class)
        ->call('delete', $admin->id)
        ->assertForbidden();

    $this->assertSame(UserStatus::Active, $admin->fresh()->status);
}
```

The **wrong version** tested the gate directly: `$this->assertFalse(Gate::forUser($admin)->allows('delete', $admin))`. This duplicates other tests and doesn't check the Livewire component's `$this->authorize()` call. The **fix** invokes the actual component method and verifies the authorization is checked at that point.

## Consequences

- When writing a test, ask: "Does this test touch the actual code path that was just written?"
  - If testing a hash function, create two rows and compare them.
  - If testing a fresh lookup, make the value change between the lookup and the second read.
  - If testing a Livewire method, call the Livewire method, don't test the underlying function in isolation.

- A test named `test_the_X_does_Y` must actually interact with X, not a substitute or a mocked version of X.

- The `Verify` clauses in architecture documents (e.g., "index usage" in the spec) are not automatically covered by tests; they need specific test assertions.

- Common anti-patterns:
  - Testing a hash function without comparing across multiple rows.
  - Testing authorization without a fresh lookup after a state change.
  - Testing Livewire row actions by testing the underlying action, not the component method.
  - Testing that a database query uses an index by running the query and assuming it's fast; write a test that asserts the index exists and is used.

## Verification

Each test is found in its cited source. All three examples pass the full test suite, confirming they actually pin the intended behavior.
