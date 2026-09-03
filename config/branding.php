<?php

declare(strict_types=1);

// FR-019 / Slice D: the platform default a trainer's shell falls back to before any branding is
// set, and what "reset to default" restores. Deliberately not under config/media.php — this is a
// colour value, not a file-storage concern, and the two config blocks answer different questions.
return [
    'default_primary_color' => env('BRAND_DEFAULT_PRIMARY_COLOR', '#0EA5E9'),
];
