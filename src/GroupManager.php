<?php

declare(strict_types=1);

namespace Seablast\Auth;

use Tracy\Debugger;
use Webmozart\Assert\Assert;

/**
 * Class to manipulate groups, to which a user may belong to.
 *
 * Phinx migrations will create following tables
 * - group: id, created, name_public, internal_notes
 * - user_group: id,created,user_id (foreign id),group_id (foreign id)
 * - group_activation_tokens: id,created,group_id (foreign id),valid_from,valid_to,token
 *
 */
class GroupManager
{
    use \Nette\SmartObject;

    /** @var \mysqli */
    private $dbms;
    /** @var string */
    private $tablePrefix;
    /** @var int */
    private $userId;

    public const ACTIVATION_WRONG_TOKEN = 401; // 'wrong_token';
    public const ACTIVATION_ALREADY = 304; // 'already_activated';
    public const ACTIVATION_NEW = 200; // 'new_activation';
    public const ACTIVATION_FAILED = 500; // 'activation_failed';

    /**
     * @param \mysqli $dbms
     * @param int $userId
     * @param string $tablePrefix
     */
    public function __construct(\mysqli $dbms, int $userId, string $tablePrefix = '')
    {
        $this->dbms = $dbms;
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
     * @param string $token
     * @return int self::ACTIVATION constant mimicking the HTTP response codes
     */
    public function activateGroupByToken(string $token): int
    {
        // Check token validity
        $resultToken = $this->dbms->query('SELECT * FROM `' . $this->tablePrefix
            . 'group_activation_tokens` WHERE token = "' . $this->dbms->real_escape_string($token)
            . '" AND valid_from <= NOW() AND valid_to >= NOW();');
        if (is_bool($resultToken)) {
            throw new \Exception('Db expected.');
        }
        $tokenData = $resultToken->fetch_assoc(); // fetch first row, it should be the only one, anyway
        Debugger::barDump($tokenData, 'tokenData');
        if (!$tokenData) {
            return self::ACTIVATION_WRONG_TOKEN;
        }

        // Check if already activated
        $resultUserGroup = $this->dbms->query('SELECT * FROM `' . $this->tablePrefix . 'user_group` WHERE user_id = '
            . (int) $this->userId . ' AND group_id = ' . (int) $tokenData['group_id'] . ';');
        if (is_bool($resultUserGroup)) {
            throw new \Exception('Db expected.');
        }
        return ($resultUserGroup->fetch_row()) ? self::ACTIVATION_ALREADY :
            // Activate
            ($this->addUserToGroup((int) $tokenData['group_id']) ? self::ACTIVATION_NEW : self::ACTIVATION_FAILED);
    }

    /**
     * Adds user to group. Returns true on success, false on failure.
     *
     * Intended to be called by a payment API or during an activation code procedure.
     *
     * @param int $groupId
     * @return bool
     */
    public function addUserToGroup(int $groupId): bool
    {
        return (bool) $this->dbms->query(
            'INSERT INTO `' . $this->tablePrefix . 'user_group` (created, user_id, group_id) VALUES (NOW(), '
            . (int) $this->userId . ', ' . (int) $groupId . ');'
        );
    }

    /**
     * Return the list of groups to which user belong. It may be empty.
     *
     * Called typically during authentication.
     *
     * @return int[]
     */
    public function getGroupsByUserId(): array
    {
        $result = $this->dbms->query(
            'SELECT ug.group_id FROM `' . $this->tablePrefix . 'group` g INNER JOIN `' . $this->tablePrefix
            . 'user_group` ug ON g.id = ug.group_id  WHERE ug.user_id = ' . (int) $this->userId . ';'
        ); // TODO maybe just `WHERE user_id` would be sufficient. Or I want group name as well here?
        if (is_bool($result)) {
            throw new \Exception('Db expected.');
        }
        // Transform to int[]
        $groups = [];
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $groups[] = (int) $row['group_id'];
        }
        return $groups;
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
            $this->dbms->query(
                'DELETE FROM `' . $this->tablePrefix . 'user_group` WHERE user_id = '
                . (int) $this->userId . '  AND group_id = ' . (int) $groupId . ';'
            )
        );
    }
}
