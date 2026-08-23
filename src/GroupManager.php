<?php

declare(strict_types=1);

namespace Seablast\Auth;

use Seablast\Auth\Exceptions\DbmsException;
use Tracy\Debugger;
use Webmozart\Assert\Assert;

/**
 * Class to manipulate groups, to which a user may belong to.
 *
 * Phinx migrations will create following tables
 * - group: id, created, name_public, internal_notes
 * - user_group: id,created,user_id (foreign id),group_id (foreign id),valid_to
 * - group_activation_tokens: id,created,group_id (foreign id),valid_from,valid_to,valid_for_days,token
 */
class GroupManager
{
    use \Nette\SmartObject;

    /** @var \mysqli */
    private $mysqli;
    /** @var string */
    private $tablePrefix;
    /** @var int */
    private $userId;

    public const ACTIVATION_ALREADY = 304; // already_activated
    public const ACTIVATION_FAILED = 500; // activation_failed
    public const ACTIVATION_NEW = 200; // new_activation
    public const ACTIVATION_WRONG_TOKEN = 401; // wrong_token

    /**
     * @param \mysqli $mysqli
     * @param int $userId
     * @param string $tablePrefix
     */
    public function __construct(\mysqli $mysqli, int $userId, string $tablePrefix = '')
    {
        if (!preg_match('/\A[A-Za-z0-9_]*\z/', $tablePrefix)) {
            throw new \InvalidArgumentException('Database table prefix contains unsupported characters.');
        }
        $this->mysqli = $mysqli;
        $this->tablePrefix = $tablePrefix;
        $this->userId = $userId;
    }

    /**
     * Add user to a group according to activation code within its validity time window.
     *
     * So that an API can be called to assign a user to a group. Such apiGroupActivation (token) with user_id can have
     * following responses:
     *  - wrong token / already activated / new activation
     *
     * Token comparison is intentionally case-insensitive.
     *
     * @param string $token
     * @return int self::ACTIVATION constant mimicking the HTTP response codes
     * @throws DbmsException on database statement error
     */
    public function activateGroupByToken(string $token): int
    {
        // Check token validity
        // Compare token case-insensitively to avoid issues with letter case.
        // Using LOWER() on both sides is portable across MySQL/MariaDB.
        $escapedToken = $this->mysqli->real_escape_string($token);
        $resultToken = $this->mysqli->query('SELECT * FROM `' . $this->tablePrefix
            . 'group_activation_tokens` WHERE LOWER(token) = LOWER("' . $escapedToken
            . '") AND valid_from <= NOW() AND valid_to >= NOW() LIMIT 1;');
        if ($resultToken === false) {
            throw new DbmsException('Db expected.');
        }
        // Ensure static analyzers know we have a mysqli_result here
        Assert::isInstanceOf($resultToken, \mysqli_result::class);
        $tokenData = $resultToken->fetch_assoc(); // fetch first row
        // Return wrong token status when not found.
        if (!$tokenData) {
            return self::ACTIVATION_WRONG_TOKEN;
        }

        $membershipValidTo = null;
        if ($tokenData['valid_for_days'] !== null) {
            $rawValidForDays = $tokenData['valid_for_days'];
            if (
                (!is_string($rawValidForDays) && !is_int($rawValidForDays))
                || !preg_match('/\A[1-9][0-9]*\z/', (string) $rawValidForDays)
            ) {
                return self::ACTIVATION_FAILED;
            }
            $membershipValidTo = (new \DateTimeImmutable())->setTimestamp(
                time() + ((int) $rawValidForDays * 86400)
            );
        }

        // Check if already activated and still valid.
        $membershipHourStart = self::roundDownToHour(time());
        $resultUserGroup = $this->mysqli->query('SELECT * FROM `' . $this->tablePrefix . 'user_group` WHERE user_id = '
            . (int) $this->userId . ' AND group_id = ' . (int) $tokenData['group_id']
            . ' AND (valid_to IS NULL OR valid_to > FROM_UNIXTIME(' . $membershipHourStart . ')) LIMIT 1;');
        if (is_bool($resultUserGroup)) {
            throw new \Exception('Db expected.');
        }
        return ($resultUserGroup->fetch_row()) ? self::ACTIVATION_ALREADY :
            // Activate
            ($this->addUserToGroup((int) $tokenData['group_id'], $membershipValidTo)
                ? self::ACTIVATION_NEW : self::ACTIVATION_FAILED);
    }

    /**
     * Adds user to group. Returns true on success, false on failure.
     *
     * Intended to be called by a payment API or during an activation code procedure.
     *
     * @param int $groupId
     * @param \DateTimeInterface|null $validTo Exact expiration time; null means unlimited membership.
     * @return bool
     */
    public function addUserToGroup(int $groupId, ?\DateTimeInterface $validTo = null): bool
    {
        $validToSql = $validTo === null
            ? 'NULL'
            : 'FROM_UNIXTIME(' . $validTo->getTimestamp() . ')';
        return (bool) $this->mysqli->query(
            'INSERT INTO `' . $this->tablePrefix
            . 'user_group` (created, user_id, group_id, valid_to) VALUES (NOW(), '
            . (int) $this->userId . ', ' . (int) $groupId . ', ' . $validToSql . ');'
        );
    }

    /**
     * Return the list of groups to which user belong. It may be empty.
     *
     * Called typically during authentication.
     *
     * @return int[]
     * @throws DbmsException on database statement error
     */
    public function getGroupsByUserId(): array
    {
        $membershipHourStart = self::roundDownToHour(time());
        $result = $this->mysqli->query(
            'SELECT DISTINCT ug.group_id FROM `' . $this->tablePrefix . 'group` g INNER JOIN `'
            . $this->tablePrefix . 'user_group` ug ON g.id = ug.group_id WHERE ug.user_id = '
            . (int) $this->userId . ' AND (ug.valid_to IS NULL OR ug.valid_to > FROM_UNIXTIME('
            . $membershipHourStart . '));'
        ); // TODO maybe just `WHERE user_id` would be sufficient. Or I want group name as well here?
        if (is_bool($result)) {
            throw new DbmsException('Db expected.');
        }
        // Transform to int[]
        $groups = [];
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            Assert::scalar($row['group_id']);
            $groups[] = (int) $row['group_id'];
        }
        return $groups;
    }

    /**
     * Return the start of the hour containing the supplied Unix timestamp.
     *
     * @param int $timestamp
     * @return int
     */
    private static function roundDownToHour(int $timestamp): int
    {
        return intdiv($timestamp, 3600) * 3600;
    }

    /**
     * Remove user from a group. If failed, throw an Exception.
     *
     * Intended as an admin action only.
     *
     * @param int $groupId
     * @return void
     */
    public function removeUserFromGroup(int $groupId): void
    {
        Assert::true(
            $this->mysqli->query(
                'DELETE FROM `' . $this->tablePrefix . 'user_group` WHERE user_id = '
                . (int) $this->userId . '  AND group_id = ' . (int) $groupId . ';'
            )
        );
    }
}
