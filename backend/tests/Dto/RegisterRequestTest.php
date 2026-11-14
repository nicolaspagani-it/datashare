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

    /**
     * @return array<string, list<string>> messages d'erreur, rangés par champ
     */
    private function messages(string $email, string $password): array
    {
        $result = [];
        foreach ($this->validator->validate(new RegisterRequest($email, $password)) as $violation) {
            $result[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $result;
    }

    public function testEmailErrorMessagesAreExplicit(): void
    {
        $this->assertSame(
            "L'adresse email n'est pas valide (exemple : nom@domaine.fr).",
            $this->messages('pas-un-email', 'password123')['email'][0],
        );
        $this->assertSame(
            "L'adresse email est trop longue (180 caractères maximum).",
            $this->messages(str_repeat('a', 176).'@x.fr', 'password123')['email'][0],
        );
        $this->assertContains("L'adresse email est obligatoire.", $this->messages('', 'password123')['email']);
    }

    public function testPasswordErrorMessagesAreExplicit(): void
    {
        $this->assertSame(
            'Le mot de passe est trop court : il doit contenir au moins 8 caractères.',
            $this->messages('alice@example.com', 'abc')['password'][0],
        );
        $this->assertSame(
            'Le mot de passe est obligatoire.',
            $this->messages('alice@example.com', '')['password'][0],
        );
        $this->assertSame(
            'Le mot de passe est trop long (4096 caractères maximum).',
            $this->messages('alice@example.com', str_repeat('a', 4097))['password'][0],
        );
    }

    public function testAnEmptyFieldOnlyShowsTheRequiredMessage(): void
    {
        // Un champ vide ne doit pas afficher en plus "trop court" ou "invalide".
        $this->assertSame(['Le mot de passe est obligatoire.'], $this->messages('alice@example.com', '')['password']);
        $this->assertSame(["L'adresse email est obligatoire."], $this->messages('', 'password123')['email']);
    }

    public function testErrorsAreAttachedToTheRightField(): void
    {
        $messages = $this->messages('pas-un-email', 'abc');

        $this->assertSame(['email', 'password'], array_keys($messages));
    }

    public function testNoMessageUsesTheVagueWordsThisValueOrThisString(): void
    {
        $all = [
            ...$this->messages('', ''),
            ...$this->messages('pas-un-email', 'abc'),
            ...$this->messages(str_repeat('a', 176).'@x.fr', str_repeat('a', 4097)),
        ];

        foreach (array_merge(...array_values($all)) as $message) {
            $this->assertStringNotContainsStringIgnoringCase('cette chaîne', $message);
            $this->assertStringNotContainsStringIgnoringCase('cette valeur', $message);
        }
    }
}
