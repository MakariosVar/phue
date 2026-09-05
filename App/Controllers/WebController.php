<?php

declare(strict_types=1);

namespace App\Controllers;

use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Route;
use Phue\Core\UI\ComponentRenderer;

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
         return $this->ui->render('Products', [
             'testValue' => 'value String'
         ]);
     }
}