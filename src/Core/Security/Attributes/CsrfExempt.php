<?php

declare(strict_types=1);

namespace Phue\Core\Security\Attributes;

use Attribute;

/**
 * Exempts a controller or action from CSRF verification.
 *
 * A marker with no arguments on purpose: it can be read straight off the route handler
 * and instantiated by the container without arguments, the same way NPlusOneGuard is.
 *
 * Reach for it only when a route genuinely cannot carry a token. Anything reachable by a
 * browser with a session cookie should be verified; a bearer-token API endpoint does not
 * need this attribute because the middleware already recognises it as non-ambient.
 *
 * @package Phue\Core\Security\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class CsrfExempt
{
}