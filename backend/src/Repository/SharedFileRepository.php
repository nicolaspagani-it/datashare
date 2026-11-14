<?php

namespace App\Repository;

use App\Entity\SharedFile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SharedFile>
 */
class SharedFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedFile::class);
    }

    public function findOneByToken(string $token): ?SharedFile
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Les fichiers d'un utilisateur, du plus récent au plus ancien.
     *
     * @return list<SharedFile>
     */
    public function findByOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Les fichiers expirés dont le contenu est encore sur le disque (à supprimer).
     *
     * @return list<SharedFile>
     */
    public function findExpiredWithContent(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.expiresAt <= :now')
            ->andWhere('f.storedName IS NOT NULL')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
