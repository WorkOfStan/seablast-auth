<?php

declare(strict_types=1);

namespace Seablast\Auth\Tests;

use Latte\Engine;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Seablast\Auth\AuthConstant;
use Seablast\Auth\IdentityManager;
use Seablast\Auth\UserModel;
use Seablast\Seablast\SeablastConfiguration;
use Seablast\Seablast\SeablastConstant;
use Seablast\Seablast\Superglobals;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Tracy\Debugger;
use Tracy\ILogger;

class EmailLoginConfirmationTest extends TestCase
{
    /** @var array<mixed> */
    private $savedSession;

    /** @return IdentityManager&MockObject */
    private function createIdentity(?int $currentUserId = null, ?callable $lookup = null): IdentityManager
    {
        $identity = $this->getMockBuilder(IdentityManager::class)->disableOriginalConstructor()->getMock();
        $identity->method('isAuthenticated')->willReturn($currentUserId !== null);
        $identity->method('getUserId')->willReturn($currentUserId ?? 0);
        $identity->method('getEmail')->willReturn('alice@example.com');
        $identity->method('getIdentityForEmailToken')->willReturnCallback($lookup ?? static function () {
            return ['userId' => 7, 'email' => 'bob@example.com', 'expiresAt' => time() + 900];
        });
        $identity->expects($this->never())->method('isTokenValid');
        $identity->expects($this->never())->method('logout');
        $identity->expects($this->never())->method('doYouRememberMe');
        return $identity;
    }

