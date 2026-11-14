<?php

namespace App\Tests\Service;

use App\Service\FileStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Tests unitaires du stockage sur disque, dans un dossier temporaire.
 */
class FileStorageTest extends TestCase
{
    private string $directory;
    private FileStorage $storage;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/datashare-storage-'.bin2hex(random_bytes(4));
        $this->storage = new FileStorage($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    private function upload(string $content = 'hello', string $name = 'photo.jpg'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dsst');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/plain', null, true);
    }

    public function testStoreCreatesTheDirectoryAndKeepsTheContent(): void
    {
        $this->assertDirectoryDoesNotExist($this->directory);

        $name = $this->storage->store($this->upload('mon contenu'));

        $this->assertDirectoryExists($this->directory);
        $this->assertTrue($this->storage->exists($name));
        $this->assertSame('mon contenu', file_get_contents($this->storage->path($name)));
    }

    public function testStoredNameIsRandomAndDoesNotRevealTheOriginalName(): void
    {
        $first = $this->storage->store($this->upload('a', 'vacances.jpg'));
        $second = $this->storage->store($this->upload('a', 'vacances.jpg'));

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $first);
        $this->assertStringNotContainsString('vacances', $first);
    }

    public function testRemoveDeletesTheFile(): void
    {
        $name = $this->storage->store($this->upload());

        $this->storage->remove($name);

        $this->assertFalse($this->storage->exists($name));
    }

    public function testRemoveDoesNotFailWhenTheFileIsAlreadyGone(): void
    {
        $this->storage->remove('nexiste-pas');

        $this->assertFalse($this->storage->exists('nexiste-pas'));
    }

    public function testPathNeverLeavesTheStorageDirectory(): void
    {
        $path = $this->storage->path('../../secret.txt');

        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'secret.txt', $path);
    }
}
