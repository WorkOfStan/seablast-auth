<?php

declare(strict_types=1);

namespace Seablast/Auth;

use DateTime;
use Seablast\Seablast\IdentityManagerInterface;
use Tracy\Debugger;

/**
 * MIT License

 TODO add `
 Todo replace p_ by prefix
 *
 * Call setTablePrefix injection, if prefix is used.
 *
 * Note: Timestamps and Timezones: Ensure that your PHP and MySQL timezones are properly set,
 * as the code uses CURRENT_TIMESTAMP for time-related operations.
 * TODO: not just (string) type casting but also escapeSQL against SQL injection
 * TODO: test intervals and refactor code
 */
class IdentityManager implements IdentityManagerInterface
{
    use \Nette\SmartObject;

    /** @var string */
    private $cookieDomain;
    /** @var string */
    private $cookiePath;
    /** @var \mysqli */
    private $dbms;
    /** @var string */
    private $email;
    /** @var bool */
    private $isAuthenticated = false;
    /** @var ?bool whether the user trying to authenticate is a new user */
    private $isNewUser = null;
    /** @var int */
    private $roleId;
    /** @var string */
    private $tablePrefix = '';
    /** @var string */
    private $token;
    /** @var int */
    private $userId;

    /**
     * @param \mysqli $dbms
     */
    public function __construct(\mysqli $dbms)
    {
        $this->dbms = $dbms;
        $this->cookiePath = '/'; // todo limit
        $this->cookieDomain = ''; // todo extract
    }

    /**
     * TODO consider insert also type (short session, long remember me) for selective purge
     * @param int $userId
     * @return void
     */
    private function createSessionId(int $userId): void
    {
        // insert uniqid to the sessionId field and userId into userId field of the session_user table
        $sessionId = uniqid('', true); // todo maybe also generateToken()???
        $rememberMeToken = $this->generateToken();
        $this->dbms->query('INSERT INTO ' . $this->tablePrefix . 'session_user (user_id, token, updated) VALUES (' . (int) $userId
            . ', "' . (string) $sessionId . '", CURRENT_TIMESTAMP), (' . (int) $userId
            . ', "' . (string) $rememberMeToken . '", CURRENT_TIMESTAMP);'); // todo assert insert doesn't fail
        $_SESSION['sbSessionToken'] = $sessionId;
        // todo if not flag allow Remember Me; then return;
        // Create relogin cookie which expires in 30 days
        $expireTime = 30 * 24 * 60 * 60; // days * hours * minutes * seconds
        // todo consider setcookie method, so that all parameters are the same when creating and deleting
        setcookie(
            'sbRememberMe',
            $rememberMeToken,
            time() + $expireTime,
            $this->cookiePath,
            $this->cookieDomain,
            true,
            true
        );
        // Set a long-lived cookie for HTTPS only
    }

    /**
     * Check whether RememberMe cookie fits.
     *
     * @param array<string> $cookie
     * @return bool
     */
    public function doYouRememberMe(array $cookie): bool
    {
        // Check if the "Remember Me" cookie exists
        if (!isset($cookie['sbRememberMe'])) {
            return false;
        }
        // Retrieve the token from the cookie
        $userId = $this->getUserForSessionId((string) $cookie['sbRememberMe'], 30);
        if (is_null($userId)) {
            return false;
        }
        // delete the old cookie id from protokronika_session_user as new one will be set in createSessionId anyway
        // TODO escape SQL (string) $cookie['sbRememberMe']
        $this->dbms->query('DELETE FROM ' . $this->tablePrefix . 'session_user WHERE `user_id` = ' . $userId . ' AND `token` = "'
            . (string) $cookie['sbRememberMe'] . '";');
        $this->createSessionId($userId); // incidentally also updates the RM cookie
        return true;
    }

    /**
     * Fetch only the first row.
     *
     * @param string $query
     * @return array<scalar>|null
     */
    private function fetchFirstRow(string $query): ?array
    {
        $result = $this->dbms->query($query);
        return is_bool($result) ? null : ( $result->fetch_assoc() ?? null);
    }

    /**
     * Child class may redefine what happens after the first login
     * I.e. promo group etc.
     * (TODO: welcome email should come after first confirmed login)
     *
     * @param ?int $userId
     * @return void
     */
    protected function firstUnconfirmedLogin(?int $userId = null): void
    {
        // Create the root item
        $this->dbms->query('INSERT INTO `' . $this->tablePrefix . 'items` (`owner_id`) VALUES ('
            . ($userId ?? $this->getUserId()) . ");");
        // TODO check whether removing unused users removes also these unused elements
    }