    /**
     * @param array<mixed> $get
     * @param array<mixed> $post
     */
    private function createModel(IdentityManager $identity, array $get = [], array $post = []): UserModel
    {
        $configuration = new SeablastConfiguration();
        $configuration->setString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL, 'https://example.test/app');
        $model = (new ReflectionClass(UserModel::class))->newInstanceWithoutConstructor();
        foreach ([
            'configuration' => $configuration,
            'superglobals' => new Superglobals($get, $post, ['REQUEST_METHOD' => $post === [] ? 'GET' : 'POST']),
            'user' => $identity,
            'userRoute' => '/user',
        ] as $name => $value) {
            $property = new ReflectionProperty(UserModel::class, $name);
            $property->setAccessible(true);
            $property->setValue($model, $value);
        }
        return $model;
    }

    private function openConfirmation(IdentityManager $identity, string $returnUrl = '/private'): string
    {
        $result = $this->createModel($identity, ['token' => 'email-token', 'returnUrl' => $returnUrl])->knowledge();
        $this->assertSame(303, $result->httpCode);
        $this->assertIsString($result->redirectionUrl);
        $this->assertStringNotContainsString('email-token', $result->redirectionUrl);
        $query = [];
        parse_str((string) parse_url($result->redirectionUrl, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('loginConfirmation', $query);
        $this->assertIsString($query['loginConfirmation']);
        return $query['loginConfirmation'];
    }

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $this->savedSession = $_SESSION;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession;
    }

    public function testCancelPreservesAccountAndTokenAndReturnsToUserRoute(): void
    {
        foreach ([null, 3] as $currentUserId) {
            $identity = $this->createIdentity($currentUserId);
            $identity->expects($this->never())->method('loginWithEmailToken');
            $id = $this->openConfirmation($identity);
            $result = $this->createModel($identity, [], [
                'loginConfirmation' => $id,
                'loginAction' => 'cancel',
                'csrfToken' => (new CsrfTokenManager())->getToken('sb_json')->getValue(),
            ])->knowledge();
            $this->assertSame('https://example.test/app/user', $result->redirectionUrl);
            $this->assertSame(303, $result->httpCode);
        }
    }

    public function testChangedAccountRequiresOpeningLinkAgain(): void
    {
        $id = $this->openConfirmation($this->createIdentity(3));
        $changedIdentity = $this->createIdentity(4);
        $changedIdentity->expects($this->never())->method('loginWithEmailToken');
        $result = $this->createModel($changedIdentity, [], [
            'loginConfirmation' => $id,
            'loginAction' => 'confirm',
            'csrfToken' => (new CsrfTokenManager())->getToken('sb_json')->getValue(),
        ])->knowledge();
        $this->assertTrue($result->showLogout);
        $this->assertIsString($result->message);
        $this->assertStringContainsString('účet se změnil', $result->message);
    }

    public function testConfirmationFormsEscapeEmailsAndNeverExposeEmailToken(): void
    {
        foreach ([null, 3] as $currentUserId) {
            $identity = $this->createIdentity($currentUserId, static function () {
                return ['userId' => 7, 'email' => '<bob@example.com>', 'expiresAt' => time() + 900];
            });
            $id = $this->openConfirmation($identity);
            $params = $this->createModel($identity, ['loginConfirmation' => $id])->knowledge();
            $params->csrfToken = 'csrf-value';
            $html = (new Engine())->renderToString(__DIR__ . '/../views/login-confirmation.latte', (array) $params);
            $this->assertStringContainsString('&lt;bob@example.com&gt;', $html);
            $this->assertStringNotContainsString('<bob@example.com>', $html);
            $this->assertStringNotContainsString('email-token', $html);
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringContainsString('method="POST"', $html);
            $this->assertStringContainsString('action="https://example.test/app/user"', $html);
            $this->assertStringContainsString('name="csrfToken"', $html);
            $this->assertStringContainsString($currentUserId === null ? 'Zrušit' : 'Zůstat přihlášen', $html);
            $this->assertStringContainsString($currentUserId === null ? 'Přihlásit se' : 'Přepnutí účtu', $html);
            $params->configuration = new SeablastConfiguration();
            $params->configuration->flag->activate(AuthConstant::FLAG_USE_SOCIAL_LOGIN);
            $params->configuration->setString(AuthConstant::GOOGLE_CLIENT_ID, 'public-google-client');
            $control = (new Engine())->renderToString(__DIR__ . '/../views/user-control.latte', (array) $params);
            $this->assertStringContainsString('name="loginConfirmation"', $control);
            $this->assertStringNotContainsString('accounts.google.com', $control);
            $this->assertStringNotContainsString('name="email"', $control);
        }
    }

    public function testConfirmationFromAnotherSessionCannotBeSubmitted(): void
    {
        $identity = $this->createIdentity();
        $identity->expects($this->never())->method('loginWithEmailToken');
        $id = $this->openConfirmation($identity);
        $_SESSION = [];
        $result = $this->createModel($identity, [], [
            'loginConfirmation' => $id,
            'loginAction' => 'confirm',
            'csrfToken' => (new CsrfTokenManager())->getToken('sb_json')->getValue(),
        ])->knowledge();
        $this->assertTrue($result->showLogin);
        $this->assertFalse(isset($result->redirectionUrl));
    }

    public function testDatabaseFailurePreservesAuthenticationAndAllowsRetry(): void
    {
        $identity = $this->createIdentity(3);
        $identity->expects($this->once())->method('loginWithEmailToken')
            ->willThrowException(new \RuntimeException('secret-email-token'));
        $_SESSION['sbSessionToken'] = 'original-session';
        $id = $this->openConfirmation($identity);
        $logger = Debugger::getLogger();
        $safeLogger = $this->createMock(ILogger::class);
        $safeLogger->expects($this->once())->method('log')
            ->with('Email login confirmation failed: RuntimeException', ILogger::ERROR);
        Debugger::setLogger($safeLogger);
        try {
            $result = $this->createModel($identity, [], [
                'loginConfirmation' => $id,
                'loginAction' => 'confirm',
                'csrfToken' => (new CsrfTokenManager())->getToken('sb_json')->getValue(),
            ])->knowledge();
        } finally {
            Debugger::setLogger($logger);
        }
        $this->assertSame('original-session', $_SESSION['sbSessionToken']);
        $this->assertTrue($result->showLogout);
        $this->assertIsString($result->message);
        $this->assertStringNotContainsString('secret-email-token', $result->message);
        $result = $this->createModel($identity, ['loginConfirmation' => $id])->knowledge();
        $this->assertTrue($result->showLoginConfirmation);
    }

    public function testExpiredOrChangedTargetDoesNotLogOutOriginalAccount(): void
    {
        foreach ([null, ['userId' => 8, 'email' => 'other@example.com', 'expiresAt' => time() + 900]] as $next) {
            $calls = 0;
            $identity = $this->createIdentity(3, static function () use (&$calls, $next) {
                return ++$calls === 1
                    ? ['userId' => 7, 'email' => 'bob@example.com', 'expiresAt' => time() + 900] : $next;
            });
            $identity->expects($this->never())->method('loginWithEmailToken');
            $id = $this->openConfirmation($identity);
            $result = $this->createModel($identity, [], [
                'loginConfirmation' => $id,
                'loginAction' => 'confirm',
                'csrfToken' => (new CsrfTokenManager())->getToken('sb_json')->getValue(),
            ])->knowledge();
            $this->assertTrue($result->showLogout);
            $this->assertFalse($result->showLogin);
        }
    }

    public function testHeadDoesNotInspectTokensOrChangeAuthentication(): void
    {
        $identity = $this->createIdentity();
        $identity->expects($this->never())->method('isAuthenticated');
        $identity->expects($this->never())->method('getIdentityForEmailToken');
        $identity->expects($this->never())->method('loginWithEmailToken');
        $model = $this->createModel($identity, ['token' => 'email-token']);
        $property = new ReflectionProperty(UserModel::class, 'superglobals');
        $property->setAccessible(true);
        $property->setValue($model, new Superglobals(['token' => 'email-token'], [], ['REQUEST_METHOD' => 'HEAD']));
        $before = $_SESSION;
        $result = $model->knowledge();
        $this->assertFalse($result->showLogin);
        $this->assertFalse($result->showLogout);
        $this->assertSame($before, $_SESSION);
    }

    public function testMalformedAndInvalidTokensDoNotAttemptAuthentication(): void
    {
        foreach ([[], '', null, 'invalid'] as $token) {
            $identity = $this->createIdentity(null, static function () {
                return null;
            });
            $identity->expects($this->never())->method('loginWithEmailToken');
            $result = $this->createModel($identity, ['token' => $token])->knowledge();
            $this->assertTrue($result->showLogin);
            $this->assertFalse(isset($result->redirectionUrl));
        }
    }

    public function testMultipleTabsAreBoundedAndExpiredRecordsAreRemoved(): void
    {
        $identity = $this->createIdentity();
        $identity->expects($this->never())->method('loginWithEmailToken');
        $ids = [];
        for ($i = 0; $i < 11; ++$i) {
            $ids[] = $this->openConfirmation($identity);
        }
        $this->assertTrue($this->createModel($identity, ['loginConfirmation' => $ids[0]])->knowledge()->showLogin);
        $this->assertTrue(
            $this->createModel($identity, ['loginConfirmation' => $ids[1]])->knowledge()->showLoginConfirmation
        );
        foreach ($_SESSION as $key => $records) {
            if (strpos($key, 'sbAuthConfirmations:') === 0) {
                $this->assertIsArray($records);
                $this->assertCount(10, $records);
                $record = $records[$ids[1]];
                $this->assertIsArray($record);
                $record['expiresAt'] = time() - 1;
                $records[$ids[1]] = $record;
                $_SESSION[$key] = $records;
            }
        }
        $this->assertTrue($this->createModel($identity, ['loginConfirmation' => $ids[1]])->knowledge()->showLogin);
        $this->assertTrue(
            $this->createModel($identity, ['loginConfirmation' => $ids[10]])->knowledge()->showLoginConfirmation
        );
    }

    public function testOnlyValidExplicitPostCanConsumeTokenAndRenewsSessionId(): void
    {
        foreach ([null, 3] as $currentUserId) {
            $identity = $this->createIdentity($currentUserId);
            $identity->expects($this->once())->method('loginWithEmailToken')->with('email-token', 7)->willReturn(true);
            $id = $this->openConfirmation($identity, '/private?token=removed&loginConfirmation=removed&filter=open');
            $oldSessionId = session_id();
            $oldCsrf = (new CsrfTokenManager())->getToken('sb_json')->getValue();
            $result = $this->createModel($identity, [], [
                'loginConfirmation' => $id,
                'loginAction' => 'confirm',
                'csrfToken' => $oldCsrf,
            ])->knowledge();
            $this->assertSame('https://example.test/app/private?filter=open', $result->redirectionUrl);
            $this->assertSame(303, $result->httpCode);
            $this->assertNotSame($oldSessionId, session_id());
            $this->assertNotSame($oldCsrf, (new CsrfTokenManager())->getToken('sb_json')->getValue());
            $expired = $this->createModel($identity, ['loginConfirmation' => $id])->knowledge();
            $this->assertFalse(isset($expired->showLoginConfirmation));
            $this->assertSame($currentUserId !== null, $expired->showLogout);
        }
    }

    public function testRejectsMissingCsrfInvalidActionsAndMalformedIdentifiers(): void
    {
        $identity = $this->createIdentity(3);
        $identity->expects($this->never())->method('loginWithEmailToken');
        $id = $this->openConfirmation($identity);
        $csrf = (new CsrfTokenManager())->getToken('sb_json')->getValue();
        foreach ([
            ['loginConfirmation' => $id, 'loginAction' => 'confirm'],
            ['loginConfirmation' => $id, 'loginAction' => 'cancel', 'csrfToken' => 'wrong'],
            ['loginConfirmation' => $id, 'loginAction' => ['confirm'], 'csrfToken' => $csrf],
            ['loginConfirmation' => [$id], 'loginAction' => 'confirm', 'csrfToken' => $csrf],
            ['loginConfirmation' => 'unknown', 'loginAction' => 'confirm', 'csrfToken' => $csrf],
        ] as $post) {
            $result = $this->createModel($identity, [], $post)->knowledge();
            $this->assertTrue($result->showLogout);
            $this->assertFalse(isset($result->redirectionUrl));
        }
    }

    public function testRepeatedScannerGetDoesNotConsumeToken(): void
    {
        $identity = $this->createIdentity();
        $identity->expects($this->never())->method('loginWithEmailToken');
        $first = $this->openConfirmation($identity);
        $second = $this->openConfirmation($identity);
        $this->assertNotSame($first, $second);
        foreach ([$first, $first, $second] as $id) {
            $result = $this->createModel($identity, ['loginConfirmation' => $id])->knowledge();
            $this->assertTrue($result->showLoginConfirmation);
            $this->assertNull($result->currentEmail);
            $this->assertSame('bob@example.com', $result->targetEmail);
            $this->assertStringNotContainsString('email-token', serialize($result));
        }
    }
}
