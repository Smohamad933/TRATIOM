<?php

declare(strict_types=1);

namespace Terrarium\Application\Exceptions;

use RuntimeException;

/** Requested resource does not exist (mapped to HTTP 404). */
final class NotFoundException extends RuntimeException {}
