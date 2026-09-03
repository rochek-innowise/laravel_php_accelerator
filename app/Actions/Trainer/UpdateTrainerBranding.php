<?php

declare(strict_types=1);

namespace App\Actions\Trainer;

use App\Exceptions\TrainerLogoException;
use App\Models\TrainerProfile;
use App\Services\AuditLogger;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * FR-019: a trainer's logo and primary colour, applied immediately across their organisation's
 * shell (tenant-scoped data on `TrainerProfile`, not a per-user preference).
 *
 * Follows `StoreProfilePhoto`'s established discipline — sniff the MIME type, decode-before-store,
 * delete a partial write on failure, replace the old file only after the new one is good — with
 * two deliberate departures: the resize preserves aspect ratio instead of cropping to a square (a
 * logo, not an avatar), and the file lives on the public disk instead of the private one (Gap 12 —
 * business identity meant to render on every page load, not personal data behind a signed URL).
 *
 * Colour validation itself (`/^#[0-9A-Fa-f]{6}$/`) is a Livewire-side rule, enforced before this
 * action is ever reached — `$primaryColor` arrives here already a valid hex string.
 */
final class UpdateTrainerBranding
{
    public function __construct(protected ImageManager $imageManager, protected AuditLogger $auditLogger) {}

    public function handle(TrainerProfile $trainer, ?TemporaryUploadedFile $logo, string $primaryColor): void
    {
        $disk = $this->disk();
        $previous = $trainer->logo_path;
        $path = $previous;

        if ($logo !== null) {
            $path = $this->storeLogo($disk, $trainer, $logo);
        }

        // Ordinary update(), not forceFill: both columns are already fillable (Slice A) and
        // neither is a privilege/ownership column (AD-016).
        $trainer->update(['logo_path' => $path, 'primary_color' => $primaryColor]);

        if ($logo !== null && ! empty($previous)) {
            $disk->delete([$previous]);
        }

        $this->auditLogger->log('trainer-branding.updated', $trainer, [
            'primary_color' => $primaryColor,
            'logo_replaced' => $logo !== null,
        ]);
    }

    /** "Reset to default" (FR-019): clears the logo and restores the platform default colour. */
    public function reset(TrainerProfile $trainer): void
    {
        $previous = $trainer->logo_path;

        $trainer->update([
            'logo_path' => null,
            'primary_color' => config('branding.default_primary_color'),
        ]);

        if (! empty($previous)) {
            $this->disk()->delete([$previous]);
        }

        $this->auditLogger->log('trainer-branding.reset', $trainer);
    }

    /**
     * The upload is decoded and resized in memory first, and only the finished, re-encoded image
     * ever reaches the disk — a single `put()`, not a raw write followed by an overwrite. The
     * previous shape wrote the unprocessed upload straight to the **public** disk before resizing
     * it in place, leaving a brief window where unvalidated bytes were publicly fetchable at a
     * guessable-once-you-know-the-pattern path. Unexploitable in practice (the extension is
     * derived from the sniffed MIME, so it can only ever be `.jpg`/`.png`, and the UUID segment
     * isn't guessable) but strictly better to close anyway (Gap 14).
     */
    protected function storeLogo(Filesystem $disk, TrainerProfile $trainer, TemporaryUploadedFile $logo): string
    {
        $extension = $this->extensionFor($logo->getMimeType());
        $path = config('media.trainer_logos.directory').'/'.$trainer->getKey().'/'.Str::uuid()->toString().'.'.$extension;

        // The MIME check is not the only gate: a file can sniff as an image and still fail to
        // decode, or resize successfully into something that then fails to encode. Without this
        // the trainer would get a 500 and (in the old shape) the disk would keep the raw upload.
        try {
            $maxPixels = (int) config('media.trainer_logos.max_pixels');

            $resized = $this->imageManager
                ->decodeBinary($logo->get())
                ->scaleDown($maxPixels, $maxPixels)
                ->encodeUsingFileExtension($extension);
        } catch (Throwable $e) {
            throw new TrainerLogoException('The logo could not be processed.', previous: $e);
        }

        $disk->put($path, (string) $resized);

        return $path;
    }

    /**
     * The extension is derived from the sniffed MIME type, never from the client's filename.
     * Throws `TrainerLogoException`, matching every other failure on this path (Gap 14) — a bare
     * `RuntimeException` here would have been a 500 for a MIME the validator's `mimetypes` rule
     * passed but this `match` doesn't name, instead of the field error every other branch gives.
     */
    protected function extensionFor(?string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new TrainerLogoException('Unsupported image type ['.($mimeType ?? 'unknown').'].'),
        };
    }

    protected function disk(): Filesystem
    {
        return Storage::disk(config('media.trainer_logos.disk'));
    }
}
