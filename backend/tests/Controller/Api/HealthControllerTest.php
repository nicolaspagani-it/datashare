<?php

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;

class HealthControllerTest extends ApiTestCase
{
    public function testHealthIsPublicAndReturnsOk(): void
    {
        $data = $this->json('GET', '/api/health');

        $this->assertResponseIsSuccessful();
        $this->assertSame(['app' => 'datashare', 'status' => 'ok'], $data);
    }
}
