<?php

declare(strict_types=1);

namespace Seablast\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Seablast\Auth\IdentityManager;
use Seablast\Seablast\SeablastConstant;
use Seablast\Seablast\SeablastSetup;
use Tracy\Debugger;
use Tracy\ILogger;

class EmailTokenTransactionTest extends TestCase
{
    /** @var \mysqli */
    private $mysqli;
    /** @var array<mixed> */
    private $savedCookies;
    /** @var array<mixed> */
    private $savedServer;
    /** @var array<mixed> */
    private $savedSession;
    /** @var string */
    private $tablePrefix;
    /** @var IdentityManager */
    private $user;

    /** @return array{email: string, id: int, token: string} */
    private function createLogin(): array
    {
        $email = 'confirmation-' . bin2hex(random_bytes(8)) . '@example.test';
        $token = $this->user->login($email);
        $identity = $this->user->getIdentityForEmailToken($token);
        $this->assertIsArray($identity);
        return ['email' => $email, 'id' => $identity['userId'], 'token' => $token];
    }

    /** @return array<string, mixed> */
    private function fetchRow(string $sql): array
    {
        $result = $this->mysqli->query($sql);
        $this->assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        $this->assertIsArray($row);
        return $row;
    }

    protected function setUp(): void
    {
        if (!defined('APP_DIR')) {
            define('APP_DIR', __DIR__ . '/..');
            Debugger::enable(Debugger::DEVELOPMENT, __DIR__ . '/../log');
        }
        $configuration = (new SeablastSetup())->getConfiguration();
        $configuration->setString(SeablastConstant::SB_PHINX_ENVIRONMENT, 'testing');
        $this->mysqli = $configuration->mysqli();
        $basePrefix = $configuration->dbmsTablePrefix();
        $this->tablePrefix = $basePrefix . 'confirmation_' . bin2hex(random_bytes(4)) . '_';
        foreach (['users', 'email_token', 'session_user'] as $table) {
            // isolated tables are created for testing
            $this->assertTrue($this->mysqli->query('CREATE TABLE `' . $this->tablePrefix . $table
                . '` LIKE `' . $basePrefix . $table . '`'));
        }
        $this->user = new IdentityManager($this->mysqli);
        $this->user->setTablePrefix($this->tablePrefix);
        $this->user->setRememberMeCookieEnabled(false);
        $this->savedSession = $_SESSION ?? [];
        $this->savedCookies = $_COOKIE;
        $this->savedServer = $_SERVER;
        $_SESSION = [];
        $_COOKIE = [];
        unset($_SERVER['HTTPS'], $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['SERVER_PORT'] = '80';
    }

    protected function tearDown(): void
    {
        foreach (['email_token', 'session_user', 'users'] as $table) {
            // isolated tables created for testing are dropped
            $this->mysqli->query('DROP TABLE `' . $this->tablePrefix . $table . '`');
        }
        $_COOKIE = $this->savedCookies;
        $_SERVER = $this->savedServer;
        $_SESSION = $this->savedSession;
    }

    public function testConcurrentConsumersCanOnlyUseTokenOnce(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('Concurrent login test requires proc_open.');
        }
        $login = $this->createLogin();
        $program = <<<'PHP'
require 'vendor/autoload.php';
define('APP_DIR', getcwd());
$configuration = (new \Seablast\Seablast\SeablastSetup())->getConfiguration();
$configuration->setString(\Seablast\Seablast\SeablastConstant::SB_PHINX_ENVIRONMENT, 'testing');
$user = new \Seablast\Auth\IdentityManager($configuration->mysqli());
$request = json_decode(stream_get_contents(STDIN), true);
if (is_array($request) === false || is_string($request['prefix']) === false
    || is_string($request['token']) === false) { exit(2); }
$user->setTablePrefix($request['prefix']);
$user->setRememberMeCookieEnabled(false);
echo $user->loginWithEmailToken($request['token']) ? 'accepted' : 'rejected';
PHP;
        $processes = [];
        for ($i = 0; $i < 2; ++$i) {
            $pipes = [];
            $process = proc_open(escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=off -r ' . escapeshellarg($program), [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        try {
            foreach ($processes as [$process, $pipes]) {
                $request = json_encode(['token' => $login['token'], 'prefix' => $this->tablePrefix]);
                $this->assertIsString($request);
                fwrite($pipes[0], $request);
                fclose($pipes[0]);
            }
            foreach ($processes as [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), (string) $errors);
            }
        } finally {
            foreach ($processes as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertNull($this->user->getIdentityForEmailToken($login['token']));
    }

    public function testDatabaseFailureRollsBackConsumptionAndKeepsOriginalAccount(): void
    {
        $original = $this->createLogin();
        $this->assertTrue($this->user->loginWithEmailToken($original['token']));
        $session = $_SESSION['sbSessionToken'];
        $target = $this->createLogin();
        $logger = Debugger::getLogger();
        Debugger::setLogger($this->createMock(ILogger::class));
        try {
            // Alter only the isolated fixture table to fail after token deletion and the last_login update.
            $this->assertTrue($this->mysqli->query(
                "ALTER TABLE `{$this->tablePrefix}session_user` DROP COLUMN updated"
            ));
            try {
                $this->user->loginWithEmailToken($target['token'], $target['id']);
                $this->fail('A failed session-token insert must abort login.');
            } catch (\Exception $exception) {
                $this->assertStringContainsString('updated', $exception->getMessage());
            }
        } finally {
            $this->assertTrue($this->mysqli->query("ALTER TABLE `{$this->tablePrefix}session_user`"
                . ' ADD COLUMN updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'));
            Debugger::setLogger($logger);
        }
        $this->assertSame($session, $_SESSION['sbSessionToken']);
        $this->assertTrue($this->user->isAuthenticated());
        $this->assertSame($original['id'], $this->user->getUserId());
        $this->assertNotNull($this->user->getIdentityForEmailToken($target['token']));
        $row = $this->fetchRow("SELECT last_login FROM `{$this->tablePrefix}users` WHERE id = " . $target['id']);
        $this->assertNull($row['last_login']);
        $this->assertTrue($this->user->loginWithEmailToken($target['token'], $target['id']));
    }

    public function testHttpsLoginCreatesNewRememberMeRecordsAfterSwitch(): void
    {
        $original = $this->createLogin();
        $this->assertTrue($this->user->loginWithEmailToken($original['token']));
        $_SERVER['HTTPS'] = 'on';
        $this->user->setRememberMeCookieEnabled(true);
        $target = $this->createLogin();
        $this->assertTrue($this->user->loginWithEmailToken($target['token'], $target['id']));
        $row = $this->fetchRow("SELECT COUNT(*) AS total FROM `{$this->tablePrefix}session_user` WHERE user_id = "
            . $target['id']);
        $this->assertEquals(2, $row['total']);
        $row = $this->fetchRow("SELECT COUNT(*) AS total FROM `{$this->tablePrefix}session_user` WHERE user_id = "
            . $original['id']);
        $this->assertEquals(0, $row['total']);
    }

    public function testInspectionDoesNotConsumeTokenOrRefreshItsLifetime(): void
    {
        $login = $this->createLogin();
        $this->mysqli->query("UPDATE `{$this->tablePrefix}email_token` SET created = NOW() - INTERVAL 14 MINUTE"
            . " WHERE email = '" . $this->mysqli->real_escape_string($login['email']) . "'");
        $first = $this->user->getIdentityForEmailToken($login['token']);
        $second = $this->user->getIdentityForEmailToken($login['token']);
        $this->assertSame($first, $second);
        $this->assertIsArray($first);
        $this->assertLessThanOrEqual(time() + 61, $first['expiresAt']);
        $this->assertFalse($this->user->isAuthenticated());
        $row = $this->fetchRow("SELECT last_login FROM `{$this->tablePrefix}users` WHERE id = " . $login['id']);
        $this->assertNull($row['last_login']);
        $this->mysqli->query("UPDATE `{$this->tablePrefix}email_token` SET created = NOW() - INTERVAL 16 MINUTE"
            . " WHERE email = '" . $this->mysqli->real_escape_string($login['email']) . "'");
        $this->assertNull($this->user->getIdentityForEmailToken($login['token']));
        $this->assertFalse($this->user->loginWithEmailToken($login['token']));
    }

    public function testSwitchRevokesOnlyBrowserTokensAndChecksExpectedUser(): void
    {
        $original = $this->createLogin();
        $this->assertTrue($this->user->loginWithEmailToken($original['token']));
        $oldSession = $_SESSION['sbSessionToken'];
        $oldRemember = bin2hex(random_bytes(16));
        $otherDevice = bin2hex(random_bytes(16));
        foreach ([$oldRemember, $otherDevice] as $token) {
            $this->mysqli->query("INSERT INTO `{$this->tablePrefix}session_user` (user_id, token, updated) VALUES ("
                . $original['id'] . ", '" . hash('sha256', $token) . "', NOW())");
        }
        $_COOKIE['sbRememberMe'] = $oldRemember;
        $target = $this->createLogin();
        $this->assertFalse($this->user->loginWithEmailToken($target['token'], $original['id']));
        $this->assertSame($oldSession, $_SESSION['sbSessionToken']);
        $this->assertNotNull($this->user->getIdentityForEmailToken($target['token']));
        $this->assertTrue($this->user->loginWithEmailToken($target['token'], $target['id']));
        $this->assertSame($target['id'], $this->user->getUserId());
        $this->assertNotSame($oldSession, $_SESSION['sbSessionToken']);
        $row = $this->fetchRow("SELECT COUNT(*) AS total FROM `{$this->tablePrefix}session_user` WHERE user_id = "
            . $original['id']);
        $this->assertEquals(1, $row['total']);
        $row = $this->fetchRow(
            "SELECT token FROM `{$this->tablePrefix}session_user` WHERE user_id = " . $original['id']
        );
        $this->assertSame(hash('sha256', $otherDevice), $row['token']);
        $this->assertNull($this->user->getIdentityForEmailToken($target['token']));
    }
}
