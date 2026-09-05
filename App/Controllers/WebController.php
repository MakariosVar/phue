<?php

namespace App\Controllers;

use Phue\Core\Routing\Attribute\Route;
use Phue\Core\Request;
use Phue\Core\Response;

#[Route('/')]
class WebController
{
    #[Route('/', methods: ['GET'])]
    public function info(): mixed
    {
        return '
        <div style="height:100vh; width:100vw; display:flex; align-items:center; justify-content:center">
            <img src="/Phue.svg" alt="Phue Logo" />
        <div>
        ';
    }
}