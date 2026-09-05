<?php

declare(strict_types=1);

namespace App\Controllers;

use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Route;
use Phue\Core\UI\ComponentRenderer;
use Phue\Core\UI\View;

/**
 * WebController handles primary HTTP routes and orchestrates page rendering.
 *
 * @package App\Controllers
 */
#[Route('/')]
class WebController
{
    /**
     * WebController constructor.
     *
     * @param ComponentRenderer $ui Core UI component rendering engine service.
     */
    public function __construct(
        private ComponentRenderer $ui
    ) {}

    /**
     * Handles the index route GET request and returns the rendered product catalog page.
     *
     * @return Response HTTP Response containing the fully assembled HTML page.
     */
    #[Route('/', methods: ['GET'])]
    public function index(): Response
    {
        $view = new View(
            template: <<<HTML
                <div class="container my-4">
                    <h2>Product Catalog</h2>
                    <form @submit.prevent="addProduct" class="mb-4">
                        <input v-model="newProduct.name" placeholder="Name" required />
                        <input v-model="newProduct.price" type="number" step="0.01" placeholder="Price" required />
                        <button type="submit">Add Product</button>
                    </form>
                    <ul>
                        <li v-for="p in products" :key="p.id">
                            {{ p.name }} - \${{ p.price }}
                        </li>
                    </ul>
                </div>
            HTML,
            state: [
                'products' => [
                    ['id' => 1, 'name' => 'Widget A', 'price' => 19.99],
                    ['id' => 2, 'name' => 'Widget B', 'price' => 29.99],
                ]
            ],
            scripts: ['/assets/app.js']
        );

        return $this->ui->render('Phue App - Products', $view);
    }
}