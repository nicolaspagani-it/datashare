<?php

namespace App\Tests\Dto;

use App\Dto\RegisterRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Tests des règles de validation de l'inscription (RegisterRequest).
 * On utilise le vrai validateur de Symfony, sans passer par HTTP ni la base.
 */
class RegisterRequestTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    /**
     * @return list<string> les champs en erreur (propertyPath)
     */
    private function invalidFields(string $email, string $password): array
    {
        $violations = $this->validator->validate(new RegisterRequest($email, $password));

        return array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($violations));
    }

    public function testValidDataHasNoViolation(): void
    {
        $this->assertSame([], $this->invalidFields('alice@example.com', 'password123'));
    }

    public function testBlankEmailIsRejected(): void
    {
        $this->assertContains('email', $this->invalidFields('', 'password123'));
    }

    public function testMalformedEmailIsRejected(): void
    {
        $this->assertContains('email', $this->invalidFields('pas-un-email', 'password123'));
    }

    public function testEmailLongerThan180CharactersIsRejected(): void
    {
        $email = str_repeat('a', 175).'@x.fr'; // 180 caractères pile : accepté
        $this->assertNotContains('email', $this->invalidFields($email, 'password123'));

        $this->assertContains('email', $this->invalidFields('a'.$email, 'password123')); // 181 : refusé
    }

    public function testBlankPasswordIsRejected(): void
    {
        $this->assertContains('password', $this->invalidFields('alice@example.com', ''));
    }

    public function testPasswordShorterThan8CharactersIsRejected(): void
    {
        $this->assertContains('password', $this->invalidFields('alice@example.com', '1234567'));
    }

    public function testPasswordOfExactly8CharactersIsAccepted(): void
    {
        $this->assertSame([], $this->invalidFields('alice@example.com', '12345678'));
    }

    public function testErrorMessagesAreInFrench(): void
    {
        $violations = $this->validator->validate(new RegisterRequest('alice@example.com', 'abc'));

        $this->assertStringContainsString('trop courte', (string) $violations[0]->getMessage());
    }
}
