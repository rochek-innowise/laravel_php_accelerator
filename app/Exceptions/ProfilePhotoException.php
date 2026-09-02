<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The upload survived validation but could not be turned into an image. */
final class ProfilePhotoException extends RuntimeException {}
