<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController
{
    #[Route('/', methods: ['GET'])]
    public function index(): Response
    {
        return new Response(file_get_contents(__DIR__.'/../../templates/catalog.html'));
    }
}
