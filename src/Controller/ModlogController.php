<?php declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\MagazineLogRepository;

class ModlogController extends AbstractController
{
    public function __invoke(MagazineLogRepository $magazineLogRepository, Request $request): Response
    {
        $page = $this->getPageNb($request);

        return $this->render(
            'modlog/modlog.html.twig',
            [
                'logs' => $magazineLogRepository->listAll((int) $request->get('strona', $page)),
            ]
        );
    }
}
