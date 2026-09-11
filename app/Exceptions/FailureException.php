<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class FailureException extends RuntimeException implements VetException {}
