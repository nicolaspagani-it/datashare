<?php

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Classe de base des tests fonctionnels de l'API.
 *
 * Chaque test repart d'une table "user" vide, et dispose de petits outils :
 *  - json()       : envoie une requête JSON et renvoie le corps décodé
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

        // On vide la table avant chaque test pour qu'ils soient indépendants les uns des autres.
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE "user" RESTART IDENTITY CASCADE');
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
