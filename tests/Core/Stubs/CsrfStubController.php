<?php

declare(strict_types=1);

namespace Phue\Tests\Core\Stubs;

use Phue\Core\Security\Attributes\CsrfExempt;

/**
 * Controller whose actions differ in CSRF posture, so a test can prove the attribute is
 * read per action rather than per controller.
 */
class CsrfStubController
{
    public function handle(): void
    {
    }

    #[CsrfExempt]
    public function exempt(): void
    {
    }
}