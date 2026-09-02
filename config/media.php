<?php

declare(strict_types=1);

// Profile photos are private: a child's photo must not sit behind a guessable URL, so the disk is
// non-public and every read goes through a signed route (AD-020).
return [
    'profile_photos' => [
        'disk' => env('PROFILE_PHOTO_DISK', 'local'),
        'directory' => 'profile-photos',
        'max_kilobytes' => (int) env('PROFILE_PHOTO_MAX_KILOBYTES', 5120),
        'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],

        // Square, because every surface renders it as an avatar.
        'thumbnail_pixels' => (int) env('PROFILE_PHOTO_THUMBNAIL_PIXELS', 256),

        // Short-lived: the URL is minted per page render, never stored or emailed.
        'url_ttl_minutes' => (int) env('PROFILE_PHOTO_URL_TTL_MINUTES', 30),
    ],
];
