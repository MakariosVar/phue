<?php

declare(strict_types=1);

namespace App\Controllers;

use Phue\Core\Response;
use Phue\Core\Routing\Attribute\Route;

/**
 * WebController handles primary HTTP routes and orchestrates page rendering.
 *
 * @package App\Controllers
 */
#[Route('/')]
class WebController extends Controller
{
    #[Route('/', methods: ['GET'])]
    public function index(): Response
    {
        return $this->ui->render('Products', [
            'testValue' => 'HomePage'
        ]);
    }

    #[Route('/docs', methods: ['GET'])]
    public function docs(): Response
    {
        return $this->ui->render('Docs', [
            'testValue' => 'DocsPage'
        ]);
    }

    #[Route('/ui', methods: ['GET'])]
    public function ui(): Response
    {
        return $this->ui->render('UI', [
            'testValue' => 'UIPage'
        ]);
    }
}