<?php

declare(strict_types=1);

namespace App\Controllers;

use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Route;

/**
 * ProductsController handles product catalog routes.
 *
 * @package App\Controllers
 */
#[Route('/products')]
class ProductsController extends Controller
{
    /**
     * Handles the index route GET request and returns the rendered product catalog page.
     *
     * @return Response HTTP Response containing the fully assembled HTML page.
     */
    #[Route('/', methods: ['GET'])]
    public function index(): Response
    {
        return $this->ui->render('Products', [
            'testValue' => 'value String'
        ]);
    }
}