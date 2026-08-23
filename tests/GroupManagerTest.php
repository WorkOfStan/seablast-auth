<?php

declare(strict_types=1);

namespace Seablast\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Seablast\Auth\GroupManager;
use Seablast\Seablast\SeablastConstant;
use Seablast\Seablast\SeablastSetup;
use Tracy\Debugger;
use Webmozart\Assert\Assert;

class GroupManagerTest extends TestCase
{
    /** @var \mysqli */
    private $mysqli;
    /** @var string */
    private $tablePrefix;
    /** @var int */
    private $userId = 0;
    /** @var int */
    private $groupId = 0;
    /** @var int[] */
    private $groupIds = [];
    /** @var GroupManager */
    private $groupManager;

    protected function setUp(): void
    {
        if (!defined('APP_DIR')) {
            define('APP_DIR', __DIR__ . '/..');
            Debugger::enable(Debugger::DEVELOPMENT, __DIR__ . '/../log');
        }

        $setup = new SeablastSetup();
        $setup->getConfiguration()->setString(SeablastConstant::SB_PHINX_ENVIRONMENT, 'testing');
        $this->mysqli = $setup->getConfiguration()->dbms();
        $this->tablePrefix = $setup->getConfiguration()->dbmsTablePrefix();

        $email = $this->mysqli->real_escape_string(
            'group-manager-' . bin2hex(random_bytes(8)) . '@example.com'
        );
        $this->assertTrue($this->mysqli->query(
            'INSERT INTO `' . $this->tablePrefix . 'users` (email, role_id) VALUES ("' . $email . '", 3);'
        ));
        $this->userId = (int) $this->mysqli->insert_id;
        $this->groupId = $this->createGroup();
        $this->groupManager = new GroupManager($this->mysqli, $this->userId, $this->tablePrefix);
    }

    protected function tearDown(): void
    {
        foreach ($this->groupIds as $groupId) {
            $this->mysqli->query(
                'DELETE FROM `' . $this->tablePrefix . 'group` WHERE id = ' . $groupId . ';'
            );
        }
        if ($this->userId > 0) {
            $this->mysqli->query(
                'DELETE FROM `' . $this->tablePrefix . 'users` WHERE id = ' . $this->userId . ';'
            );
        }
    }

    public function testAddsUnlimitedMembership(): void
    {
        $this->assertTrue($this->groupManager->addUserToGroup($this->groupId));

        $membership = $this->fetchLatestMembership($this->groupId);
        $this->assertNull($membership['valid_to']);
        $this->assertSame([$this->groupId], $this->groupManager->getGroupsByUserId());
    }

    public function testStoresExactMembershipExpiration(): void
    {
        $validToTimestamp = time() + 7217;
        $validTo = (new \DateTimeImmutable())->setTimestamp($validToTimestamp);

        $this->assertTrue($this->groupManager->addUserToGroup($this->groupId, $validTo));

        $membership = $this->fetchLatestMembership($this->groupId);
        Assert::scalar($membership['valid_to_timestamp']);
        $this->assertSame($validToTimestamp, (int) $membership['valid_to_timestamp']);
    }

    public function testRoundsEffectiveExpirationUpToNextHourAndReturnsDistinctGroups(): void
    {
        $hourStart = intdiv(time(), 3600) * 3600;
        $withinCurrentHour = (new \DateTimeImmutable())->setTimestamp($hourStart + 1);
        $exactHourBoundary = (new \DateTimeImmutable())->setTimestamp($hourStart);

        $this->assertTrue($this->groupManager->addUserToGroup($this->groupId, $withinCurrentHour));
        $this->assertTrue($this->groupManager->addUserToGroup($this->groupId, $withinCurrentHour));
        $expiredGroupId = $this->createGroup();
        $this->assertTrue($this->groupManager->addUserToGroup($expiredGroupId, $exactHourBoundary));

        $this->assertSame([$this->groupId], $this->groupManager->getGroupsByUserId());
    }

    public function testHourBucketIsStableWithinAnHour(): void
    {
        $method = new \ReflectionMethod(GroupManager::class, 'roundDownToHour');
        $method->setAccessible(true);

        $this->assertSame(3600, $method->invoke(null, 3601));
        $this->assertSame(3600, $method->invoke(null, 7199));
        $this->assertSame(7200, $method->invoke(null, 7200));
    }

    public function testTokenWithUnlimitedValidityCreatesUnlimitedMembership(): void
    {
        $token = $this->createToken(null);

        $this->assertSame(GroupManager::ACTIVATION_NEW, $this->groupManager->activateGroupByToken($token));

        $membership = $this->fetchLatestMembership($this->groupId);
        $this->assertNull($membership['valid_to']);
    }

