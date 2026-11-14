<?php

namespace App\Tests\Controller\Api;

use App\Entity\SharedFile;
use App\Repository\SharedFileRepository;
use App\Service\FileStorage;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Tests fonctionnels de l'API des fichiers : envoi, liste et suppression.
 * (En test, la taille maximale est de 1 000 octets : voir config/services.yaml.)
 */
class FileControllerTest extends ApiTestCase
{
    private const TOO_BIG = 'La taille des fichiers est limitée à 1 Go';

    private function files(): SharedFileRepository
    {
        return static::getContainer()->get(SharedFileRepository::class);
    }

    private function storage(): FileStorage
    {
        return static::getContainer()->get(FileStorage::class);
    }

    /** Crée directement un fichier en base (et sur le disque), sans passer par l'API. */
    private function createSharedFile(?\App\Entity\User $owner, string $name = 'doc.pdf', string $expires = '+2 days', bool $withContent = true): SharedFile
    {
        $file = (new SharedFile())
            ->setOwner($owner)
            ->setOriginalName($name)
            ->setMimeType('application/pdf')
            ->setSize(12)
            ->setToken(substr(md5($name.$expires.random_int(0, PHP_INT_MAX)), 0, 8))
            ->setExpiresAt(new \DateTimeImmutable($expires));

        if ($withContent) {
            $stored = $this->storage()->store($this->makeUploadedFile('contenu stocké'));
            $file->setStoredName($stored);
        }
        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }

    // ------------------------------------------------------------------ POST /api/files : succès

    public function testAnonymousVisitorCanUploadAFile(): void
    {
        $data = $this->upload(['expiration' => '1'], $this->makeUploadedFile('contenu', 'photo.jpg'));

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('photo.jpg', $data['name']);
        $this->assertSame(strlen('contenu'), $data['size']);
        $this->assertFalse($data['protected']);
        $this->assertFalse($data['expired']);
        $this->assertIsInt($data['id']);
    }

