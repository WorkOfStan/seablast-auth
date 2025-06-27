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
        $this->user = new IdentityManager($this->mysqli);
        $this->user->setTablePrefix($setup->getConfiguration()->dbmsTablePrefix());
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
}
