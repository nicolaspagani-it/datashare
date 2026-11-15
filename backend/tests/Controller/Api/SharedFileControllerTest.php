<?php

namespace App\Tests\Controller\Api;

use App\Entity\SharedFile;
use App\Service\DownloadTicket;
use App\Service\FileStorage;
use App\Tests\Support\ApiTestCase;

/**
 * Tests fonctionnels de la récupération d'un fichier à partir de son lien :
 * informations, déverrouillage par mot de passe et téléchargement.
 */
class SharedFileControllerTest extends ApiTestCase
{
    private const EXPIRED = "Ce fichier a expiré, il n'est plus stocké chez nous.";

    private function storage(): FileStorage
    {
        return static::getContainer()->get(FileStorage::class);
    }

    private function tickets(): DownloadTicket
    {
        return static::getContainer()->get(DownloadTicket::class);
    }

    /** @return array<string, mixed> */
    private function unlock(SharedFile $file, string $password): array
    {
        return $this->json('POST', '/api/shared/'.$file->getToken().'/unlock', ['password' => $password]);
    }

    // ------------------------------------------------------------------ GET /api/shared/{token}

    public function testShowIsPublicAndReturnsTheFileInformation(): void
    {
        $file = $this->createStoredFile('rapport.pdf', 'abcdef', 'application/pdf', '+3 days');

        $data = $this->json('GET', '/api/shared/'.$file->getToken());

        $this->assertResponseIsSuccessful();
        $this->assertSame($file->getToken(), $data['token']);
        $this->assertSame('rapport.pdf', $data['name']);
        $this->assertSame(6, $data['size']);
        $this->assertSame('application/pdf', $data['type']);
        $this->assertFalse($data['protected']);
        $this->assertSame($file->getExpiresAt()->format(\DATE_ATOM), $data['expiresAt']);
    }

    public function testShowFlagsAProtectedFile(): void
    {
        $file = $this->createStoredFile(password: 'secret');

        $this->assertTrue($this->json('GET', '/api/shared/'.$file->getToken())['protected']);
    }

