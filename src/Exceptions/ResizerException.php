<?php
declare(strict_types=1);

namespace Nelson\Resizer\Exceptions;

use Exception;

/**
 * Base class of exceptions caused by an invalid request (params, path, format),
 * the resize presenter turns them into a 4xx response.
 */
abstract class ResizerException extends Exception
{
}
