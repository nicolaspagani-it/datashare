<?php

namespace App\Tests\Entity;

use App\Entity\SharedFile;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de l'entité SharedFile (aucune base de données).
 */
class SharedFileTest extends TestCase
{
    public function testANewFileHasACreationDateAndNoIdYet(): void
    {
        $file = new SharedFile();

        $this->assertNull($file->getId());
        $this->assertEqualsWithDelta(time(), $file->getCreatedAt()->getTimestamp(), 5);
    }

    public function testItKeepsItsInformation(): void
    {
        $owner = (new User())->setEmail('alice@example.com');
        $expires = new \DateTimeImmutable('+3 days');

        $file = (new SharedFile())
            ->setOwner($owner)
            ->setOriginalName('photo.jpg')
            ->setStoredName('abc123')
            ->setMimeType('image/jpeg')
            ->setSize(2_600_000)
            ->setToken('UhGyr4Kd')
            ->setExpiresAt($expires);

        $this->assertSame($owner, $file->getOwner());
        $this->assertSame('photo.jpg', $file->getOriginalName());
        $this->assertSame('abc123', $file->getStoredName());
        $this->assertSame('image/jpeg', $file->getMimeType());
        $this->assertSame(2_600_000, $file->getSize());
        $this->assertSame('UhGyr4Kd', $file->getToken());
        $this->assertSame($expires, $file->getExpiresAt());
    }

    public function testAnonymousFileHasNoOwner(): void
    {
        $this->assertNull((new SharedFile())->getOwner());
    }

    public function testFileIsNotProtectedWithoutPassword(): void
    {
        $this->assertFalse((new SharedFile())->isProtected());
    }

    public function testFileIsProtectedOnceAPasswordHashIsSet(): void
    {
        $file = (new SharedFile())->setPasswordHash('$2y$hash');

        $this->assertTrue($file->isProtected());
        $this->assertSame('$2y$hash', $file->getPasswordHash());
    }

    public function testExpirationIsComparedWithTheGivenDate(): void
    {
        $now = new \DateTimeImmutable('2026-01-10 12:00:00');
        $file = (new SharedFile())->setExpiresAt(new \DateTimeImmutable('2026-01-10 12:00:00'));

        $this->assertFalse($file->isExpired($now->modify('-1 second')));
        $this->assertTrue($file->isExpired($now), 'À la seconde exacte, le fichier est considéré comme expiré.');
        $this->assertTrue($file->isExpired($now->modify('+1 day')));
    }

    public function testExpirationUsesTheCurrentDateByDefault(): void
    {
        $this->assertTrue((new SharedFile())->setExpiresAt(new \DateTimeImmutable('-1 minute'))->isExpired());
        $this->assertFalse((new SharedFile())->setExpiresAt(new \DateTimeImmutable('+1 minute'))->isExpired());
    }

    public function testContentCanBeMarkedAsDeleted(): void
    {
        $file = (new SharedFile())->setStoredName('abc123');

        $file->setStoredName(null);

        $this->assertNull($file->getStoredName());
    }
}
