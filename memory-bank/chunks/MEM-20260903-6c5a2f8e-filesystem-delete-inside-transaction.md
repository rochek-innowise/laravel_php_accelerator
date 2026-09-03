---
{
  "id": "MEM-20260903-6c5a2f8e",
  "title": "A filesystem delete inside a database transaction always resolves the wrong way",
  "type": "constraint",
  "status": "active",
  "scope": ["application"],
  "tags": ["database", "transactions", "file-storage", "gdpr", "slice-d"],
  "created": "2026-09-03",
  "last_verified": "2026-09-03",
  "review_after": "2026-12-03",
  "sources": ["app/Actions/Admin/AnonymizeUser.php"],
  "supersedes": [],
  "superseded_by": null,
  "valid_from": "2026-09-03",
  "valid_to": null,
  "source_digests": []
}
---

# Filesystem Delete Inside a Transaction Always Resolves the Wrong Way

## Durable Context

Deleting a file inside a `DB::transaction()` creates two failure scenarios:

1. **The transaction commits but the file delete fails:** The database row points to a non-existent file. You've deleted the reference but orphaned the data.
2. **The transaction rolls back and the file delete succeeds:** The database reference is restored by the rollback, but the file is gone. You've left a dangling pointer.

Either way, the file and database state diverge irreversibly.

**The solution:** Collect file paths inside the transaction. After the transaction commits (or fails cleanly), perform filesystem operations outside the transaction, using `DB::afterCommit()` if you need to fire cleanup only on success.

## Consequences

- Pattern:

```php
DB::transaction(function () use ($target) {
    // Write to database
    $target->photo_path = null;
    $target->save();
    
    // Collect paths, don't delete
    $photoPaths[] = $target->old_photo_path;
});

// Delete files AFTER the transaction is durable
Storage::delete($photoPaths);
```

Or:

```php
$photoPaths = [];

DB::transaction(function () use ($target, &$photoPaths) {
    // ... write to database ...
    $photoPaths[] = $target->old_photo_path;
    
    // Use DB::afterCommit to schedule deletion only on success
    DB::afterCommit(function () use ($photoPaths) {
        Storage::delete($photoPaths);
    });
});
```

- A dangling database reference (row exists, file is gone) is worse than an orphan file (file exists, row is gone), because the reference can be read and acted upon, leading to failures in unrelated code paths.
- Test: write a unit test that forces the filesystem operation to fail (mock `Storage::delete()` to throw); verify the database is still consistent.

## Verification

From `app/Actions/Admin/AnonymizeUser.php`:

```php
/**
 * ...
 *  4. Remove the stored photo via `StoreProfilePhoto` — reused, not reimplemented. The actual disk
 *     delete is deferred by that action to `DB::afterCommit()` (Gap 3): a filesystem delete can't be
 *     rolled back, so it must never happen before the DB write it depends on is durable.
 *  ...
 */
final class AnonymizeUser
{
    public function handle(User $target, User $actor, ?string $reason = null): void
    {
        // ...
        DB::transaction(function () use ($target, $actor, $reason, $originalEmail): void {
            // ... write UserDeletionLog ...
            // ... write User row scrub ...
            
            // Delete photo using an action that defers the actual file delete
            $this->storeProfilePhoto->remove($target);
            
            // ... more database cleanup ...
        });

        // File deletions have already been queued via DB::afterCommit()
        // at this point; the transaction is durable.
    }
}
```

The `StoreProfilePhoto::remove()` action internally uses `DB::afterCommit()` to defer the actual file delete until the transaction commits. This ensures the database is always the source of truth for what should exist on disk.
