<?php declare(strict_types=1);

namespace App\Components;

use App\Entity\Magazine;
use App\Repository\MagazineRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Twig\Environment;

#[AsTwigComponent('featured_magazines')]
class FeaturedMagazinesComponent
{
    public ?Magazine $magazine;

    public function __construct(private MagazineRepository $repository, private Environment $twig, private CacheInterface $cache)
    {
    }

    public function getHtml(): string
    {
        $magazines = $this->cache->get('featured_magazines', function (ItemInterface $item) {
            $item->expiresAfter(60);

            $magazines = $this->repository->findBy([], ['lastActive' => 'DESC'], 55);

            if ($this->magazine && !in_array($this->magazine, $magazines)) {
                array_unshift($magazines, $this->magazine);
            }

            usort($magazines, fn($a, $b) => $a->lastActive < $b->lastActive);

            return array_map(fn($mag) => $mag->name, $magazines);
        });

        return $this->twig->render(
            'magazine/_featured.html.twig',
            [
                'magazine' => $this->magazine,
                'magazines' => $magazines,
            ]
        );
    }

}