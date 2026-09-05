<?php

declare(strict_types=1);

namespace App\Controllers;

use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Asset\HtmlShellRenderer;
use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Route;

#[Route('/')]
class WebController
{
    public function __construct(
        private HtmlShellRenderer $renderer,
        private AssetRegistry $registry
    ) {}

    #[Route('/', methods: ['GET'])]
    public function index(): Response
    {
        // Enqueue frontend script as ESM module
        $this->registry->addScript('/assets/js/app.js', isModule: true);

        $initialData = [
            'products' => [
                ['id' => 1, 'name' => 'Widget A', 'price' => 19.99],
                ['id' => 2, 'name' => 'Widget B', 'price' => 29.99],
            ],
        ];

        // Base shell markup
        $html = $this->renderer->render(
            title: 'Schii Demo - Products',
            appId: 'app',
            initialState: $initialData
        );

        // In-DOM template container required by Vue runtime without compiler
        $inDomMarkup = <<<HTML
<div id="app">
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
</div>
HTML;

        $html = str_replace('<div id="app"></div>', $inDomMarkup, $html);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}