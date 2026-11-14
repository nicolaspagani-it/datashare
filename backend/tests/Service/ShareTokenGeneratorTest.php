<?php

namespace App\Tests\Service;

use App\Service\ShareTokenGenerator;
use PHPUnit\Framework\TestCase;

class ShareTokenGeneratorTest extends TestCase
{
    public function testTokenHasTheExpectedLength(): void
    {
        $this->assertSame(8, strlen((new ShareTokenGenerator())->generate()));
    }

    public function testTokenOnlyContainsLettersAndDigits(): void
    {
        $generator = new ShareTokenGenerator();

        for ($i = 0; $i < 200; ++$i) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $generator->generate());
        }
    }

    public function testTokensAreDifferentEachTime(): void
    {
        $generator = new ShareTokenGenerator();
        $tokens = [];
        for ($i = 0; $i < 500; ++$i) {
            $tokens[] = $generator->generate();
        }

        // 62^8 combinaisons : une collision sur 500 tirages est pratiquement impossible.
        $this->assertCount(500, array_unique($tokens));
    }

    public function testTokensAreUsableInAUrlWithoutEncoding(): void
    {
        $token = (new ShareTokenGenerator())->generate();

        $this->assertSame($token, rawurlencode($token));
    }
}
