<?php

declare(strict_types=1);

namespace Seablast\Auth\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Seablast\Auth\IdentityManager;
use Seablast\Auth\UserModel;
use Seablast\Seablast\SeablastConfiguration;
use Seablast\Seablast\SeablastConstant;
use Seablast\Seablast\Superglobals;

class UserModelTest extends TestCase
{
    public function testBuildLoginUrlPreservesSafeTargetAndQueryInSubdirectory(): void
    {
        $model = $this->createModel(
            [],
            [
                'REQUEST_URI' => '/app/private/report?filter=open&page=2&tag[]=a&tag[]=b'
                    . '&token=stale&returnUrl=stale',
            ]
        );

        $loginUrl = $this->invokePrivateStringMethod($model, 'buildLoginUrl', ['fresh-token']);
        $this->assertSame('https://example.test/app/user/', parse_url($loginUrl, PHP_URL_SCHEME) . '://'
            . parse_url($loginUrl, PHP_URL_HOST) . parse_url($loginUrl, PHP_URL_PATH));

        $loginQuery = [];
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);
        $this->assertSame('fresh-token', $loginQuery['token']);
        $this->assertIsString($loginQuery['returnUrl']);

        $returnQuery = [];
        parse_str((string) parse_url($loginQuery['returnUrl'], PHP_URL_QUERY), $returnQuery);
        $this->assertSame('/private/report', parse_url($loginQuery['returnUrl'], PHP_URL_PATH));
        $this->assertSame('open', $returnQuery['filter']);
        $this->assertSame('2', $returnQuery['page']);
        $this->assertSame(['a', 'b'], $returnQuery['tag']);
        $this->assertArrayNotHasKey('token', $returnQuery);
        $this->assertArrayNotHasKey('returnUrl', $returnQuery);
    }

    public function testSuccessfulTokenRedirectsToSafeReturnUrlWithSeeOther(): void
    {
        $identity = $this->createIdentityMock(true);
        $model = $this->createModel(
            [
                'token' => 'valid-token',
                'returnUrl' => '/private/report?filter=open&token=leaked&returnUrl=nested',
            ],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/app/user/?token=valid-token'],
            $identity
        );

        $result = $model->knowledge();

        $this->assertSame('https://example.test/app/private/report?filter=open', $result->redirectionUrl);
        $this->assertSame(303, $result->httpCode);
    }

    public function testInvalidReturnUrlsFallBackToUserRoute(): void
    {
        $invalidReturnUrls = [
            'missing string value' => ['/private'],
            'absolute URL' => 'https://attacker.test/',
            'protocol-relative URL' => '//attacker.test/',
            'backslash path' => '/\\attacker.test/',
            'encoded traversal' => '/%2e%2e/admin',
            'double-encoded traversal' => '/%252e%252e/admin',
            'encoded control character' => '/%00admin',
            'raw control character' => "/safe\nLocation: https://attacker.test/",
            'fragment' => '/safe#fragment',
        ];
        foreach ($invalidReturnUrls as $description => $returnUrl) {
            $identity = $this->createIdentityMock(true);
            $model = $this->createModel(
                ['token' => 'valid-token', 'returnUrl' => $returnUrl],
                ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/app/user/?token=valid-token'],
                $identity
            );

            $result = $model->knowledge();

            $this->assertSame(
                'https://example.test/app/user',
                $result->redirectionUrl,
                'Unexpected redirect for ' . $description
            );
            $this->assertSame(303, $result->httpCode);
        }
    }

    public function testMalformedCurrentRequestFallsBackToDirectUserLogin(): void
    {
        $model = $this->createModel([], ['REQUEST_URI' => 'https://attacker.test/private']);

        $loginUrl = $this->invokePrivateStringMethod($model, 'buildLoginUrl', ['fresh-token']);
        $loginQuery = [];
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);

        $this->assertSame('/user', $loginQuery['returnUrl']);
    }

    public function testDirectUserLoginKeepsExistingDestination(): void
    {
        $model = $this->createModel([], ['REQUEST_URI' => '/app/user/']);

        $loginUrl = $this->invokePrivateStringMethod($model, 'buildLoginUrl', ['fresh-token']);
        $loginQuery = [];
        parse_str((string) parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);

        $this->assertSame('/user', $loginQuery['returnUrl']);
    }

    public function testInvalidTokenDoesNotRedirect(): void
    {
        $identity = $this->createIdentityMock(false);
        $model = $this->createModel(
            ['token' => 'invalid-token', 'returnUrl' => '/private'],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/app/user/?token=invalid-token'],
            $identity
        );

        $result = $model->knowledge();

        $this->assertTrue($result->showLogin);
        $this->assertSame('Invalid token.', $result->message);
        $this->assertFalse(isset($result->redirectionUrl));
    }

    /**
     * @param mixed[] $get
     * @param mixed[] $server
     * @param IdentityManager|null $identity
     * @return UserModel
     */
    private function createModel(array $get, array $server, ?IdentityManager $identity = null): UserModel
    {
        $configuration = new SeablastConfiguration();
        $configuration->setString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL, 'https://example.test/app');

        $reflection = new ReflectionClass(UserModel::class);
        /** @var UserModel $model */
        $model = $reflection->newInstanceWithoutConstructor();
        $this->setPrivateProperty($model, 'configuration', $configuration);
        $this->setPrivateProperty($model, 'superglobals', new Superglobals($get, [], $server));
        $this->setPrivateProperty($model, 'userRoute', '/user');
        if ($identity !== null) {
            $this->setPrivateProperty($model, 'user', $identity);
        }
        return $model;
    }

    /**
     * @param bool $validToken
     * @return IdentityManager&MockObject
     */
    private function createIdentityMock(bool $validToken): IdentityManager
    {
        /** @var IdentityManager&MockObject $identity */
        $identity = $this->getMockBuilder(IdentityManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isAuthenticated', 'isTokenValid'])
            ->getMock();
        $identity->expects($this->once())->method('isAuthenticated')->willReturn(false);
        $identity->expects($this->once())->method('isTokenValid')->willReturn($validToken);
        return $identity;
    }

    /**
     * @param UserModel $model
     * @param string $name
     * @param mixed $value
     * @return void
     */
    private function setPrivateProperty(UserModel $model, string $name, $value): void
    {
        $property = new ReflectionProperty(UserModel::class, $name);
        $property->setAccessible(true);
        $property->setValue($model, $value);
    }

    /**
     * @param UserModel $model
     * @param string $name
     * @param mixed[] $arguments
     * @return string
     */
    private function invokePrivateStringMethod(UserModel $model, string $name, array $arguments): string
    {
        $method = new ReflectionMethod(UserModel::class, $name);
        $method->setAccessible(true);
        $result = $method->invokeArgs($model, $arguments);
        $this->assertIsString($result);
        return $result;
    }
}
