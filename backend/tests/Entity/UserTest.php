<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de l'entité User (aucune base de données, aucun kernel).
 */
class UserTest extends TestCase
{
    public function testIdentifierIsTheEmail(): void
    {
        $user = (new User())->setEmail('alice@example.com');

        $this->assertSame('alice@example.com', $user->getEmail());
        $this->assertSame('alice@example.com', $user->getUserIdentifier());
    }

    public function testNewUserHasNoIdYet(): void
    {
        $this->assertNull((new User())->getId());
    }

    public function testEveryUserAlwaysHasRoleUser(): void
    {
        $this->assertSame(['ROLE_USER'], (new User())->getRoles());
    }

    public function testRolesAreMergedWithRoleUserWithoutDuplicates(): void
    {
        $user = (new User())->setRoles(['ROLE_ADMIN', 'ROLE_USER']);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_USER', $roles);
        $this->assertCount(2, $roles);
    }

    public function testPasswordIsStoredAsGiven(): void
    {
        $user = (new User())->setPassword('hashed-value');

        $this->assertSame('hashed-value', $user->getPassword());
    }

    public function testSerializationDoesNotLeakThePasswordHash(): void
    {
        $user = (new User())->setEmail('alice@example.com')->setPassword('secret-hash');

        $serialized = serialize($user);

        // Le hash n'est jamais écrit tel quel dans la session : seulement son empreinte crc32c.
        $this->assertStringNotContainsString('secret-hash', $serialized);
        $this->assertStringContainsString(hash('crc32c', 'secret-hash'), $serialized);
    }
}
