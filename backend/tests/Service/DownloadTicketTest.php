<?php

namespace App\Tests\Service;

use App\Entity\SharedFile;
use App\Service\DownloadTicket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du ticket de téléchargement signé.
 */
class DownloadTicketTest extends TestCase
{
    private DownloadTicket $tickets;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->tickets = new DownloadTicket();
        $this->now = new \DateTimeImmutable('2026-01-10 12:00:00');
    }

    private function protectedFile(string $token = 'AAAA1111', string $hash = 'hash-secret-1'): SharedFile
    {
        return (new SharedFile())->setToken($token)->setPasswordHash($hash);
    }

    public function testTicketHasTheExpectedFormat(): void
    {
        $ticket = $this->tickets->issue($this->protectedFile(), $this->now);

        $this->assertMatchesRegularExpression('/^\d+\.[a-f0-9]{64}$/', $ticket);
        $this->assertStringStartsWith((string) ($this->now->getTimestamp() + DownloadTicket::TTL).'.', $ticket);
    }

    public function testTicketIsValidRightAfterBeingIssued(): void
    {
        $file = $this->protectedFile();

        $this->assertTrue($this->tickets->isValid($file, $this->tickets->issue($file, $this->now), $this->now));
    }

    public function testTicketIsValidUntilItsExpirationAndNotAfter(): void
    {
        $file = $this->protectedFile();
        $ticket = $this->tickets->issue($file, $this->now);

        $this->assertTrue($this->tickets->isValid($file, $ticket, $this->now->modify('+'.DownloadTicket::TTL.' seconds')));
        $this->assertFalse($this->tickets->isValid($file, $ticket, $this->now->modify('+'.(DownloadTicket::TTL + 1).' seconds')));
    }

    public function testTicketIsRejectedWhenItsSignatureIsAltered(): void
    {
        $file = $this->protectedFile();
        $ticket = $this->tickets->issue($file, $this->now);
        $altered = substr($ticket, 0, -1).(str_ends_with($ticket, '0') ? '1' : '0');

        $this->assertFalse($this->tickets->isValid($file, $altered, $this->now));
    }

    public function testExpirationCannotBeExtendedByEditingTheTicket(): void
    {
        $file = $this->protectedFile();
        [, $signature] = explode('.', $this->tickets->issue($file, $this->now));
        $extended = ($this->now->getTimestamp() + 999999).'.'.$signature;

        $this->assertFalse($this->tickets->isValid($file, $extended, $this->now));
    }

    public function testTicketOnlyWorksForTheFileItWasIssuedFor(): void
    {
        $ticket = $this->tickets->issue($this->protectedFile('AAAA1111'), $this->now);

        $this->assertFalse($this->tickets->isValid($this->protectedFile('BBBB2222'), $ticket, $this->now), 'Autre lien.');
        $this->assertFalse($this->tickets->isValid($this->protectedFile('AAAA1111', 'autre-hash'), $ticket, $this->now), 'Autre mot de passe.');
    }

    public function testNoTicketIsValidForAFileWithoutPassword(): void
    {
        $withPassword = $this->protectedFile();
        $withoutPassword = (new SharedFile())->setToken('AAAA1111');

        $this->assertFalse($this->tickets->isValid($withoutPassword, $this->tickets->issue($withPassword, $this->now), $this->now));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedTickets(): iterable
    {
        yield 'vide' => [''];
        yield 'texte' => ['n-importe-quoi'];
        yield 'sans signature' => ['1768046400.'];
        yield 'signature trop courte' => ['1768046400.abcdef'];
        yield 'signature en majuscules' => ['1768046400.'.'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789'];
        yield 'expiration négative' => ['-5.'.'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'];
        yield 'expiration démesurée' => ['9999999999999999.'.'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'];
        yield 'caractères en trop' => ['1768046400.'.'0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'.'x'];
    }

    #[DataProvider('malformedTickets')]
    public function testMalformedTicketsAreRejectedWithoutError(string $ticket): void
    {
        $this->assertFalse($this->tickets->isValid($this->protectedFile(), $ticket, $this->now));
    }
}
