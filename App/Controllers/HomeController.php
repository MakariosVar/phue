<?php

namespace App\Controllers;

use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Request;
use Phue\Core\Response;

#[Route('/api')]
class HomeController
{
    #[Route('/info', methods: 'GET')]
    public function info(): array
    {
        return [
            'status' => 'success',
            'framework' => 'Phue',
            'version' => 'dev-master',
        ];
    }

    #[Route('/products', methods: 'GET')]
    public function index(): Response
    {
        return Response::json([
            'message' => 'Fetched products successfully!',
        ]);
    }
}
