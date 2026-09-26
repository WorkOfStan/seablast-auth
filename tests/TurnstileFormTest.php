<?php

declare(strict_types=1);

namespace Seablast\Auth\Tests;

use Latte\Engine;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Seablast\Auth\AuthConstant;
use Seablast\Auth\IdentityManager;
use Seablast\Auth\TurnstileVerifier;
use Seablast\Auth\UserModel;
use Seablast\Seablast\SeablastConfiguration;
use Seablast\Seablast\SeablastConstant;
use Seablast\Seablast\Superglobals;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Tracy\Debugger;
use Tracy\ILogger;

class TurnstileFormTest extends TestCase
{
    public function testPostGatePrecedesSideEffectsAndPreservesThrottle(): void
    {
        $csrf = (new CsrfTokenManager())->getToken('sb_json')->getValue();
        $cases = [
            ['token', false, true, 'user@example.test', $csrf],
            [null, false, true, 'user@example.test', $csrf],
            [['token'], false, true, 'user@example.test', $csrf],
            ['token', true, true, 'user@example.test', $csrf],
            [null, true, false, 'user@example.test', $csrf],
            ['token', false, true, 'invalid-email', $csrf],
            ['token', false, true, 'user@example.test', 'invalid-csrf'],
        ];
        $logger = Debugger::getLogger();
        Debugger::setLogger($this->createMock(ILogger::class));
        try {
            foreach ($cases as [$token, $accepted, $enabled, $email, $submittedCsrf]) {
                $validInput = $email !== 'invalid-email' && $submittedCsrf === $csrf;
                $verifier = $this->getMockBuilder(TurnstileVerifier::class)->disableOriginalConstructor()->getMock();
                $verifier->method('isEnabled')->willReturn($enabled);
                $verifier->expects($validInput && $enabled && is_string($token) ? $this->once() : $this->never())
                    ->method('verify')->willReturn($accepted);
                $identity = $this->getMockBuilder(IdentityManager::class)->disableOriginalConstructor()->getMock();
                $identity->method('isAuthenticated')->willReturn(false);
                $identity->expects($accepted ? $this->once() : $this->never())
                    ->method('isLoginEmailRecentlyRequested')->willReturn(true);
                // login creates the user and token; rejected requests must never reach it or mail delivery.
                $identity->expects($this->never())->method('login');
                $model = (new ReflectionClass(UserModel::class))->newInstanceWithoutConstructor();
                foreach (
                    [
                    'configuration' => new SeablastConfiguration(),
                    'superglobals' => new Superglobals([], [
                        'email' => $email,
                        'csrfToken' => $submittedCsrf,
                        'cf-turnstile-response' => $token,
                    ], ['REQUEST_METHOD' => 'POST']),
                    'turnstileVerifier' => $verifier,
                    'user' => $identity,
                    ] as $name => $value
                ) {
                    $property = new ReflectionProperty(UserModel::class, $name);
                    $property->setAccessible(true);
                    $property->setValue($model, $value);
                }
                $result = $model->knowledge();
                $this->assertSame(!$accepted, $result->showLogin);
            }
        } finally {
            Debugger::setLogger($logger);
        }
    }

    public function testSuccessfulVerificationReachesLogin(): void
    {
        $configuration = new SeablastConfiguration();
        $configuration->setString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL, 'https://example.test');
        $configuration->setString(AuthConstant::TEXT_EMAIL_LOGIN, 'Login: %URL%');
        $configuration->flag->deactivate(SeablastConstant::USER_MAIL_ENABLED);
        $verifier = $this->getMockBuilder(TurnstileVerifier::class)->disableOriginalConstructor()->getMock();
        $verifier->method('isEnabled')->willReturn(true);
        $verifier->expects($this->once())->method('verify')->with('token')->willReturn(true);
        $identity = $this->getMockBuilder(IdentityManager::class)->disableOriginalConstructor()->getMock();
        $identity->method('isAuthenticated')->willReturn(false);
        $identity->expects($this->once())->method('isLoginEmailRecentlyRequested')->willReturn(false);
        $identity->expects($this->once())->method('login')->with('user@example.test')->willReturn('email-token');
        $identity->method('isNewUser')->willReturn(false);
        $model = (new ReflectionClass(UserModel::class))->newInstanceWithoutConstructor();
        foreach (
            [
            'configuration' => $configuration,
            'superglobals' => new Superglobals([], [
                'email' => 'user@example.test',
                'csrfToken' => (new CsrfTokenManager())->getToken('sb_json')->getValue(),
                'cf-turnstile-response' => 'token',
            ], ['REQUEST_METHOD' => 'POST']),
            'turnstileVerifier' => $verifier,
            'user' => $identity,
            'userRoute' => '/user',
            ] as $name => $value
        ) {
            $property = new ReflectionProperty(UserModel::class, $name);
            $property->setAccessible(true);
            $property->setValue($model, $value);
        }
        $this->assertFalse($model->knowledge()->showLogin);
    }

    public function testWidgetRenderingNeverExposesSecret(): void
    {
        foreach ([false, true] as $enabled) {
            $configuration = new SeablastConfiguration();
            if ($enabled) {
                $configuration->setString(AuthConstant::CLOUDFLARE_TURNSTILE_SITE_KEY, 'public-site');
                $configuration->setString(AuthConstant::CLOUDFLARE_TURNSTILE_SECRET_KEY, 'private-secret-value');
            }
            $html = (new Engine())->renderToString(__DIR__ . '/../views/login-form.latte', [
                'configuration' => $configuration,
                'csrfToken' => 'csrf',
                'message' => '',
                'showLogin' => true,
                'showLogout' => false,
            ]);
            $this->assertSame($enabled, strpos($html, 'data-action="login_email"') !== false);
            $script = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
            $this->assertSame($enabled, strpos($html, $script) !== false);
            $this->assertStringNotContainsString('private-secret-value', $html);
        }
    }
}
