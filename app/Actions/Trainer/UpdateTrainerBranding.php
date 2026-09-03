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
use RuntimeException;
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

    protected function storeLogo(Filesystem $disk, TrainerProfile $trainer, TemporaryUploadedFile $logo): string
    {
        $extension = $this->extensionFor($logo->getMimeType());
        $path = config('media.trainer_logos.directory').'/'.$trainer->getKey().'/'.Str::uuid()->toString().'.'.$extension;

        $disk->put($path, $logo->get());

        // The MIME check is not the only gate: a file can sniff as an image and still fail to
        // decode, or resize successfully into something that then fails to encode. Without this
        // the trainer would get a 500 and the disk would keep the upload.
        try {
            $maxPixels = (int) config('media.trainer_logos.max_pixels');

            $resized = $this->imageManager
                ->decodeBinary((string) $disk->get($path))
                ->scaleDown($maxPixels, $maxPixels)
                ->encodeUsingFileExtension($extension);

            $disk->put($path, (string) $resized);
        } catch (Throwable $e) {
            $disk->delete([$path]);

            throw new TrainerLogoException('The logo could not be processed.', previous: $e);
        }

        return $path;
    }

    /** The extension is derived from the sniffed MIME type, never from the client's filename. */
    protected function extensionFor(?string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new RuntimeException('Unsupported image type ['.($mimeType ?? 'unknown').'].'),
        };
    }

    protected function disk(): Filesystem
    {
        return Storage::disk(config('media.trainer_logos.disk'));
    }
}
