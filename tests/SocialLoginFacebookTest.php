<?php

declare(strict_types=1);

namespace Seablast\Auth\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Seablast\Auth\AuthConstant;
use Seablast\Auth\Models\SocialLoginFacebook;
use Seablast\Seablast\SeablastConfiguration;

class SocialLoginFacebookTest extends TestCase
{
    private const FACEBOOK_APP_ID = '123456789';
    private const FACEBOOK_APP_SECRET = 'test-secret';

    public function testReturnsPayloadWhenDebugTokenMatchesApp(): void
    {
        list($model) = $this->createModel([
            new Response(200, [], (string) json_encode([
                'data' => [
                    'is_valid' => true,
                    'app_id' => self::FACEBOOK_APP_ID,
                    'expires_at' => time() + 3600,
                ],
            ])),
            new Response(200, [], (string) json_encode([
                'id' => 'facebook-user-id',
                'email' => 'user@example.com',
            ])),
        ]);

        $this->assertSame(
            [
                'id' => 'facebook-user-id',
                'email' => 'user@example.com',
            ],
            $model->authTokenToPayload('valid-access-token')
        );
    }

    public function testRejectsDebugTokenForDifferentApp(): void
    {
        list($model, $mock) = $this->createModel([
            new Response(200, [], (string) json_encode([
                'data' => [
                    'is_valid' => true,
                    'app_id' => 'other-app',
                    'expires_at' => time() + 3600,
                ],
            ])),
            new Response(200, [], (string) json_encode(['email' => 'user@example.com'])),
        ]);

        $this->assertNull($model->authTokenToPayload('wrong-app-token'));
        $this->assertCount(1, $mock);
    }

    public function testRejectsExpiredDebugToken(): void
    {
        list($model, $mock) = $this->createModel([
            new Response(200, [], (string) json_encode([
                'data' => [
                    'is_valid' => true,
                    'app_id' => self::FACEBOOK_APP_ID,
                    'expires_at' => time() - 1,
                ],
            ])),
            new Response(200, [], (string) json_encode(['email' => 'user@example.com'])),
        ]);

        $this->assertNull($model->authTokenToPayload('expired-token'));
        $this->assertCount(1, $mock);
    }

    public function testRequiresFacebookAppSecret(): void
    {
        list($model, $mock) = $this->createModel([], false);

        $this->assertNull($model->authTokenToPayload('token-without-app-secret'));
        $this->assertCount(0, $mock);
    }

    /**
     * @param array<int,Response> $responses
     * @return array{0:SocialLoginFacebook,1:MockHandler}
     */
    private function createModel(array $responses, bool $includeAppSecret = true): array
    {
        $configuration = new SeablastConfiguration();
        $configuration->setString(AuthConstant::FACEBOOK_APP_ID, self::FACEBOOK_APP_ID);
        $configuration->setString(AuthConstant::FACEBOOK_API_VERSION, 'v21.0');
        if ($includeAppSecret) {
            $configuration->setString(AuthConstant::FACEBOOK_APP_SECRET, self::FACEBOOK_APP_SECRET);
        }

        $mock = new MockHandler(array_values($responses));
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        return [new SocialLoginFacebook($configuration, $client), $mock];
    }
}