    public function testTokenValidityDaysAreExactMultiplesOfTwentyFourHours(): void
    {
        $token = $this->createToken(2);
        $beforeActivation = $this->databaseTimestamp();

        $this->assertSame(GroupManager::ACTIVATION_NEW, $this->groupManager->activateGroupByToken($token));

        $afterActivation = $this->databaseTimestamp();
        $membership = $this->fetchLatestMembership($this->groupId);
        Assert::scalar($membership['valid_to_timestamp']);
        $validToTimestamp = (int) $membership['valid_to_timestamp'];
        $this->assertGreaterThanOrEqual($beforeActivation + (2 * 86400), $validToTimestamp);
        $this->assertLessThanOrEqual($afterActivation + (2 * 86400), $validToTimestamp);
    }

    public function testTokenWithZeroValidityDaysFails(): void
    {
        $token = $this->createToken(0);

        $this->assertSame(GroupManager::ACTIVATION_FAILED, $this->groupManager->activateGroupByToken($token));
        $this->assertSame([], $this->groupManager->getGroupsByUserId());
    }

    public function testActiveMembershipReturnsAlreadyActivated(): void
    {
        $validTo = (new \DateTimeImmutable())->setTimestamp(time() + 7200);
        $this->assertTrue($this->groupManager->addUserToGroup($this->groupId, $validTo));
        $token = $this->createToken(null);

        $this->assertSame(GroupManager::ACTIVATION_ALREADY, $this->groupManager->activateGroupByToken($token));
        $this->assertSame(1, $this->countMemberships($this->groupId));
    }

    public function testExpiredMembershipCanBeActivatedAgain(): void
    {
        $hourStart = intdiv(time(), 3600) * 3600;
        $expiredAt = (new \DateTimeImmutable())->setTimestamp($hourStart);
        $this->assertTrue($this->groupManager->addUserToGroup($this->groupId, $expiredAt));
        $token = $this->createToken(null);

        $this->assertSame(GroupManager::ACTIVATION_NEW, $this->groupManager->activateGroupByToken($token));
        $this->assertSame(2, $this->countMemberships($this->groupId));
        $this->assertSame([$this->groupId], $this->groupManager->getGroupsByUserId());
    }

    public function testTokenActivationWindowRemainsExact(): void
    {
        $futureToken = $this->createToken(null, time() + 60, time() + 3600);
        $expiredToken = $this->createToken(null, time() - 3600, time() - 1);

        $this->assertSame(
            GroupManager::ACTIVATION_WRONG_TOKEN,
            $this->groupManager->activateGroupByToken($futureToken)
        );
        $this->assertSame(
            GroupManager::ACTIVATION_WRONG_TOKEN,
            $this->groupManager->activateGroupByToken($expiredToken)
        );
        $this->assertSame([], $this->groupManager->getGroupsByUserId());
    }

    private function createGroup(): int
    {
        $name = $this->mysqli->real_escape_string('Group Manager Test ' . bin2hex(random_bytes(8)));
        $this->assertTrue($this->mysqli->query(
            'INSERT INTO `' . $this->tablePrefix . 'group` (name_public) VALUES ("' . $name . '");'
        ));
        $groupId = (int) $this->mysqli->insert_id;
        $this->groupIds[] = $groupId;
        return $groupId;
    }

    private function createToken(?int $validForDays, ?int $validFrom = null, ?int $validTo = null): string
    {
        $token = 'group-token-' . bin2hex(random_bytes(8));
        $escapedToken = $this->mysqli->real_escape_string($token);
        $validFrom = $validFrom ?? time() - 60;
        $validTo = $validTo ?? time() + 3600;
        $validForDaysSql = $validForDays === null ? 'NULL' : (string) $validForDays;
        $this->assertTrue($this->mysqli->query(
            'INSERT INTO `' . $this->tablePrefix
            . 'group_activation_tokens` (group_id, valid_from, valid_to, valid_for_days, token) VALUES ('
            . $this->groupId . ', FROM_UNIXTIME(' . $validFrom . '), FROM_UNIXTIME(' . $validTo . '), '
            . $validForDaysSql . ', "' . $escapedToken . '");'
        ));
        return $token;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchLatestMembership(int $groupId): array
    {
        $result = $this->mysqli->query(
            'SELECT valid_to, UNIX_TIMESTAMP(valid_to) AS valid_to_timestamp FROM `' . $this->tablePrefix
            . 'user_group` WHERE user_id = ' . $this->userId . ' AND group_id = ' . $groupId
            . ' ORDER BY id DESC LIMIT 1;'
        );
        $this->assertInstanceOf(\mysqli_result::class, $result);
        $membership = $result->fetch_assoc();
        $this->assertIsArray($membership);
        return $membership;
    }

    private function countMemberships(int $groupId): int
    {
        $result = $this->mysqli->query(
            'SELECT COUNT(*) AS membership_count FROM `' . $this->tablePrefix . 'user_group` WHERE user_id = '
            . $this->userId . ' AND group_id = ' . $groupId . ';'
        );
        $this->assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        $this->assertIsArray($row);
        return (int) $row['membership_count'];
    }

    private function databaseTimestamp(): int
    {
        $result = $this->mysqli->query('SELECT UNIX_TIMESTAMP() AS current_epoch;');
        $this->assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        $this->assertIsArray($row);
        return (int) $row['current_epoch'];
    }
}
