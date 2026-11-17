<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SpeechRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    private const int RECENT_SPEECHES = 20;

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(SpeechRepository $speechRepository): Response
    {
        return $this->render('home/index.html.twig', [
            'speeches' => $speechRepository->findRecent(self::RECENT_SPEECHES),
        ]);
    }
}
