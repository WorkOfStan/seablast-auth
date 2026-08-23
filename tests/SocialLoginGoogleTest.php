<?php

declare(strict_types=1);

namespace Seablast\Auth\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Seablast\Auth\AuthConstant;
use Seablast\Auth\Models\SocialLoginGoogle;
use Seablast\Seablast\SeablastConfiguration;

class SocialLoginGoogleTest extends TestCase
{
    private const GOOGLE_CLIENT_ID = 'test-client.apps.googleusercontent.com';

    public function testReturnsPayloadForValidTokeninfoResponse(): void
    {
        $payload = [
            'aud' => self::GOOGLE_CLIENT_ID,
            'iss' => 'https://accounts.google.com',
            'email' => 'user@example.com',
            'exp' => (string) (time() + 3600),
            'email_verified' => 'true',
        ];

        $model = $this->createModel([
            new Response(200, [], (string) json_encode($payload)),
        ]);

        $this->assertSame($payload, $model->authTokenToPayload('valid-id-token'));
    }

    public function testRejectsExpiredTokeninfoResponse(): void
    {
        $model = $this->createModel([
            new Response(200, [], (string) json_encode([
                'aud' => self::GOOGLE_CLIENT_ID,
                'iss' => 'accounts.google.com',
                'email' => 'user@example.com',
                'exp' => (string) (time() - 1),
                'email_verified' => 'true',
            ])),
        ]);

        $this->assertFalse($model->authTokenToPayload('expired-id-token'));
    }

    public function testRejectsAudienceMismatch(): void
    {
        $model = $this->createModel([
            new Response(200, [], (string) json_encode([
                'aud' => 'other-client.apps.googleusercontent.com',
                'iss' => 'https://accounts.google.com',
                'email' => 'user@example.com',
                'exp' => (string) (time() + 3600),
                'email_verified' => 'true',
            ])),
        ]);

        $this->assertFalse($model->authTokenToPayload('wrong-audience-id-token'));
    }

    public function testRejectsMissingExpiration(): void
    {
        $model = $this->createModel([
            new Response(200, [], (string) json_encode([
                'aud' => self::GOOGLE_CLIENT_ID,
                'iss' => 'https://accounts.google.com',
                'email' => 'user@example.com',
                'email_verified' => 'true',
            ])),
        ]);

        $this->assertFalse($model->authTokenToPayload('missing-expiration-id-token'));
    }

    public function testRejectsUnverifiedEmail(): void
    {
        $model = $this->createModel([
            new Response(200, [], (string) json_encode([
                'aud' => self::GOOGLE_CLIENT_ID,
                'iss' => 'https://accounts.google.com',
                'email' => 'user@example.com',
                'exp' => (string) (time() + 3600),
                'email_verified' => 'false',
            ])),
        ]);

        $this->assertFalse($model->authTokenToPayload('unverified-email-id-token'));
    }

    public function testRejectsMalformedTokeninfoResponse(): void
    {
        $model = $this->createModel([
            new Response(200, [], '{'),
        ]);

        $this->assertFalse($model->authTokenToPayload('malformed-response-id-token'));
    }

    public function testRejectsFailedTokeninfoResponse(): void
    {
        $model = $this->createModel([
            new Response(400, [], (string) json_encode(['error' => 'invalid_token'])),
        ]);

        $this->assertFalse($model->authTokenToPayload('failed-response-id-token'));
    }

    /**
     * @param array<int,Response> $responses
     * @return SocialLoginGoogle
     */
    private function createModel(array $responses): SocialLoginGoogle
    {
        $configuration = new SeablastConfiguration();
        $configuration->setString(AuthConstant::GOOGLE_CLIENT_ID, self::GOOGLE_CLIENT_ID);

        $mock = new MockHandler(array_values($responses));
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        return new SocialLoginGoogle($configuration, $client);
    }
}
