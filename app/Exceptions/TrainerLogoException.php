<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The upload survived validation but could not be turned into an image (mirrors ProfilePhotoException). */
final class TrainerLogoException extends RuntimeException {}
