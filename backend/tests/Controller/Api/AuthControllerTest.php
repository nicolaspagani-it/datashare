<?php

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests fonctionnels de l'authentification : inscription, connexion JWT, /api/me.
 * Ils passent par le vrai kernel, les vrais firewalls et la base "datashare_test".
 */
class AuthControllerTest extends ApiTestCase
{
    // ------------------------------------------------------------------ inscription

    public function testRegisterCreatesAUser(): void
    {
        $data = $this->json('POST', '/api/register', ['email' => 'alice@example.com', 'password' => 'password123']);

        $this->assertResponseStatusCodeSame(201);
        $this->assertSame('alice@example.com', $data['email']);
        $this->assertIsInt($data['id']);
        $this->assertArrayNotHasKey('password', $data, 'La réponse ne doit jamais contenir le mot de passe.');
    }

    public function testRegisterStoresAHashedPassword(): void
    {
        $this->json('POST', '/api/register', ['email' => 'alice@example.com', 'password' => 'password123']);

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'alice@example.com']);

        $this->assertNotNull($user);
        $this->assertNotSame('password123', $user->getPassword());
        $this->assertTrue(
            static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'password123'),
        );
    }

    public function testRegisterRejectsAnEmailAlreadyUsed(): void
    {
        $this->createUser('alice@example.com');

        $data = $this->json('POST', '/api/register', ['email' => 'alice@example.com', 'password' => 'password123']);

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('Cet email est déjà utilisé.', $data['message']);
        $this->assertSame(1, $this->em->getRepository(User::class)->count([]));
    }

    public function testRegisterRejectsAnInvalidEmail(): void
    {
        $this->json('POST', '/api/register', ['email' => 'pas-un-email', 'password' => 'password123']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->em->getRepository(User::class)->count([]));
    }

    public function testRegisterRejectsAPasswordThatIsTooShort(): void
    {
        $data = $this->json('POST', '/api/register', ['email' => 'alice@example.com', 'password' => 'abc']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame('password', $data['violations'][0]['propertyPath']);
        $this->assertStringContainsString('trop courte', $data['violations'][0]['title']);
    }

    public function testRegisterRejectsAnEmptyBody(): void
    {
        $this->json('POST', '/api/register', []);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testRegisterIsPublic(): void
    {
        // Pas de token : l'inscription doit rester accessible (PUBLIC_ACCESS).
        $this->json('POST', '/api/register', ['email' => 'bob@example.com', 'password' => 'password123']);

        $this->assertResponseStatusCodeSame(201);
    }

    // ------------------------------------------------------------------ connexion

    public function testLoginReturnsAJwt(): void
    {
        $this->createUser('alice@example.com', 'password123');

        $data = $this->json('POST', '/api/login', ['email' => 'alice@example.com', 'password' => 'password123']);

        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('token', $data);
        // Un JWT est composé de 3 segments séparés par des points : en-tête.contenu.signature
        $this->assertCount(3, explode('.', $data['token']));
    }

    public function testLoginFailsWithAWrongPassword(): void
    {
        $this->createUser('alice@example.com', 'password123');

        $data = $this->json('POST', '/api/login', ['email' => 'alice@example.com', 'password' => 'mauvais']);

        $this->assertResponseStatusCodeSame(401);
        $this->assertArrayNotHasKey('token', $data);
    }

    public function testLoginFailsForAnUnknownUser(): void
    {
        $data = $this->json('POST', '/api/login', ['email' => 'inconnu@example.com', 'password' => 'password123']);

        $this->assertResponseStatusCodeSame(401);
        $this->assertArrayNotHasKey('token', $data);
    }

    public function testLoginFailsWithMissingCredentials(): void
    {
        $this->json('POST', '/api/login', []);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testLoginWorksRightAfterRegistration(): void
    {
        // Le scénario réel du frontend : s'inscrire puis se connecter avec les mêmes identifiants.
        $this->json('POST', '/api/register', ['email' => 'alice@example.com', 'password' => 'password123']);

        $this->assertNotEmpty($this->login('alice@example.com', 'password123'));
    }

    // ------------------------------------------------------------------ /api/me

    public function testMeRequiresAToken(): void
    {
        $this->json('GET', '/api/me');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMeReturnsTheCurrentUser(): void
    {
        $user = $this->createUser('alice@example.com');

        $data = $this->json('GET', '/api/me', token: $this->login());

        $this->assertResponseIsSuccessful();
        $this->assertSame($user->getId(), $data['id']);
        $this->assertSame('alice@example.com', $data['email']);
        $this->assertSame(['ROLE_USER'], $data['roles']);
        $this->assertArrayNotHasKey('password', $data);
    }

    public function testMeRejectsAnInvalidToken(): void
    {
        $this->json('GET', '/api/me', token: 'ceci.nest.pas-un-jwt');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMeRejectsATamperedToken(): void
    {
        $this->createUser();
        $token = $this->login();

        // On modifie la signature (dernier segment) : le token ne doit plus être accepté.
        $parts = explode('.', $token);
        $parts[2] = strrev($parts[2]);

        $this->json('GET', '/api/me', token: implode('.', $parts));

        $this->assertResponseStatusCodeSame(401);
    }
}