    /**
     * Generate a unique token for the user session.
     *
     * TODO next phase - CSRF token method used
     *
     * @return string
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Return the user's email.
     *
     * @return string
     */
    public function getEmail(): string
    {
        if (empty($this->email)) {
            throw new \Exception('You should first check the existence of User.');
        }
        return $this->email;
    }

    /**
     * Return the list of groups to which user belong. It may be empty.
     *
     * Implementation of Seablast\Seablast\IdentityManagerInterface.
     *
     * @return int[]
     */
    public function getGroups(): array
    {
        $groups = new GroupManager($this->dbms, $this->userId, $this->tablePrefix);
        return $groups->getGroupsByUserId();
    }

    /**
     * Return the id of user's role.
     *
     * Implementation of Seablast\Seablast\IdentityManagerInterface.
     *
     * @return int
     */
    public function getRoleId(): int
    {
        if (empty($this->roleId)) {
            throw new \Exception('You should first check the existence of User.'); // todo check it here?
        }
        return $this->roleId;
    }

    /**
     * Return the user's id.
     *
     * Implementation of Seablast\Seablast\IdentityManagerInterface.
     *
     * @return int
     */
    public function getUserId(): int
    {
        if (empty($this->userId)) {
            throw new \Exception('You should first check the existence of User.');
        }
        return $this->userId;
    }

    /**
     * Select userId from session_user where $sessionId and not older than 15 minutes. Otherwise return null.
     *
     * @param string $sessionToken
     * @param int $days to respect validity of a saved token
     * @return ?int userId (null = no match)
     */
    private function getUserForSessionId(string $sessionToken, int $days = 1): ?int
    {
        // todo escapeSql $sessionToken
        // It logs you out after 1 day of non-usage
        //$row = $this->fetchFirstRow('SELECT user_id, updated FROM protokronika_session_user WHERE token = "'
        //    . (string) $sessionToken . '" AND updated > (NOW() - INTERVAL ' . $days . ' DAY);');
        // TODO precalculate NOW() - INTERVAL and round it to 30 minutes in order to cache the SQL responses
        // Calculate 1 day from now in PHP
        $oneDayTillNow = new DateTime('-' . $days . ' day');
        // Regardless of rounding up, reset minutes (and seconds) to 0
        $oneDayTillNow->setTime((int) $oneDayTillNow->format('H'), 0, 0);
        $pastDate = $oneDayTillNow->format('Y-m-d H:i:s');
        Debugger::barDump($pastDate, 'Past date'); // debug
        $row = $this->fetchFirstRow('SELECT user_id, updated FROM ' . $this->tablePrefix . 'session_user WHERE token = "'
            . (string) $sessionToken . '" AND updated > "' . $pastDate . '";');
        //Debugger::barDump($row2, 'static date');
        // todo if row2 returns the same as for row, use the precalculated rounded statement instead of now()
        if (is_null($row)) {
            return null;
        }
        // Update last access
        // TODO prolongate session only if the previous access is older than 5 minutes to reduce SQL load
        Debugger::barDump($row, 'User for session');
        $this->dbms->query('UPDATE ' . $this->tablePrefix . 'session_user SET updated = CURRENT_TIMESTAMP WHERE token = "'
            . (string) $sessionToken . '";');
        return (int) $row['user_id'];
    }

    /**
     * Determine whether the user is authenticated.
     *
     * Implementation of Seablast\Seablast\IdentityManagerInterface.
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        $sessionId = $_SESSION['sbSessionToken'] ?? null;
        if (is_null($sessionId)) {
            // todo doYouRememberMe?
            $this->isAuthenticated = false;
        } else {
            $userId = $this->getUserForSessionId($sessionId);
            if (is_null($userId)) {
                $this->isAuthenticated = false;
            } else {
                $this->isAuthenticated = true;
                $this->populateUserById($userId);
            }
        }
        return $this->isAuthenticated;
    }

    /**
     * Determine whether the user trying to authenticate is a new user.
     *
     * @return bool
     */
    public function isNewUser(): bool
    {
        if (is_null($this->isNewUser)) {
            throw new \Exception('isNewUser should not be called in this moment.');
        }
        return (bool) $this->isNewUser;
    }

    /**
     * Validate the email token and if successful, populate the User.
     *
     * Check for sessionToken as well to force login to the same environment.
     *
     * @param string $emailToken
     * @return bool
     */
    public function isTokenValid(string $emailToken): bool
    {
        $row = $this->fetchFirstRow(
            'SELECT id,email FROM ' . $this->tablePrefix . 'email_token WHERE token = "' . (string) $emailToken
            . '"  AND created > (NOW() - INTERVAL 15 MINUTE);'
        );
        if (is_null($row)) {
            return false;
        }
        // Token is one time only
        $this->dbms->query('DELETE FROM ' . $this->tablePrefix . 'email_token WHERE id = ' . (int) $row['id'] . ';');

        // Update last_access
        $this->dbms->query('UPDATE ' . $this->tablePrefix . 'users SET last_login = CURRENT_TIMESTAMP WHERE email = "'
            . (string) $row['email'] . '";');

        $this->populateUserByEmail((string) $row['email']);
        return true;
    }

