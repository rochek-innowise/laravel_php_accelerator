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

    // FR-019 / Slice D Gap 12: business identity, not personal data — meant to render for every
    // authenticated member of the organisation on every page load, unlike the signed-URL profile
    // photo above. Stored on the public disk under `branding/{trainer_profile_id}/`; deployment
    // needs `php artisan storage:link` for this disk's contents to be reachable.
    'trainer_logos' => [
        'disk' => env('TRAINER_LOGO_DISK', 'public'),
        'directory' => 'branding',
        'max_kilobytes' => (int) env('TRAINER_LOGO_MAX_KILOBYTES', 2048),

        // Gap 8: SVG deliberately excluded. FR-019's acceptance text lists PNG/JPG/SVG, but an SVG
        // is a scriptable document — a stored-XSS vector when served back to every member of the
        // organisation. Recorded as an open conflict for the client, not silently resolved.
        'mime_types' => ['image/jpeg', 'image/png'],

        // FR-019 recommends 200x200; doubled for retina, resized to fit within this box while
        // preserving aspect ratio (a logo, not an avatar — no square crop).
        'max_pixels' => (int) env('TRAINER_LOGO_MAX_PIXELS', 400),
    ],
];
