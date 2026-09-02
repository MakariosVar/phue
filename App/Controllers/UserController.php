<?php

namespace App\Controllers;

use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Request;
use Phue\Core\Response;

#[Route('/api/users')]
class UserController
{
    #[Route('', methods: 'GET')]
    public function index(): array
    {
        return [
            'status' => 'success',
            'users' => [
                ['id' => 1, 'name' => 'Makarios'],
                ['id' => 2, 'name' => 'Phue Framework']
            ]
        ];
    }

    #[Route('/{id:int}', methods: 'GET')]
    public function show(Request $request, array $params): Response
    {
        return Response::json([
            'user_id' => $params['id'],
            'message' => 'Fetched user successfully!'
        ]);
    }
}
