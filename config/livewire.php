<?php

declare(strict_types=1);

// Livewire 4 defaults to `layouts::app` (resources/views/layouts). Point it at the anonymous
// Blade component instead, so full-page components and <x-layouts.app> share one layout file.
return [
    'component_layout' => 'components.layouts.app',
];
