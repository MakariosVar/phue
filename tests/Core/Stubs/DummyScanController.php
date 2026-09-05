<?php

namespace Phue\Tests\Core\Stubs;

use Phue\Core\Routing\Attribute\Route;

#[Route('/api/v3/scanned')]
class DummyScanController
{
    #[Route('', methods: ['GET'])]
    public function index(): void
    {
    }
}