    public function testResponseContainsTheShareLink(): void
    {
        $data = $this->upload(['expiration' => '1'], $this->makeUploadedFile());

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $data['token']);
        $this->assertSame('http://localhost:5173/'.$data['token'], $data['url']);
    }

    public function testResponseNeverExposesInternalOrSecretFields(): void
    {
        $data = $this->upload(['expiration' => '1', 'password' => 'secret'], $this->makeUploadedFile());

        foreach (['storedName', 'stored_name', 'passwordHash', 'password_hash', 'password'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    public function testTheFileIsStoredOnDiskUnderARandomName(): void
    {
        $this->upload(['expiration' => '1'], $this->makeUploadedFile('mon contenu', 'vacances.jpg'));

        $shared = $this->files()->findOneBy([]);
        $this->assertNotNull($shared->getStoredName());
        $this->assertStringNotContainsString('vacances', $shared->getStoredName());
        $this->assertSame('mon contenu', file_get_contents($this->storage()->path($shared->getStoredName())));
    }

    public function testRowIsSavedWithItsInformation(): void
    {
        $this->upload(['expiration' => '1'], $this->makeUploadedFile('abc', 'photo.jpg', 'image/jpeg'));

        $shared = $this->files()->findOneBy([]);
        $this->assertSame('photo.jpg', $shared->getOriginalName());
        $this->assertSame(3, $shared->getSize());
        $this->assertNull($shared->getOwner(), 'Un envoi anonyme n\'a pas de propriétaire.');
        $this->assertSame(1, $this->files()->count([]));
    }

    public function testMimeTypeIsDetectedFromTheContentNotTheClaim(): void
    {
        // Le navigateur prétend "image/png", mais le contenu est du texte brut.
        $this->upload(['expiration' => '1'], $this->makeUploadedFile('juste du texte', 'faux.png', 'image/png'));

        $this->assertSame('text/plain', $this->files()->findOneBy([])->getMimeType());
    }

    // ------------------------------------------------------------------ durées de conservation

    /** @return iterable<string, array{string, int}> */
    public static function durations(): iterable
    {
        yield 'une journée' => ['1', 1];
        yield '2 jours' => ['2', 2];
        yield '3 jours' => ['3', 3];
        yield '4 jours' => ['4', 4];
        yield '5 jours' => ['5', 5];
        yield '6 jours' => ['6', 6];
        yield 'une semaine' => ['7', 7];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('durations')]
    public function testExpirationDateMatchesTheChosenDuration(string $expiration, int $days): void
    {
        $data = $this->upload(['expiration' => $expiration], $this->makeUploadedFile());

        $this->assertResponseStatusCodeSame(201);
        $created = new \DateTimeImmutable($data['createdAt']);
        $expires = new \DateTimeImmutable($data['expiresAt']);
        $this->assertSame($days, $created->diff($expires)->days);
    }

    // ------------------------------------------------------------------ mot de passe optionnel

    public function testPasswordIsHashedInDatabase(): void
    {
        $data = $this->upload(['expiration' => '1', 'password' => 'mon-secret'], $this->makeUploadedFile());

        $this->assertTrue($data['protected']);
        $shared = $this->files()->findOneBy([]);
        $this->assertNotNull($shared->getPasswordHash());
        $this->assertNotSame('mon-secret', $shared->getPasswordHash());
        $hasher = static::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(SharedFile::class);
        $this->assertTrue($hasher->verify($shared->getPasswordHash(), 'mon-secret'));
        $this->assertFalse($hasher->verify($shared->getPasswordHash(), 'autre-chose'));
    }

    public function testPlainPasswordIsNeverWrittenInTheDatabase(): void
    {
        $this->upload(['expiration' => '1', 'password' => 'mon-secret-unique'], $this->makeUploadedFile());

        $found = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM shared_file WHERE password_hash LIKE ?',
            ['%mon-secret-unique%'],
        );
        $this->assertSame(0, (int) $found);
    }

    public function testNoPasswordMeansAnUnprotectedFile(): void
    {
        $this->upload(['expiration' => '1'], $this->makeUploadedFile());

        $this->assertNull($this->files()->findOneBy([])->getPasswordHash());
    }

    public function testEmptyPasswordMeansAnUnprotectedFile(): void
    {
        $data = $this->upload(['expiration' => '1', 'password' => ''], $this->makeUploadedFile());

        $this->assertFalse($data['protected']);
        $this->assertNull($this->files()->findOneBy([])->getPasswordHash());
    }

    // ------------------------------------------------------------------ propriétaire et authentification

    public function testUploadWhileLoggedInSetsTheOwner(): void
    {
        $user = $this->createUser('alice@example.com');

        $this->upload(['expiration' => '1'], $this->makeUploadedFile(), $this->login());

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame($user->getId(), $this->files()->findOneBy([])->getOwner()?->getId());
    }

    public function testUploadWithAnInvalidTokenIsRefused(): void
    {
        $this->upload(['expiration' => '1'], $this->makeUploadedFile(), 'ceci.nest.pas-un-jwt');

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(0, $this->files()->count([]));
    }

    // ------------------------------------------------------------------ POST /api/files : erreurs

    public function testMissingFileIsRejected(): void
    {
        $data = $this->upload(['expiration' => '1']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('file', $data['violations'][0]['propertyPath']);
        $this->assertSame("Aucun fichier n'a été envoyé.", $data['violations'][0]['title']);
    }

    public function testRequestBodyDroppedByPhpIsReportedAsTooBig(): void
    {
        // Quand le corps dépasse post_max_size, PHP vide $_POST et $_FILES : seul CONTENT_LENGTH reste.
        $this->client->request('POST', '/api/files', [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => 5_000_000_000]);
        $data = $this->decodedResponse();

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(self::TOO_BIG, $data['violations'][0]['title']);
    }

    public function testFileOverTheLimitIsRejected(): void
    {
        $data = $this->upload(['expiration' => '1'], $this->makeUploadedFile(str_repeat('a', 1001)));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('file', $data['violations'][0]['propertyPath']);
        $this->assertSame(self::TOO_BIG, $data['violations'][0]['title']);
    }

    public function testFileExactlyAtTheLimitIsAccepted(): void
    {
        $this->upload(['expiration' => '1'], $this->makeUploadedFile(str_repeat('a', 1000)));

        $this->assertResponseStatusCodeSame(201);
    }

    public function testRejectedFileIsNeitherSavedNorStored(): void
    {
        $this->upload(['expiration' => '1'], $this->makeUploadedFile(str_repeat('a', 1001)));

        $this->assertSame(0, $this->files()->count([]));
        $this->assertDirectoryDoesNotExist(static::getContainer()->getParameter('app.upload_dir'));
    }

    public function testFileRefusedByPhpForItsSizeIsReportedAsTooBig(): void
    {
        $data = $this->upload(['expiration' => '1'], $this->makeUploadedFile('', 'gros.bin', 'application/octet-stream', \UPLOAD_ERR_INI_SIZE));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(self::TOO_BIG, $data['violations'][0]['title']);
    }

    public function testOtherUploadErrorsGetAGenericMessage(): void
    {
        $data = $this->upload(['expiration' => '1'], $this->makeUploadedFile('', 'x.bin', 'application/octet-stream', \UPLOAD_ERR_PARTIAL));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame("Le fichier n'a pas pu être envoyé. Réessaie.", $data['violations'][0]['title']);
    }

    public function testEmptyFileIsRejected(): void
    {
        $data = $this->upload(['expiration' => '1'], $this->makeUploadedFile(''));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('Le fichier est vide.', $data['violations'][0]['title']);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function invalidFields(): iterable
    {
        yield 'durée absente' => [[]];
        yield 'durée à zéro' => [['expiration' => '0']];
        yield 'durée de 8 jours' => [['expiration' => '8']];
        yield 'durée en texte' => [['expiration' => 'abc']];
    }

    /** @param array<string, string> $fields */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFields')]
    public function testInvalidExpirationIsRejectedOnItsField(array $fields): void
    {
        $data = $this->upload($fields, $this->makeUploadedFile());

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('expiration', $data['violations'][0]['propertyPath']);
        $this->assertSame(0, $this->files()->count([]));
    }

    public function testEachErrorIsReportedOnItsOwnField(): void
    {
        $data = $this->upload(['expiration' => '99']);

        $this->assertResponseStatusCodeSame(422);
        $fields = array_column($data['violations'], 'propertyPath');
        sort($fields);
        $this->assertSame(['expiration', 'file'], $fields);
    }

    public function testNoErrorMessageUsesTheVagueWordsThisValueOrThisString(): void
    {
        $data = $this->upload(['expiration' => '99', 'password' => str_repeat('a', 5000)]);

        $this->assertNotEmpty($data['violations']);
        foreach ($data['violations'] as $violation) {
            $this->assertStringNotContainsStringIgnoringCase('cette valeur', $violation['title']);
            $this->assertStringNotContainsStringIgnoringCase('cette chaîne', $violation['title']);
        }
    }

    // ------------------------------------------------------------------ nom du fichier

    public function testPathInTheClientFileNameIsRemoved(): void
    {
        $this->upload(['expiration' => '1'], $this->makeUploadedFile('abc', '../../evil.jpg'));

        $this->assertSame('evil.jpg', $this->files()->findOneBy([])->getOriginalName());
    }

    public function testVeryLongFileNameIsTruncatedToFitTheDatabase(): void
    {
        $this->upload(['expiration' => '1'], $this->makeUploadedFile('abc', str_repeat('a', 300).'.jpg'));

        $this->assertResponseStatusCodeSame(201);
        $this->assertLessThanOrEqual(255, mb_strlen($this->files()->findOneBy([])->getOriginalName()));
    }

    public function testTwoUploadsGetDifferentLinks(): void
    {
        $first = $this->upload(['expiration' => '1'], $this->makeUploadedFile());
        $second = $this->upload(['expiration' => '1'], $this->makeUploadedFile());

        $this->assertNotSame($first['token'], $second['token']);
        $this->assertNotSame($first['url'], $second['url']);
    }

    // ------------------------------------------------------------------ GET /api/files

    public function testListRequiresAToken(): void
    {
        $this->json('GET', '/api/files');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testListIsEmptyForANewUser(): void
    {
        $this->createUser();

        $data = $this->json('GET', '/api/files', token: $this->login());

        $this->assertResponseIsSuccessful();
        $this->assertSame([], $data);
    }

    public function testListOnlyContainsMyFiles(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->createSharedFile($alice, 'a.pdf');
        $this->createSharedFile($bob, 'b.pdf');
        $this->createSharedFile(null, 'anonyme.pdf');

        $data = $this->json('GET', '/api/files', token: $this->login('alice@example.com'));

        $this->assertSame(['a.pdf'], array_column($data, 'name'));
    }

    public function testListShowsTheNewestFirstAndFlagsExpiredFiles(): void
    {
        $alice = $this->createUser();
        $old = $this->createSharedFile($alice, 'ancien.pdf', '-1 day', withContent: false);
        $recent = $this->createSharedFile($alice, 'recent.pdf');
        // createdAt est posé à la création : on force l'ordre pour que le test soit déterministe.
        $this->em->getConnection()->executeStatement("UPDATE shared_file SET created_at = created_at - INTERVAL '1 hour' WHERE id = ?", [$old->getId()]);

        $data = $this->json('GET', '/api/files', token: $this->login());

        $this->assertSame(['recent.pdf', 'ancien.pdf'], array_column($data, 'name'));
        $this->assertSame([false, true], array_column($data, 'expired'));
        $this->assertSame($recent->getId(), $data[0]['id']);
    }

    public function testListEntriesCarryTheShareLinkAndTheProtectedFlag(): void
    {
        $alice = $this->createUser();
        $this->upload(['expiration' => '3', 'password' => 'secret'], $this->makeUploadedFile('x', 'photo.jpg'), $this->login());

        $data = $this->json('GET', '/api/files', token: $this->login());

        $this->assertTrue($data[0]['protected']);
        $this->assertSame('http://localhost:5173/'.$data[0]['token'], $data[0]['url']);
        $this->assertArrayNotHasKey('passwordHash', $data[0]);
        $this->assertSame($alice->getId(), $this->files()->findOneBy([])->getOwner()->getId());
    }

    // ------------------------------------------------------------------ DELETE /api/files/{id}

    public function testOwnerCanDeleteTheirFile(): void
    {
        $alice = $this->createUser();
        $file = $this->createSharedFile($alice);
        $path = $this->storage()->path($file->getStoredName());
        $this->assertFileExists($path);

        $this->json('DELETE', '/api/files/'.$file->getId(), token: $this->login());

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(0, $this->files()->count([]));
        $this->assertFileDoesNotExist($path);
    }

    public function testOwnerCanDeleteAnExpiredFileWhoseContentIsAlreadyGone(): void
    {
        $alice = $this->createUser();
        $file = $this->createSharedFile($alice, expires: '-1 day', withContent: false);

        $this->json('DELETE', '/api/files/'.$file->getId(), token: $this->login());

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(0, $this->files()->count([]));
    }

    public function testDeleteRequiresAToken(): void
    {
        $file = $this->createSharedFile($this->createUser());

        $this->json('DELETE', '/api/files/'.$file->getId());

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(1, $this->files()->count([]));
    }

    public function testCannotDeleteSomeoneElsesFile(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->createUser('bob@example.com');
        $file = $this->createSharedFile($alice);

        $this->json('DELETE', '/api/files/'.$file->getId(), token: $this->login('bob@example.com'));

        $this->assertResponseStatusCodeSame(404, 'On ne révèle pas l\'existence du fichier d\'un autre.');
        $this->assertSame(1, $this->files()->count([]));
        $this->assertFileExists($this->storage()->path($file->getStoredName()));
    }

    public function testCannotDeleteAnAnonymousFile(): void
    {
        $this->createUser();
        $file = $this->createSharedFile(null);

        $this->json('DELETE', '/api/files/'.$file->getId(), token: $this->login());

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame(1, $this->files()->count([]));
    }

    public function testDeletingAnUnknownFileGivesA404(): void
    {
        $this->createUser();

        $data = $this->json('DELETE', '/api/files/99999', token: $this->login());

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('Fichier introuvable.', $data['message']);
    }
}