    public function testShowNeverExposesInternalOrSecretFields(): void
    {
        $file = $this->createStoredFile(password: 'secret', owner: $this->createUser());

        $data = $this->json('GET', '/api/shared/'.$file->getToken());

        $keys = array_keys($data);
        sort($keys);
        $this->assertSame(['expiresAt', 'name', 'protected', 'size', 'token', 'type'], $keys);
        foreach (['storedName', 'passwordHash', 'owner', 'id', 'url'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    public function testShowUnknownLinkGives404(): void
    {
        $data = $this->json('GET', '/api/shared/ZZZZ9999');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame("Ce lien n'existe pas.", $data['message']);
    }

    public function testShowAnExpiredFileGives410(): void
    {
        $file = $this->createStoredFile(expires: '-1 minute');

        $data = $this->json('GET', '/api/shared/'.$file->getToken());

        $this->assertResponseStatusCodeSame(410);
        $this->assertSame(self::EXPIRED, $data['message']);
    }

    public function testShowAFileWhoseContentWasPurgedGives410(): void
    {
        $file = $this->createStoredFile(withContent: false);

        $this->json('GET', '/api/shared/'.$file->getToken());

        $this->assertResponseStatusCodeSame(410);
    }

    public function testShowAFileMissingFromDiskGives410(): void
    {
        $file = $this->createStoredFile();
        $this->storage()->remove($file->getStoredName());

        $this->json('GET', '/api/shared/'.$file->getToken());

        $this->assertResponseStatusCodeSame(410);
    }

    public function testLinksWithUnexpectedCharactersOrLengthDoNotMatchAnyRoute(): void
    {
        $this->json('GET', '/api/shared/ab-cd');
        $this->assertResponseStatusCodeSame(404);

        $this->json('GET', '/api/shared/'.str_repeat('a', 17));
        $this->assertResponseStatusCodeSame(404);
    }

    // ------------------------------------------------------------------ GET /api/shared/{token}/download : sans mot de passe

    public function testAnyoneCanDownloadAnUnprotectedFile(): void
    {
        $file = $this->createStoredFile('notes.txt', 'mon contenu à télécharger', 'text/plain');

        $this->client->request('GET', '/api/shared/'.$file->getToken().'/download');

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('mon contenu à télécharger', $this->responseBody());
    }

    public function testDownloadIsAlwaysAnAttachmentWithTheOriginalName(): void
    {
        $file = $this->createStoredFile('photo.jpg', 'x', 'image/jpeg');

        $this->client->request('GET', '/api/shared/'.$file->getToken().'/download');

        $headers = $this->client->getResponse()->headers;
        $this->assertStringContainsString('attachment', $headers->get('Content-Disposition'));
        $this->assertStringContainsString('filename=photo.jpg', $headers->get('Content-Disposition'));
        $this->assertSame('image/jpeg', $headers->get('Content-Type'));
    }

    public function testDownloadKeepsAccentsInTheFileName(): void
    {
        $file = $this->createStoredFile('rapport été.pdf', 'x');

        $this->client->request('GET', '/api/shared/'.$file->getToken().'/download');

        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');
        $this->assertStringContainsString("filename*=utf-8''rapport%20%C3%A9t%C3%A9.pdf", $disposition);
        $this->assertStringContainsString('filename="rapport _t_.pdf"', $disposition, 'Version ASCII pour les anciens navigateurs.');
    }

    public function testDownloadIsNeverInterpretedByTheBrowserNorCachedByProxies(): void
    {
        $file = $this->createStoredFile('page.html', '<script>alert(1)</script>', 'text/html');

        $this->client->request('GET', '/api/shared/'.$file->getToken().'/download');

        $headers = $this->client->getResponse()->headers;
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', $headers->get('Content-Disposition'), 'Un fichier HTML est téléchargé, jamais affiché.');
        $this->assertStringContainsString('no-store', $headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $headers->get('Cache-Control'));
    }

    public function testDownloadAnnouncesItsSize(): void
    {
        $file = $this->createStoredFile(content: str_repeat('a', 1234));

        $this->client->request('GET', '/api/shared/'.$file->getToken().'/download');

        $this->assertSame('1234', $this->client->getResponse()->headers->get('Content-Length'));
    }

    public function testDownloadWorksWithoutBeingLoggedInAndIgnoresAnUselessTicket(): void
    {
        $file = $this->createStoredFile(content: 'public');

        $this->client->request('GET', '/api/shared/'.$file->getToken().'/download?ticket=nimporte-quoi');

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('public', $this->responseBody());
    }

    public function testDownloadUnknownLinkGives404(): void
    {
        $this->json('GET', '/api/shared/ZZZZ9999/download');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDownloadAnExpiredFileGives410EvenIfItsContentIsStillOnDisk(): void
    {
        $file = $this->createStoredFile(expires: '-1 second');
        $this->assertTrue($this->storage()->exists($file->getStoredName()));

        $data = $this->json('GET', '/api/shared/'.$file->getToken().'/download');

        $this->assertResponseStatusCodeSame(410);
        $this->assertSame(self::EXPIRED, $data['message']);
    }

    public function testDownloadAPurgedFileGives410(): void
    {
        $file = $this->createStoredFile(withContent: false);

        $this->json('GET', '/api/shared/'.$file->getToken().'/download');

        $this->assertResponseStatusCodeSame(410);
    }

    public function testDownloadCanBeResumedWithARangeRequest(): void
    {
        $file = $this->createStoredFile(content: '0123456789');

        $this->client->request('GET', '/api/shared/'.$file->getToken().'/download', server: ['HTTP_RANGE' => 'bytes=2-5']);

        $this->assertResponseStatusCodeSame(206);
        $this->assertSame('2345', $this->responseBody());
    }

    // ------------------------------------------------------------------ GET /download : avec mot de passe

    public function testProtectedFileCannotBeDownloadedWithoutATicket(): void
    {
        $file = $this->createStoredFile(content: 'confidentiel', password: 'secret');

        $data = $this->json('GET', '/api/shared/'.$file->getToken().'/download');

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('password', $data['violations'][0]['propertyPath']);
        $this->assertStringNotContainsString('confidentiel', $this->responseBody());
    }

    public function testProtectedFileRefusesAMadeUpTicket(): void
    {
        $file = $this->createStoredFile(password: 'secret');

        $this->json('GET', '/api/shared/'.$file->getToken().'/download?ticket=nimporte-quoi');
        $this->assertResponseStatusCodeSame(403);

        $forged = (time() + 600).'.'.str_repeat('a', 64);
        $this->json('GET', '/api/shared/'.$file->getToken().'/download?ticket='.$forged);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testTicketOfAnotherFileIsRefused(): void
    {
        $mine = $this->createStoredFile(password: 'secret');
        $other = $this->createStoredFile(content: 'autre fichier', password: 'secret');
        $ticketOfMine = $this->tickets()->issue($mine);

        $this->json('GET', '/api/shared/'.$other->getToken().'/download?ticket='.$ticketOfMine);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testValidTicketDownloadsTheProtectedFile(): void
    {
        $file = $this->createStoredFile(content: 'confidentiel', password: 'secret');

        $this->client->request('GET', '/api/shared/'.$file->getToken().'/download?ticket='.$this->tickets()->issue($file));

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('confidentiel', $this->responseBody());
    }

    public function testExpiredTicketIsRefused(): void
    {
        $file = $this->createStoredFile(password: 'secret');
        $oldTicket = $this->tickets()->issue($file, new \DateTimeImmutable('-1 hour'));

        $this->json('GET', '/api/shared/'.$file->getToken().'/download?ticket='.$oldTicket);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testValidTicketDoesNotOutliveTheFileExpiration(): void
    {
        $file = $this->createStoredFile(password: 'secret');
        $ticket = $this->tickets()->issue($file);
        $file->setExpiresAt(new \DateTimeImmutable('-1 second')); // le fichier expire après la délivrance du ticket
        $this->em->flush();

        $this->json('GET', '/api/shared/'.$file->getToken().'/download?ticket='.$ticket);

        $this->assertResponseStatusCodeSame(410);
    }

    // ------------------------------------------------------------------ POST /unlock

    public function testUnlockOfAnUnprotectedFileGivesTheDownloadUrlDirectly(): void
    {
        $file = $this->createStoredFile(content: 'public');

        $data = $this->unlock($file, '');

        $this->assertResponseIsSuccessful();
        $this->assertSame('/api/shared/'.$file->getToken().'/download', $data['downloadUrl']);
        $this->client->request('GET', $data['downloadUrl']);
        $this->assertSame('public', $this->responseBody());
    }

    public function testRightPasswordGivesAUrlThatDownloadsTheFile(): void
    {
        $file = $this->createStoredFile(content: 'confidentiel', password: 'mon-secret');

        $data = $this->unlock($file, 'mon-secret');

        $this->assertResponseIsSuccessful();
        $this->assertStringStartsWith('/api/shared/'.$file->getToken().'/download?ticket=', $data['downloadUrl']);
        $this->client->request('GET', $data['downloadUrl']); // le parcours complet, comme le navigateur
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('confidentiel', $this->responseBody());
    }

    public function testUnlockResponseOnlyContainsTheDownloadUrl(): void
    {
        $file = $this->createStoredFile(password: 'mon-secret');

        $data = $this->unlock($file, 'mon-secret');

        $this->assertSame(['downloadUrl'], array_keys($data));
    }

    public function testUnlockAcceptsAFormEncodedBodyToo(): void
    {
        $file = $this->createStoredFile(password: 'mon-secret');

        $this->client->request('POST', '/api/shared/'.$file->getToken().'/unlock', ['password' => 'mon-secret'], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('downloadUrl', $this->decodedResponse());
    }

    public function testWrongPasswordIsRefusedOnThePasswordField(): void
    {
        $file = $this->createStoredFile(password: 'mon-secret');

        $data = $this->unlock($file, 'mauvais');

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('Mot de passe incorrect.', $data['message']);
        $this->assertSame('password', $data['violations'][0]['propertyPath']);
        $this->assertArrayNotHasKey('downloadUrl', $data);
    }

    public function testPasswordIsCaseSensitive(): void
    {
        $file = $this->createStoredFile(password: 'MonSecret');

        $this->unlock($file, 'monsecret');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testMissingPasswordIsReportedOnThePasswordField(): void
    {
        $file = $this->createStoredFile(password: 'mon-secret');

        $data = $this->unlock($file, '');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('Le mot de passe est obligatoire.', $data['violations'][0]['title']);
    }

    public function testUnlockUnknownLinkGives404(): void
    {
        $this->json('POST', '/api/shared/ZZZZ9999/unlock', ['password' => 'x']);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnlockAnExpiredFileGives410(): void
    {
        $file = $this->createStoredFile(expires: '-1 minute', password: 'mon-secret');

        $this->unlock($file, 'mon-secret');

        $this->assertResponseStatusCodeSame(410);
    }

    // ------------------------------------------------------------------ limitation des essais

    public function testTooManyWrongPasswordsLocksTheFile(): void
    {
        $file = $this->createStoredFile(password: 'mon-secret');

        for ($i = 1; $i <= 5; ++$i) {
            $this->unlock($file, 'mauvais'.$i);
            $this->assertResponseStatusCodeSame(403, "Essai $i");
        }
        $data = $this->unlock($file, 'mauvais6');

        $this->assertResponseStatusCodeSame(429);
        $this->assertStringContainsString('Trop de tentatives', $data['message']);
        $this->assertSame('password', $data['violations'][0]['propertyPath']);
        $this->assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testEvenTheRightPasswordIsRefusedWhileLocked(): void
    {
        $file = $this->createStoredFile(password: 'mon-secret');
        for ($i = 0; $i < 5; ++$i) {
            $this->unlock($file, 'mauvais');
        }

        $data = $this->unlock($file, 'mon-secret');

        $this->assertResponseStatusCodeSame(429);
        $this->assertArrayNotHasKey('downloadUrl', $data);
    }

    public function testTheLockOnlyAffectsTheTargetedFile(): void
    {
        $locked = $this->createStoredFile(password: 'mon-secret');
        $other = $this->createStoredFile(password: 'mon-secret');
        for ($i = 0; $i < 6; ++$i) {
            $this->unlock($locked, 'mauvais');
        }

        $this->unlock($other, 'mon-secret');

        $this->assertResponseIsSuccessful();
    }

    public function testASuccessfulAttemptResetsTheCounter(): void
    {
        $file = $this->createStoredFile(password: 'mon-secret');
        for ($i = 0; $i < 4; ++$i) {
            $this->unlock($file, 'mauvais');
        }
        $this->unlock($file, 'mon-secret'); // réussit : remet le compteur à zéro
        $this->assertResponseIsSuccessful();

        for ($i = 0; $i < 4; ++$i) {
            $this->unlock($file, 'mauvais');
            $this->assertResponseStatusCodeSame(403, 'Pas encore verrouillé après la remise à zéro.');
        }
    }

    public function testEmptyAttemptsDoNotCountAgainstTheLimit(): void
    {
        $file = $this->createStoredFile(password: 'mon-secret');
        for ($i = 0; $i < 10; ++$i) {
            $this->unlock($file, '');
            $this->assertResponseStatusCodeSame(422);
        }

        $this->unlock($file, 'mauvais');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUnprotectedFilesAreNeverRateLimited(): void
    {
        $file = $this->createStoredFile();

        for ($i = 0; $i < 10; ++$i) {
            $this->unlock($file, '');
            $this->assertResponseIsSuccessful();
        }
    }
}
