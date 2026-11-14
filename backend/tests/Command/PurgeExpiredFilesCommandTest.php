<?php

namespace App\Tests\Command;

use App\Entity\SharedFile;
use App\Repository\SharedFileRepository;
use App\Service\FileStorage;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests de la commande qui supprime du disque les fichiers expirés.
 */
class PurgeExpiredFilesCommandTest extends ApiTestCase
{
    private function storage(): FileStorage
    {
        return static::getContainer()->get(FileStorage::class);
    }

    private function createFile(string $name, string $expires, bool $withContent = true): SharedFile
    {
        $file = (new SharedFile())
            ->setOriginalName($name)
            ->setMimeType('text/plain')
            ->setSize(5)
            ->setToken(substr(md5($name), 0, 8))
            ->setExpiresAt(new \DateTimeImmutable($expires));
        if ($withContent) {
            $file->setStoredName($this->storage()->store($this->makeUploadedFile('contenu', $name)));
        }
        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }

    private function purge(): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:files:purge-expired'));
        $tester->execute([]);

        return $tester;
    }

    public function testExpiredFileContentIsRemovedFromDiskButTheRowIsKept(): void
    {
        $expired = $this->createFile('vieux.txt', '-1 day');
        $path = $this->storage()->path($expired->getStoredName());
        $this->assertFileExists($path);

        $tester = $this->purge();

        $tester->assertCommandIsSuccessful();
        $this->assertFileDoesNotExist($path);
        $this->em->clear();
        $reloaded = static::getContainer()->get(SharedFileRepository::class)->findOneBy(['originalName' => 'vieux.txt']);
        $this->assertNotNull($reloaded, 'La ligne reste en base : l\'utilisateur voit "Expiré" dans sa liste.');
        $this->assertNull($reloaded->getStoredName());
        $this->assertTrue($reloaded->isExpired());
    }

    public function testFilesThatHaveNotExpiredAreLeftAlone(): void
    {
        $valid = $this->createFile('valide.txt', '+2 days');
        $path = $this->storage()->path($valid->getStoredName());

        $this->purge();

        $this->assertFileExists($path);
        $this->em->clear();
        $this->assertNotNull(static::getContainer()->get(SharedFileRepository::class)->findOneBy(['originalName' => 'valide.txt'])->getStoredName());
    }

    public function testOnlyExpiredFilesAreRemovedWhenBothExist(): void
    {
        // On note les chemins AVANT : la commande remet le nom stocké à null sur l'entité.
        $expiredPath = $this->storage()->path($this->createFile('vieux.txt', '-1 hour')->getStoredName());
        $validPath = $this->storage()->path($this->createFile('valide.txt', '+1 hour')->getStoredName());

        $this->purge();

        $this->assertFileDoesNotExist($expiredPath);
        $this->assertFileExists($validPath);
    }

    public function testItReportsHowManyFilesWereRemoved(): void
    {
        $this->createFile('a.txt', '-1 day');
        $this->createFile('b.txt', '-2 days');
        $this->createFile('c.txt', '+1 day');

        $this->assertStringContainsString('2 fichier(s) expiré(s)', $this->purge()->getDisplay());
    }

    public function testRunningItTwiceIsHarmless(): void
    {
        $this->createFile('vieux.txt', '-1 day');
        $this->purge();

        $second = $this->purge();

        $second->assertCommandIsSuccessful();
        $this->assertStringContainsString('0 fichier(s) expiré(s)', $second->getDisplay());
    }

    public function testExpiredFilesAlreadyPurgedAreIgnored(): void
    {
        $this->createFile('deja-purge.txt', '-1 day', withContent: false);

        $tester = $this->purge();

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('0 fichier(s) expiré(s)', $tester->getDisplay());
    }

    public function testItWorksWhenThereIsNothingToDo(): void
    {
        $tester = $this->purge();

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('0 fichier(s) expiré(s)', $tester->getDisplay());
    }
}
