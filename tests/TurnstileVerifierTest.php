<?php

declare(strict_types=1);

namespace Seablast\Auth\Tests;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Seablast\Auth\AuthConstant;
use Seablast\Auth\TurnstileVerifier;
use Seablast\Seablast\SeablastConfiguration;
use Tracy\Debugger;
use Tracy\ILogger;

class TurnstileVerifierTest extends TestCase
{
    /** @var ILogger */
    private $previousLogger;

    private function configuration(string $site = 'site', string $secret = 'secret'): SeablastConfiguration
    {
        $configuration = new SeablastConfiguration();
        $configuration->setString(AuthConstant::CLOUDFLARE_TURNSTILE_SITE_KEY, $site);
        $configuration->setString(AuthConstant::CLOUDFLARE_TURNSTILE_SECRET_KEY, $secret);
        $configuration->setString(AuthConstant::CLOUDFLARE_TURNSTILE_HOSTNAMES, ' example.test, www.example.test ');
        return $configuration;
    }

    protected function setUp(): void
    {
        $this->previousLogger = Debugger::getLogger();
        Debugger::setLogger($this->createMock(ILogger::class));
    }

    protected function tearDown(): void
    {
        Debugger::setLogger($this->previousLogger);
    }

    public function testDebugLogOnlyContainsRecognizedErrorCodes(): void
    {
        $logger = $this->createMock(ILogger::class);
        $logger->expects($this->once())->method('log')->with(
            'Turnstile verification rejected: success_not_true; error_codes=timeout-or-duplicate',
            ILogger::DEBUG
        );
        Debugger::setLogger($logger);
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willReturn(new Response(200, [],
            '{"success":false,"error-codes":["timeout-or-duplicate","secret","token",{}]}'
        ));
        $this->assertFalse((new TurnstileVerifier($this->configuration(), $client))->verify('token'));
    }

    public function testDisabledConfigurationDoesNotCallCloudflare(): void
    {
        $configurations = [new SeablastConfiguration(), $this->configuration('', 'secret')];
        $configurations[] = $this->configuration('site', '   ');
        foreach ($configurations as $configuration) {
            $client = $this->createMock(ClientInterface::class);
            $client->expects($this->never())->method('request');
            $verifier = new TurnstileVerifier($configuration, $client);
            $this->assertFalse($verifier->isEnabled());
            $this->assertTrue($verifier->verify(''));
        }
    }

    public function testInvalidInputDoesNotCallCloudflare(): void
    {
        foreach (['', '   ', str_repeat('a', 2049), 'valid'] as $token) {
            $configuration = $this->configuration();
            if ($token === 'valid') {
                $configuration->setString(AuthConstant::CLOUDFLARE_TURNSTILE_HOSTNAMES, ' , ');
            }
            $client = $this->createMock(ClientInterface::class);
            $client->expects($this->never())->method('request');
            $this->assertFalse((new TurnstileVerifier($configuration, $client))->verify($token));
        }
    }

    public function testResponseValidationAndRequestContract(): void
    {
        $valid = '{"success":true,"action":"login_email","hostname":"example.test"}';
        $cases = [
            [200, $valid, true],
            [200, str_replace('true', 'false', $valid), false],
            [200, str_replace('true', '"true"', $valid), false],
            [200, str_replace('login_email', 'signup', $valid), false],
            [200, str_replace('example.test', 'evil.test', $valid), false],
            [200, '{"success":false,"error-codes":["timeout-or-duplicate"]}', false],
            [200, '{}', false],
            [200, 'null', false],
            [200, 'invalid json', false],
            [302, $valid, false],
            [500, $valid, false],
        ];
        foreach ($cases as [$status, $body, $expected]) {
            $client = $this->createMock(ClientInterface::class);
            $client->expects($this->once())->method('request')->with(
                'POST',
                'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                [
                    'allow_redirects' => false,
                    'connect_timeout' => 3,
                    'form_params' => ['secret' => 'secret', 'response' => 'token'],
                    'http_errors' => false,
                    'timeout' => 10,
                ]
            )->willReturn(new Response($status, [], $body));
            $this->assertSame($expected, (new TurnstileVerifier($this->configuration(), $client))->verify('token'));
        }
    }

    public function testTimeoutFailsClosed(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('request')->willThrowException(new ConnectException('timeout', new Request('POST', '/')));
        $this->assertFalse((new TurnstileVerifier($this->configuration(), $client))->verify('token'));
    }
}
