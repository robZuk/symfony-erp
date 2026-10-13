<?php

namespace App\Identity\Repository;

use App\Identity\Entity\User;
use App\Shared\Pagination\Page;
use App\Shared\Pagination\Paginator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function save(User $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Called by the security system when a password should be rehashed with a newer algorithm.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->save($user);
    }

    /**
     * @param string $status "active", "blocked" or "all"
     *
     * @return Page<User>
     */
    public function search(string $term, string $status, int $page): Page
    {
        $queryBuilder = $this->createQueryBuilder('u')->orderBy('u.fullName', 'ASC')->addOrderBy('u.id', 'ASC');

        if ('' !== trim($term)) {
            $queryBuilder
                ->andWhere('LOWER(u.fullName) LIKE :term OR LOWER(u.email) LIKE :term')
                ->setParameter('term', Paginator::containsPattern($term));
        }

        if ('all' !== $status) {
            $queryBuilder->andWhere('u.active = :active')->setParameter('active', 'active' === $status);
        }

        /** @var Page<User> $result */
        $result = Paginator::paginate($queryBuilder, $page);

        return $result;
    }
}
