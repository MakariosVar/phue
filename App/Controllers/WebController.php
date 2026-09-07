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
        return $this->ui->render('Home', [
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
            'checked'      => true,
            'radioValue'   => 'option1',
            'loading'      => false,
            'currentPage'  => 3,
            'totalPages'   => 10,
            'activeTab'    => 'tab1',
            'modalOpen'    => false,
            'alertDismissed' => false,
            'inputValue'   => 'Sample text',
            'selectValue'  => 'option2',
        ]);
    }
}