    /**
     * Logic for the user login.
     *
     * @param string $email
     * @return string
     */
    public function login(string $email): string
    {
        // Validate existence of the user or create it
        // Select email From users and if nothing returned, then INSERT email INTO users
        // (Note never loggedin users older than 15 minutes are destroyed) <- TODO
        $result = $this->dbms->query('SELECT email FROM ' . $this->tablePrefix . 'users WHERE email = "' . (string) $email . '";');
        if (is_bool($result) || !$result->fetch_assoc()) {
            $this->dbms->query('INSERT INTO ' . $this->tablePrefix . 'users (email, created) VALUES ("' . (string) $email
                . '", CURRENT_TIMESTAMP);'); // todo assert insert doesn't fail
            $this->firstUnconfirmedLogin((int) $this->dbms->insert_id);
            // Note: If the number is greater than maximal int value, mysqli_insert_id() will return a string.
            $this->isNewUser = true;
        } else {
            $this->isNewUser = false;
        }
        $this->token = $this->generateToken();
        // Generate and store a token for this email
        $this->dbms->query('INSERT INTO ' . $this->tablePrefix . 'email_token (email, token, created) VALUES ("' . (string) $email
            . '", "' . (string) $this->token . '", CURRENT_TIMESTAMP);'); // todo assert insert doesn't fail
        return $this->token;
    }

    /**
     * Logic to handle user logout.
     *
     * Redirection MUST be taken care of by the calling script.
     *
     * @return void
     */
    public function logout(): void
    {
        $this->dbms->query('DELETE FROM protokronika_session_user WHERE token = "'
            . (string) $_SESSION['sbSessionToken'] . '";');
        unset($_SESSION['sbSessionToken']);
        // todo remove csrf tokens from this browser context
        // Remove "Remember Me" cookie if it exists both from database and from cookies
        if (isset($_COOKIE['sbRememberMe'])) {
            $this->dbms->query('DELETE FROM protokronika_session_user WHERE token = "'
                . (string) $_COOKIE['sbRememberMe'] . '";');
            setcookie('sbRememberMe', '', time() - 3600, $this->cookiePath, $this->cookieDomain, true, true);
        }
        $this->isAuthenticated = false;
    }

    /**
     * Populates user attributes for user with the given email.
     *
     * Also creates Session.
     *
     * @param string $email
     * @return void
     * @throws \Exception
     */
    private function populateUserByEmail(string $email): void
    {
        $row = $this->fetchFirstRow('SELECT id, role_id FROM protokronika_users WHERE email = "'
            . (string) $email . '";');
        if (is_null($row)) {
            throw new \Exception('An existing user expected.');
        }
        $this->email = $email;
        $this->roleId = (int) $row['role_id'];
        $this->userId = (int) $row['id'];
        $this->createSessionId($this->userId);
        Debugger::barDump(['email' => $this->email, 'roleId' => $this->roleId, 'userId' => $this->userId], 'User');
        //$this->dbms->query("UPDATE protokronika_users SET last_access = CURRENT_TIMESTAMP WHERE
        // email = '{$this->email}'");
    }

    /**
     * Populates user attributes for user with the given user_id.
     *
     * Doesn't create session
     *
     * @param int $userId
     * @return void
     * @throws \Exception
     */
    private function populateUserById(int $userId): void
    {
        $row = $this->fetchFirstRow('SELECT email, role_id FROM protokronika_users WHERE id = ' . (int) $userId . ';');
        if (is_null($row)) {
            throw new \Exception('An existing user expected.');
        }
        $this->email = (string) $row['email'];
        $this->roleId = (int) $row['role_id'];
        $this->userId = $userId;
        //$this->createSessionId($this->userId);
        Debugger::barDump(['email' => $this->email, 'roleId' => $this->roleId, 'userId' => $this->userId], 'User');
        //$this->dbms->query("UPDATE protokronika_users SET last_access = CURRENT_TIMESTAMP
        // WHERE email = '{$this->email}'");
    }

    /**
     * Table prefix injection.
     *
     * Used for groups, so far.
     * Todo use it for queries in this class, as well.
     *
     * @param string $tablePrefix
     * @return void
     */
    public function setTablePrefix(string $tablePrefix): void
    {
        $this->tablePrefix = $tablePrefix;
    }
}
