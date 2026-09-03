<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Exceptions\ProfilePhotoException;
use App\Models\PlayerProfile;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

/**
 * FR-016, and (with `$withThumbnail: false`) Slice C's FR-008 child photo — the same disk,
 * validation order and forceFill discipline for both, so there is exactly one place that knows how
 * a profile photo is written.
 *
 * Order matters. The original is written first, the thumbnail (when asked for) derived from it,
 * and only then does the old photo go — so a failure at any step leaves the owner with the photo
 * they already had rather than none, and the half-written new pair is removed on the way out.
 *
 * `$owner` is `User` (thumbnailed) or `PlayerProfile` (Slice C Decision 5: full-size only, no
 * thumbnail pass) — both carry a plain `photo_path` column and nothing else about either model
 * matters here.
 */
final class StoreProfilePhoto
{
    public function __construct(protected ImageManager $imageManager) {}

    public function handle(User|PlayerProfile $owner, TemporaryUploadedFile $upload, bool $withThumbnail = true): string
    {
        $disk = $this->disk();
        $previous = $owner->photo_path;

        $extension = $this->extensionFor($upload->getMimeType());
        $path = config('media.profile_photos.directory').'/'.$this->ownerSegment($owner).'/'.$owner->getKey().'/'.Str::uuid()->toString().'.'.$extension;

        $disk->put($path, $upload->get());

        // The MIME check cannot be the only gate: a file can sniff as an image and still fail to
        // decode. Without this the owner would get a 500 and the disk would keep the upload —
        // exercised either by producing the thumbnail, or (with none to produce) by decoding alone.
        try {
            if ($withThumbnail) {
                $this->writeThumbnail($disk, $path);
            } else {
                $this->imageManager->decodeBinary((string) $disk->get($path));
            }
        } catch (Throwable $e) {
            $disk->delete($withThumbnail ? [$path, User::thumbnailPathFor($path)] : [$path]);

            throw new ProfilePhotoException('The photo could not be processed.', previous: $e);
        }

        $owner->forceFill(['photo_path' => $path])->save();

        if (! empty($previous)) {
            $disk->delete($withThumbnail ? [$previous, User::thumbnailPathFor($previous)] : [$previous]);
        }

        return $path;
    }

    /**
     * Slice D Gap 3: a caller wrapping this in `DB::transaction()` (GDPR erasure, in particular)
     * cannot roll back a filesystem delete — only the DB write. Nulling the column first and
     * deferring the actual disk delete to `DB::afterCommit()` keeps the two in the only order that
     * can't strand a dangling reference: if the transaction rolls back, the callback never runs and
     * the file is untouched; if it commits, the column is already null by the time the file goes.
     * `afterCommit()` runs the callback immediately when no transaction is open, so this is safe to
     * call from a bare (non-transactional) caller like `ProfileForm::removePhoto()` too.
     */
    public function remove(User|PlayerProfile $owner, bool $withThumbnail = true): void
    {
        if (empty($owner->photo_path)) {
            return;
        }

        $path = $owner->photo_path;
        $paths = $withThumbnail ? [$path, User::thumbnailPathFor($path)] : [$path];

        $owner->forceFill(['photo_path' => null])->save();

        DB::afterCommit(fn (): bool => $this->disk()->delete($paths));
    }

    protected function writeThumbnail(Filesystem $disk, string $path): void
    {
        $pixels = (int) config('media.profile_photos.thumbnail_pixels');

        $thumbnail = $this->imageManager
            ->decodeBinary((string) $disk->get($path))
            ->cover($pixels, $pixels)
            ->encodeUsingFileExtension(pathinfo($path, PATHINFO_EXTENSION));

        $disk->put(User::thumbnailPathFor($path), (string) $thumbnail);
    }

    /** The extension is derived from the sniffed MIME type, never from the client's filename. */
    protected function extensionFor(?string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('Unsupported image type ['.($mimeType ?? 'unknown').'].'),
        };
    }

    protected function disk(): Filesystem
    {
        return Storage::disk(config('media.profile_photos.disk'));
    }

    /**
     * `users.id` and `player_profiles.id` are separate sequences, so without this a `User` and a
     * `PlayerProfile` sharing the same id would share the same directory. Harmless today —
     * filenames are UUIDs and every delete names an exact path — but FR-018's GDPR delete (Slice D)
     * is exactly the consumer that would purge a whole owner directory, and it must not be able to
     * take an unrelated owner's photos with it.
     */
    protected function ownerSegment(User|PlayerProfile $owner): string
    {
        return match (true) {
            $owner instanceof User => 'users',
            $owner instanceof PlayerProfile => 'players',
        };
    }
}
