<?php

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Classe de base des tests fonctionnels de l'API.
 *
 * Chaque test repart de tables vides et d'un dossier de fichiers vide, et dispose d'outils :
 *  - json()       : envoie une requête JSON et renvoie le corps décodé
 *  - upload()     : envoie un fichier (multipart) à POST /api/files
 *  - createUser() : insère directement un utilisateur en base
 *  - login()      : récupère un vrai token JWT via POST /api/login
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // On vide les tables avant chaque test pour qu'ils soient indépendants les uns des autres.
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE shared_file, "user" RESTART IDENTITY CASCADE');
        $this->cleanUploadDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanUploadDirectory();
        parent::tearDown();
    }

    private function cleanUploadDirectory(): void
    {
        (new Filesystem())->remove(static::getContainer()->getParameter('app.upload_dir'));
    }

    /**
     * Envoie une requête JSON et renvoie le corps de la réponse décodé (tableau).
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    protected function json(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request($method, $uri, server: $server, content: $body !== null ? json_encode($body) : null);

        return $this->decodedResponse();
    }

    /**
     * Envoie un formulaire multipart (champs texte + fichier) à POST /api/files.
     *
     * @param array<string, string> $fields champs texte (expiration, password...)
     *
     * @return array<string, mixed>
     */
    protected function upload(array $fields = ['expiration' => '1'], ?UploadedFile $file = null, ?string $token = null): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request('POST', '/api/files', $fields, $file ? ['file' => $file] : [], $server);

        return $this->decodedResponse();
    }

    /** Crée un vrai fichier temporaire et le présente comme un fichier envoyé par un navigateur. */
    protected function makeUploadedFile(
        string $content = 'contenu du fichier',
        string $name = 'photo.jpg',
        string $mimeType = 'image/jpeg',
        int $error = \UPLOAD_ERR_OK,
    ): UploadedFile {
        $path = tempnam(sys_get_temp_dir(), 'dsup');
        file_put_contents($path, $content);

        // Le dernier argument (test: true) permet d'utiliser un fichier qui n'a pas été envoyé par HTTP.
        return new UploadedFile($path, $name, $mimeType, $error, true);
    }

    /** @return array<string, mixed> */
    protected function decodedResponse(): array
    {
        $content = $this->client->getResponse()->getContent();

        return $content ? (json_decode($content, true) ?? []) : [];
    }

    protected function createUser(string $email = 'alice@example.com', string $password = 'password123'): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function login(string $email = 'alice@example.com', string $password = 'password123'): string
    {
        $data = $this->json('POST', '/api/login', ['email' => $email, 'password' => $password]);

        return $data['token'];
    }
}
