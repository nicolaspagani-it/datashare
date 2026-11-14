<?php

namespace App\Tests\Dto;

use App\Dto\UploadRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Règles de validation des champs texte de l'envoi d'un fichier.
 */
class UploadRequestTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    /** @return array<string, list<string>> messages d'erreur, rangés par champ */
    private function messages(string $expiration, string $password = ''): array
    {
        $result = [];
        foreach ($this->validator->validate(new UploadRequest($expiration, $password)) as $violation) {
            $result[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $result;
    }

    /** @return iterable<string, array{string}> */
    public static function validDurations(): iterable
    {
        yield 'une journée' => ['1'];
        yield '2 jours' => ['2'];
        yield '3 jours' => ['3'];
        yield '4 jours' => ['4'];
        yield '5 jours' => ['5'];
        yield '6 jours' => ['6'];
        yield 'une semaine' => ['7'];
    }

    #[DataProvider('validDurations')]
    public function testEveryDurationFromOneDayToOneWeekIsAccepted(string $days): void
    {
        $this->assertSame([], $this->messages($days));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDurations(): iterable
    {
        yield 'zéro' => ['0'];
        yield 'plus d\'une semaine' => ['8'];
        yield 'un mois' => ['30'];
        yield 'négative' => ['-1'];
        yield 'décimale' => ['1.5'];
        yield 'texte' => ['abc'];
        yield 'ancien format' => ['1d'];
    }

    #[DataProvider('invalidDurations')]
    public function testOtherDurationsAreRejected(string $days): void
    {
        $messages = $this->messages($days);

        $this->assertSame(
            ['La durée de conservation doit être comprise entre 1 jour et 1 semaine (7 jours).'],
            $messages['expiration'],
        );
    }

    public function testMissingDurationIsRejectedWithASingleMessage(): void
    {
        $this->assertSame(['Choisis une durée de conservation.'], $this->messages('')['expiration']);
    }

    public function testPasswordIsOptional(): void
    {
        $this->assertSame([], $this->messages('1', ''));
        $this->assertSame([], $this->messages('1', 'secret'));
    }

    public function testVeryLongPasswordIsRejected(): void
    {
        $this->assertSame(
            ['Le mot de passe est trop long (4096 caractères maximum).'],
            $this->messages('1', str_repeat('a', 4097))['password'],
        );
    }
}
