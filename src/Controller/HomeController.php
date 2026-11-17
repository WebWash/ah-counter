<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SpeechStatus;
use App\Report\ReportBuilder;
use App\Repository\SpeechRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    private const int RECENT_SPEECHES = 20;

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(SpeechRepository $speechRepository, ReportBuilder $reportBuilder): Response
    {
        $speeches = $speechRepository->findRecent(self::RECENT_SPEECHES);

        // Reports are shown only for done Speeches (SPEC §6.4), keyed by Speech ID.
        $reports = [];
        foreach ($speeches as $speech) {
            if (SpeechStatus::Done === $speech->getStatus()) {
                $reports[$speech->getId()->toRfc4122()] = $reportBuilder->buildIfAvailable($speech);
            }
        }

        return $this->render('home/index.html.twig', [
            'speeches' => $speeches,
            'reports' => $reports,
        ]);
    }
}
