<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AD-020: photos live on a private disk, so every read is served here rather than by the web
 * server. The signature bounds the link's lifetime; the policy decides who may follow it — a
 * valid signature alone must not be enough, or a link shared once would grant access forever.
 */
final class ProfilePhotoController extends Controller
{
    public function __invoke(Request $request, User $user, string $variant = 'thumbnail'): StreamedResponse
    {
        Gate::authorize('view', $user);

        $path = $variant === 'original' ? $user->photo_path : $user->photoThumbnailPath();

        abort_if(empty($path), 404);

        $disk = Storage::disk(config('media.profile_photos.disk'));

        abort_unless($disk->exists($path), 404);

        return $disk->response($path);
    }
}
