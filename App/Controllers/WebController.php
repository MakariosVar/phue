<?php

declare(strict_types=1);

namespace App\Controllers;

use Phue\Core\Request;
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
        return $this->ui->render('Pages/Home', [
            'testValue' => 'HomePage'
        ]);
    }

    #[Route('/docs', methods: ['GET'])]
    public function docs(Request $request): Response
    {
        return $this->ui->render('Pages/Docs', [
            'doc' => (string) $request->getQuery('doc', 'getting-started'),
        ]);
    }
    
    #[Route('/phui', methods: ['GET'])]
    public function ui(Request $request): Response
    {
        $section = (string) $request->getQuery('section', 'index');
        return $this->ui->render('Pages/PhUI', ['section' => $section]);
    }

    #[Route('/ui/showcase', methods: ['GET'])]
    public function uiShowcase(): Response
    {
        return $this->ui->render('Pages/PhUIShowcase', []);
    }
}