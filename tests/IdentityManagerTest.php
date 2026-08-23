<?php

namespace Seablast\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Seablast\Auth\IdentityManager;
use Seablast\Seablast\SeablastSetup;
use Seablast\Seablast\SeablastConstant;
use Tracy\Debugger;

/**
 * Todo later: isAuthenticated and logout expect session and cookie handling
 */
class IdentityManagerTest extends TestCase
{
    /** @var \mysqli */
    private $mysqli;
    /** @var string */
    private $tablePrefix;
    /** @var IdentityManager */
    protected $user;

    protected function setUp(): void
    {
        // set this up only once
        if (!defined('APP_DIR')) {
            error_reporting(E_ALL); // incl E_NOTICE
            define('APP_DIR', __DIR__ . '/..'); // APP_DIR is expected by SeablastSetup
            Debugger::enable(Debugger::DEVELOPMENT, __DIR__ . '/../log');
        }
        $setup = new SeablastSetup(); // combine configuration files into a valid configuration
        $setup->getConfiguration()->setString(SeablastConstant::SB_PHINX_ENVIRONMENT, 'testing');
        $this->mysqli = $setup->getConfiguration()->dbms();
        $this->tablePrefix = $setup->getConfiguration()->dbmsTablePrefix();
        $this->user = new IdentityManager($this->mysqli);
        $this->user->setTablePrefix($this->tablePrefix);
    }

    public function testSqlInjection(): void
    {
        // Insert a harmless SQL injection attempt
        $sqlInjectionString = "invalid-email'; SELECT * FROM foobar WHERE '1' = '1";
        try {
            $token = $this->user->login($sqlInjectionString);

            $this->fail('SQL injection attempt was not interrupted by an exception.');
            // For invalid email, no token should be generated
            //$this->assertFalse($this->user->isTokenValid($token), 'For invalid email, no token should be generated');
        } catch (\Webmozart\Assert\InvalidArgumentException $e) { //TODO even more specific exception
            // If an exception is thrown, it means there was an error
            //$this->fail('SQL injection attempt caused an exception: ' . $e->getMessage() . ' - ' . get_class($e));
            // There should be an exception if $sqlInjectionString is not a valid email
            $this->assertNotSame($sqlInjectionString, filter_var($sqlInjectionString, FILTER_VALIDATE_EMAIL));
        }
    }

    public function testInsertValidEmail(): void
    {
        // Generate a random email address
        $randomEmail = 'test-user-' . rand(1, 1000) . '@dadastrip.com';

        // All is ok. Send the login email.
        $token = $this->user->login($randomEmail);

        $escapedEmail = $this->mysqli->real_escape_string($randomEmail);
        $result = $this->mysqli->query(
            "SELECT token FROM `{$this->tablePrefix}email_token` WHERE email = '" . $escapedEmail . "' LIMIT 1;"
        );
        $this->assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        $this->assertIsArray($row);
        $this->assertSame(hash('sha256', $token), $row['token']);
        $this->assertNotSame($token, $row['token']);

        // Token should be valid
        $this->assertTrue($this->user->isTokenValid($token), 'Token should be valid');

        // But only once
        $this->assertFalse($this->user->isTokenValid($token), 'Token should no longer be valid');
    }

    public function testEmptyEmail(): void
    {
        // Insert a harmless SQL injection attempt
        $invalidEmailString = "";
        try {
            $token = $this->user->login($invalidEmailString);

            $this->fail('Empty email entry attempt was not interrupted by an exception.');
            // For invalid email, no token should be generated
            //$this->assertFalse($this->user->isTokenValid($token), 'For invalid email, no token should be generated');
        } catch (\Webmozart\Assert\InvalidArgumentException $e) { //TODO even more specific exception
            // If an exception is thrown, it means there was an error
            //$this->fail('SQL injection attempt caused an exception: ' . $e->getMessage() . ' - ' . get_class($e));
            // There should be an exception if $sqlInjectionString is not a valid email
            $this->assertNotSame($invalidEmailString, filter_var($invalidEmailString, FILTER_VALIDATE_EMAIL));
        }
    }

    public function testInvalidEmail(): void
    {
        // Insert a harmless SQL injection attempt
        $invalidEmailString = "invalid-email@g";
        try {
            $token = $this->user->login($invalidEmailString);

            $this->fail('Empty email entry attempt was not interrupted by an exception.');
            // For invalid email, no token should be generated
            //$this->assertFalse($this->user->isTokenValid($token), 'For invalid email, no token should be generated');
        } catch (\Webmozart\Assert\InvalidArgumentException $e) { //TODO even more specific exception
            // If an exception is thrown, it means there was an error
            //$this->fail('SQL injection attempt caused an exception: ' . $e->getMessage() . ' - ' . get_class($e));
            // There should be an exception if $sqlInjectionString is not a valid email
            $this->assertNotSame($invalidEmailString, filter_var($invalidEmailString, FILTER_VALIDATE_EMAIL));
        }
    }

    public function testRejectsUnsafeTablePrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->user->setTablePrefix('bad`prefix');
    }
}
