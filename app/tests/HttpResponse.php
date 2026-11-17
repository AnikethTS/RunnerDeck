<?php

declare(strict_types=1);

namespace Tests;

/** Thrown by the respond seam so a test can read what an action answered. */
final class HttpResponse extends \RuntimeException
{
}
