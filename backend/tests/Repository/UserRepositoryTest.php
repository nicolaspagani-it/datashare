<?php

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Tests du repository User : le rehachage automatique du mot de passe
 * (Symfony le déclenche à la connexion quand l'algorithme de hachage évolue).
 */
class UserRepositoryTest extends ApiTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = static::getContainer()->get(UserRepository::class);
    }

    public function testUpgradePasswordSavesTheNewHash(): void
    {
        $user = $this->createUser('alice@example.com');

        $this->repository->upgradePassword($user, 'nouveau-hash');

        $this->em->clear(); // on force une relecture depuis la base
        $reloaded = $this->repository->findOneBy(['email' => 'alice@example.com']);
        $this->assertSame('nouveau-hash', $reloaded->getPassword());
    }

    public function testUpgradePasswordRejectsOtherUserTypes(): void
    {
        $this->expectException(UnsupportedUserException::class);

        $this->repository->upgradePassword(new InMemoryUser('bob', 'hash'), 'nouveau-hash');
    }

    public function testFindsAUserByEmail(): void
    {
        $this->createUser('alice@example.com');

        $this->assertInstanceOf(User::class, $this->repository->findOneBy(['email' => 'alice@example.com']));
        $this->assertNull($this->repository->findOneBy(['email' => 'inconnu@example.com']));
    }
}
