<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Exceptions\ProfilePhotoException;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

/**
 * FR-016: stores an already-validated upload and its thumbnail, then drops the previous pair.
 *
 * Order matters. The original is written first, the thumbnail derived from it, and only then does
 * the old photo go — so a failure at any step leaves the user with the photo they already had
 * rather than none, and the half-written new pair is removed on the way out.
 */
final class StoreProfilePhoto
{
    public function __construct(protected ImageManager $imageManager) {}

    public function handle(User $user, TemporaryUploadedFile $upload): string
    {
        $disk = $this->disk();
        $previous = $user->photo_path;

        $extension = $this->extensionFor($upload->getMimeType());
        $path = config('media.profile_photos.directory').'/'.$user->getKey().'/'.Str::uuid()->toString().'.'.$extension;

        $disk->put($path, $upload->get());

        // The MIME check cannot be the only gate: a file can sniff as an image and still fail to
        // decode. Without this the user would get a 500 and the disk would keep the upload.
        try {
            $this->writeThumbnail($disk, $path);
        } catch (Throwable $e) {
            $disk->delete([$path, User::thumbnailPathFor($path)]);

            throw new ProfilePhotoException('The photo could not be processed.', previous: $e);
        }

        $user->forceFill(['photo_path' => $path])->save();

        if (! empty($previous)) {
            $disk->delete([$previous, User::thumbnailPathFor($previous)]);
        }

        return $path;
    }

    public function remove(User $user): void
    {
        if (empty($user->photo_path)) {
            return;
        }

        $this->disk()->delete([$user->photo_path, User::thumbnailPathFor($user->photo_path)]);

        $user->forceFill(['photo_path' => null])->save();
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
}
