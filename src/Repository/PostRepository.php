<?php

// SPDX-FileCopyrightText: Copyright (c) 2016-2017 Emma <emma1312@protonmail.ch>
//
// SPDX-License-Identifier: Zlib

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contracts\VisibilityInterface;
use App\Entity\HashtagLink;
use App\Entity\Magazine;
use App\Entity\MagazineBlock;
use App\Entity\MagazineSubscription;
use App\Entity\Moderator;
use App\Entity\Post;
use App\Entity\PostFavourite;
use App\Entity\User;
use App\Entity\UserBlock;
use App\Entity\UserFollow;
use App\PageView\EntryPageView;
use App\PageView\PostPageView;
use App\Pagination\AdapterFactory;
use App\Service\SettingsManager;
use App\Utils\SqlHelpers;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Exception\NotValidCurrentPageException;
use Pagerfanta\Pagerfanta;
use Pagerfanta\PagerfantaInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @method Post|null find($id, $lockMode = null, $lockVersion = null)
 * @method Post|null findOneBy(array $criteria, array $orderBy = null)
 * @method Post[]    findAll()
 * @method Post[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PostRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 15;
    public const SORT_DEFAULT = 'hot';

    public function __construct(
        ManagerRegistry $registry,
        private readonly Security $security,
        private readonly CacheInterface $cache,
        private readonly AdapterFactory $adapterFactory,
        private readonly SettingsManager $settingsManager,
        private readonly SqlHelpers $sqlHelpers,
    ) {
        parent::__construct($registry, Post::class);
    }

    public function findByCriteria(PostPageView $criteria): PagerfantaInterface
    {
        $pagerfanta = new Pagerfanta($this->adapterFactory->create($this->getEntryQueryBuilder($criteria)));

        try {
            $pagerfanta->setMaxPerPage($criteria->perPage ?? self::PER_PAGE);
            $pagerfanta->setCurrentPage($criteria->page);
            if (!$criteria->magazine) {
                $pagerfanta->setMaxNbPages(1000);
            }
        } catch (NotValidCurrentPageException $e) {
            throw new NotFoundHttpException();
        }

        return $pagerfanta;
    }

    private function getEntryQueryBuilder(PostPageView $criteria): QueryBuilder
    {
        $user = $this->security->getUser();

        $qb = $this->createQueryBuilder('p')
            ->select('p', 'm', 'u')
            ->where('p.visibility = :visibility')
            ->join('p.magazine', 'm')
            ->join('p.user', 'u')
            ->andWhere('m.visibility = :visible')
            ->andWhere('u.visibility = :visible');

        if ($user && VisibilityInterface::VISIBILITY_VISIBLE === $criteria->visibility) {
            $qb->orWhere(
                'EXISTS (SELECT IDENTITY(puf.following) FROM '.UserFollow::class.' puf WHERE puf.follower = :puf_user AND p.visibility = :puf_visibility AND puf.following = p.user)'
            )
                ->setParameter('puf_user', $user)
                ->setParameter('puf_visibility', VisibilityInterface::VISIBILITY_PRIVATE);
        } else {
            $qb->orWhere('p.user IS NULL');
        }

        $qb->setParameter('visibility', $criteria->visibility)
            ->setParameter('visible', VisibilityInterface::VISIBILITY_VISIBLE);

        $this->addTimeClause($qb, $criteria);
        $this->addStickyClause($qb, $criteria);
        $this->filter($qb, $criteria);
        $this->addBannedHashtagClause($qb);

        return $qb;
    }

    private function addTimeClause(QueryBuilder $qb, Criteria $criteria): void
    {
        if (Criteria::TIME_ALL !== $criteria->time) {
            $since = $criteria->getSince();

            $qb->andWhere('p.createdAt > :time')
                ->setParameter('time', $since, Types::DATETIMETZ_IMMUTABLE);
        }
    }

    private function addStickyClause(QueryBuilder $qb, PostPageView $criteria): void
    {
        if ($criteria->stickiesFirst) {
            if (1 === $criteria->page) {
                $qb->addOrderBy('p.sticky', 'DESC');
            } else {
                $qb->andWhere($qb->expr()->eq('p.sticky', 'false'));
            }
        }
    }

    private function addBannedHashtagClause(QueryBuilder $qb): void
    {
        $dql = $this->getEntityManager()->createQueryBuilder()
            ->select('hl2')
            ->from(HashtagLink::class, 'hl2')
            ->join('hl2.hashtag', 'h2')
            ->where('h2.banned = true')
            ->andWhere('hl2.post = p')
            ->getDQL();
        $qb->andWhere($qb->expr()->not($qb->expr()->exists($dql)));
    }

    private function filter(QueryBuilder $qb, Criteria $criteria): QueryBuilder
    {
        /** @var User|null $user */
        $user = $this->security->getUser();

        if (Criteria::AP_LOCAL === $criteria->federation) {
            $qb->andWhere('p.apId IS NULL');
        }

        if ($criteria->magazine) {
            $qb->andWhere('p.magazine = :magazine')
                ->setParameter('magazine', $criteria->magazine);
        }

        if ($criteria->user) {
            $qb->andWhere('p.user = :user')
                ->setParameter('user', $criteria->user);
        }

        if ($criteria->tag) {
            $qb->andWhere('t.tag = :tag')
                ->join('p.hashtags', 'h')
                ->join('h.hashtag', 't')
                ->setParameter('tag', $criteria->tag);
        }

        if ($criteria->subscribed) {
            $qb->andWhere(
                'EXISTS (SELECT IDENTITY(ms.magazine) FROM '.MagazineSubscription::class.' ms WHERE ms.user = :user AND ms.magazine = p.magazine)
                OR
                EXISTS (SELECT IDENTITY(uf.following) FROM '.UserFollow::class.' uf WHERE uf.follower = :user AND uf.following = p.user)
                OR
                p.user = :user'
            );
            $qb->setParameter('user', $this->security->getUser());
        }

        if ($criteria->moderated) {
            $qb->andWhere(
                'EXISTS (SELECT IDENTITY(mm.magazine) FROM '.Moderator::class.' mm WHERE mm.user = :user AND mm.magazine = p.magazine)'
            );
            $qb->setParameter('user', $this->security->getUser());
        }

        if ($criteria->favourite) {
            $qb->andWhere(
                'EXISTS (SELECT IDENTITY(pf.post) FROM '.PostFavourite::class.' pf WHERE pf.user = :user AND pf.post = p)'
            );
            $qb->setParameter('user', $this->security->getUser());
        }

        if ($criteria->languages) {
            $qb->andWhere('p.lang IN (:languages)')
                ->setParameter('languages', $criteria->languages, ArrayParameterType::STRING);
        }

        if ($user && (!$criteria->magazine || !$criteria->magazine->userIsModerator($user)) && !$criteria->moderated) {
            $qb->andWhere(
                'NOT EXISTS (SELECT IDENTITY(ub.blocked) FROM '.UserBlock::class.' ub WHERE ub.blocker = :blocker AND ub.blocked = p.user)'
            );
            $qb->setParameter('blocker', $user);

            $qb->andWhere(
                'NOT EXISTS (SELECT IDENTITY(mb.magazine) FROM '.MagazineBlock::class.' mb WHERE mb.user = :magazineBlocker AND mb.magazine = p.magazine)'
            );
            $qb->setParameter('magazineBlocker', $user);
        }

        if (!$user || $user->hideAdult) {
            $qb->andWhere('m.isAdult = :isAdult')
                ->andWhere('p.isAdult = :isAdult')
                ->setParameter('isAdult', false);
        }

        switch ($criteria->sortOption) {
            case Criteria::SORT_HOT:
                $qb->addOrderBy('p.ranking', 'DESC');
                break;
            case Criteria::SORT_TOP:
                $qb->addOrderBy('p.score', 'DESC');
                break;
            case Criteria::SORT_COMMENTED:
                $qb->addOrderBy('p.commentCount', 'DESC');
                break;
            case Criteria::SORT_ACTIVE:
                $qb->addOrderBy('p.lastActive', 'DESC');
                break;
            default:
        }

        $qb->addOrderBy('p.createdAt', Criteria::SORT_OLD === $criteria->sortOption ? 'ASC' : 'DESC');
        $qb->addOrderBy('p.id', 'DESC');

        return $qb;
    }

    public function hydrate(Post ...$posts): void
    {
        $this->_em->createQueryBuilder()
            ->select('PARTIAL p.{id}')
            ->addSelect('u')
            ->addSelect('ua')
            ->addSelect('m')
            ->addSelect('i')
            ->from(Post::class, 'p')
            ->join('p.user', 'u')
            ->join('p.magazine', 'm')
            ->leftJoin('u.avatar', 'ua')
            ->leftJoin('p.image', 'i')
            ->where('p IN (?1)')
            ->setParameter(1, $posts)
            ->getQuery()
            ->getResult();

        if ($this->security->getUser()) {
            $this->_em->createQueryBuilder()
                ->select('PARTIAL p.{id}')
                ->addSelect('pv')
                ->addSelect('pf')
                ->from(Post::class, 'p')
                ->leftJoin('p.votes', 'pv')
                ->leftJoin('p.favourites', 'pf')
                ->where('p IN (?1)')
                ->setParameter(1, $posts)
                ->getQuery()
                ->getResult();
        }
    }

    public function countPostsByMagazine(Magazine $magazine)
    {
        return \intval(
            $this->createQueryBuilder('p')
                ->select('count(p.id)')
                ->where('p.magazine = :magazine')
                ->andWhere('p.visibility = :visibility')
                ->setParameter('magazine', $magazine)
                ->setParameter('visibility', VisibilityInterface::VISIBILITY_VISIBLE)
                ->getQuery()
                ->getSingleScalarResult()
        );
    }

    public function countPostCommentsByMagazine(Magazine $magazine): int
    {
        return \intval(
            $this->createQueryBuilder('p')
                ->select('sum(p.commentCount)')
                ->where('p.magazine = :magazine')
                ->setParameter('magazine', $magazine)
                ->getQuery()
                ->getSingleScalarResult()
        );
    }

    public function findToDelete(User $user, int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.visibility != :visibility')
            ->andWhere('p.user = :user')
            ->setParameters(['visibility' => VisibilityInterface::VISIBILITY_SOFT_DELETED, 'user' => $user])
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findRelatedByTag(string $tag, ?int $limit = 1, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('p');

        $qb = $qb
            ->andWhere('p.visibility = :visibility')
            ->andWhere('m.visibility = :visibility')
            ->andWhere('u.visibility = :visibility')
            ->andWhere('m.name != :name')
            ->andWhere('p.isAdult = false')
            ->andWhere('m.isAdult = false')
            ->andWhere('h.tag = :name')
            ->join('p.magazine', 'm')
            ->join('p.user', 'u')
            ->join('p.hashtags', 'hl')
            ->join('hl.hashtag', 'h')
            ->orderBy('p.createdAt', 'DESC')
            ->setParameters([
                'visibility' => VisibilityInterface::VISIBILITY_VISIBLE,
                'name' => $tag,
            ])
            ->setMaxResults($limit);

        if (null !== $user) {
            $qb->andWhere($qb->expr()->not($qb->expr()->exists($this->sqlHelpers->getBlockedMagazinesDql($user))))
                ->andWhere($qb->expr()->not($qb->expr()->exists($this->sqlHelpers->getBlockedUsersDql($user))));
            $qb->setParameter('user', $user);
        }

        return $qb->getQuery()
            ->getResult();
    }

    public function findRelatedByMagazine(string $name, ?int $limit = 1, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('p');

        $qb = $qb->where('m.name LIKE :name OR m.title LIKE :title')
            ->andWhere('p.visibility = :visibility')
            ->andWhere('m.visibility = :visibility')
            ->andWhere('u.visibility = :visibility')
            ->andWhere('p.isAdult = false')
            ->andWhere('m.isAdult = false')
            ->join('p.magazine', 'm')
            ->join('p.user', 'u')
            ->orderBy('p.createdAt', 'DESC')
            ->setParameters(
                ['name' => "%{$name}%", 'title' => "%{$name}%", 'visibility' => VisibilityInterface::VISIBILITY_VISIBLE]
            )
            ->setMaxResults($limit);

        if (null !== $user) {
            $qb->andWhere($qb->expr()->not($qb->expr()->exists($this->sqlHelpers->getBlockedMagazinesDql($user))))
                ->andWhere($qb->expr()->not($qb->expr()->exists($this->sqlHelpers->getBlockedUsersDql($user))));
            $qb->setParameter('user', $user);
        }

        return $qb->getQuery()
            ->getResult();
    }

    public function findLast(int $limit = 1, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('p');

        $qb = $qb->where('p.isAdult = false')
            ->andWhere('p.visibility = :visibility')
            ->andWhere('m.isAdult = false');
        if ($this->settingsManager->get('MBIN_SIDEBAR_SECTIONS_LOCAL_ONLY')) {
            $qb = $qb->andWhere('m.apId IS NULL');
        }

        if (null !== $user) {
            $qb->andWhere($qb->expr()->not($qb->expr()->exists($this->sqlHelpers->getBlockedMagazinesDql($user))))
                ->andWhere($qb->expr()->not($qb->expr()->exists($this->sqlHelpers->getBlockedUsersDql($user))));
            $qb->setParameter('user', $user);
        }

        return $qb->join('p.magazine', 'm')
            ->join('p.user', 'u')
            ->orderBy('p.createdAt', 'DESC')
            ->setParameter('visibility', VisibilityInterface::VISIBILITY_VISIBLE)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findFederated()
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.apId IS NOT NULL')
            ->andWhere('p.visibility = :visibility')
            ->orderBy('p.createdAt', 'DESC')
            ->setParameter('visibility', VisibilityInterface::VISIBILITY_VISIBLE)
            ->getQuery()
            ->getResult();
    }

    public function findTaggedFederatedInRandomMagazine()
    {
        return $this->createQueryBuilder('p')
            ->join('p.magazine', 'm')
            ->andWhere('m.name = :magazine')
            ->andWhere('p.apId IS NOT NULL')
            ->andWhere('p.visibility = :visibility')
            ->orderBy('p.createdAt', 'DESC')
            ->setParameters(
                ['visibility' => VisibilityInterface::VISIBILITY_VISIBLE, 'magazine' => 'random']
            )
            ->getQuery()
            ->getResult();
    }

    public function findUsers(Magazine $magazine, ?bool $federated = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('u.id, COUNT(p.id) as count')
            ->groupBy('u.id')
            ->join('p.user', 'u')
            ->join('p.magazine', 'm')
            ->andWhere('p.magazine = :magazine')
            ->andWhere('p.visibility = :visibility')
            ->andWhere('u.about != :emptyString')
            ->andWhere('u.isBanned = false');

        if ($federated) {
            $qb->andWhere('u.apId IS NOT NULL')
                ->andWhere('u.apDiscoverable = true');
        } else {
            $qb->andWhere('u.apId IS NULL');
        }

        return $qb->orderBy('count', 'DESC')
            ->setParameters(
                ['magazine' => $magazine, 'emptyString' => '', 'visibility' => VisibilityInterface::VISIBILITY_VISIBLE]
            )
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();
    }

    private function countAll(EntryPageView|Criteria $criteria): int
    {
        return $this->cache->get(
            'posts_count_'.$criteria->magazine?->name,
            function (ItemInterface $item) use ($criteria): int {
                $item->expiresAfter(60);

                if (!$criteria->magazine) {
                    $query = $this->_em->createQuery(
                        'SELECT COUNT(p.id) FROM App\Entity\Post p WHERE p.visibility = :visibility'
                    )
                        ->setParameter('visibility', VisibilityInterface::VISIBILITY_VISIBLE);
                } else {
                    $query = $this->_em->createQuery(
                        'SELECT COUNT(p.id) FROM App\Entity\Post p WHERE p.visibility = :visibility AND p.magazine = :magazine'
                    )
                        ->setParameters(
                            ['visibility' => VisibilityInterface::VISIBILITY_VISIBLE, 'magazine' => $criteria->magazine]
                        );
                }

                try {
                    return $query->getSingleScalarResult();
                } catch (NoResultException $e) {
                    return 0;
                }
            }
        );
    }
}
