<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a magazine cannot be created because the submitted magazine `name`
 * does not satisfy Mbin's name-format rule (RegPatterns::MAGAZINE_NAME).
 *
 * The exception message is a user-facing, actionable explanation (optionally
 * including a suggested valid identifier derived from the submitted value).
 * It is meant to be surfaced directly to API clients.
 */
final class MagazineNameInvalidException extends \Exception
{
}
