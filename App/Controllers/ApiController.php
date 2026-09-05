<?php

namespace App\Controllers;

use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Request;
use Phue\Core\Response;

#[Route('/api')]
class ApiController
{
    #[Route('/info', methods: ['GET'])]
    public function info(): array
    {
        return [
            'status' => 'success',
            'framework' => 'Phue',
            'version' => 'dev-master',
        ];
    }

    #[Route('/products', methods: ['GET'])]
    public function products(): array
    {
        return [
            'message' => 'Fetched products successfully!',
        ];
    }
}
