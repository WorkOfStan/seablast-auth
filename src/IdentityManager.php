<?php

declare(strict_types=1);

namespace Seablast\Auth;

use DateTime;
use Seablast\Auth\Exceptions\DbmsException;
use Seablast\Auth\Exceptions\UserException;
use Seablast\Interfaces\IdentityManagerInterface;
use Tracy\Debugger;
use Webmozart\Assert\Assert;

/**
 * IdentityManager class manages user authentication and session handling.
 * Uses MySQLi for database access.
 *
 * Call setTablePrefix injection, if table prefix is used.
 *
 * Note: Timestamps and Timezones: Ensure that your PHP and MySQL timezones are properly set,
 * as the code uses CURRENT_TIMESTAMP for time-related operations.
 * TODO: not just (string) type casting but also escapeSQL against SQL injection
 * TODO: test intervals and refactor code
 */
class IdentityManager implements IdentityManagerInterface
{
    use \Nette\SmartObject;

    /** @var string User email. */
    private $email;
    /** @var bool Authentication status. */
    private $isAuthenticated = false;
    /** @var ?bool Flag indicating if the user trying to authenticate is a new user. */
    private $isNewUser = null;
    /** @var \mysqli Database connection. */
    private $mysqli;
    /** @var int Role ID of the user. */
    private $roleId;
    /** @var string Table prefix for SQL queries. */
    private $tablePrefix = '';
    /** @var int User ID. */
    private $userId;

    /**
     * Constructor for IdentityManager.
     *
     * @param \mysqli $mysqli Database management system to use.
     */
    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * If email belongs to an existing user, isNewUser = false; otherwise INSERT new user and isNewUser = true.
     *
     * @param string $email
     * @return void
     */
    private function checkEmailOrCreateUser(string $email): void
    {
        // Validate existence of the user or create it
        // Select email From users and if nothing returned, then INSERT email INTO users
        // (Note never loggedin users older than 15 minutes are destroyed) <- TODO
        Assert::email($email); // TODO more specific Exception to catch exactly it in a PHPUnit test
        $result = $this->mysqli->query("SELECT email FROM `{$this->tablePrefix}users` WHERE email = '"
            . (string) $email . "';");
        if (is_bool($result) || !$result->fetch_assoc()) {
            $this->mysqli->query("INSERT INTO `{$this->tablePrefix}users` (email, created) VALUES ('" . (string) $email
                . "', CURRENT_TIMESTAMP);"); // todo assert insert doesn't fail
            // Note: If the number is greater than maximal int value, mysqli_insert_id() will return a string.
            $this->userId = (int) $this->mysqli->insert_id;
            $this->isNewUser = true;
        } else {
            $this->isNewUser = false;
        }
    }

    /**
     * Creates a session ID and a remember-me token.
     *
     * TODO consider insert also type (short session, long remember me) for selective purge
     *
     * @param int $userId The user's ID.
     */
    private function createSessionId(int $userId): void
    {
        // insert uniqid to the sessionId field and userId into userId field of the session_user table
        $sessionId = uniqid('', true); // todo maybe also generateToken()???
        $rememberMeToken = $this->generateToken();
        $this->mysqli->query("INSERT INTO `{$this->tablePrefix}session_user` (user_id, token, updated) VALUES ("
            . (int) $userId . ", '" . $sessionId . "', CURRENT_TIMESTAMP), (" . (int) $userId
            . ", '" . $rememberMeToken . "', CURRENT_TIMESTAMP);");
        // todo assert insert doesn't fail
        $_SESSION['sbSessionToken'] = $sessionId;
        // Create a long-lived relogin cookie which expires in 30 days (only for HTTPS)
        if ($this->isHttps($_SERVER)) {
            $this->setCookie(
                $rememberMeToken,
                time() + 30 * 24 * 60 * 60 // expire time: days * hours * minutes * seconds
            );
        }
    }

    /**
     * Checks if the Remember Me cookie matches.
     *
     * @param array<string> $cookie The array of cookies.
     * @return bool True if remembered, false otherwise.
     */
    public function doYouRememberMe(array $cookie): bool
    {
        // Check if the "Remember Me" cookie exists
        if (!isset($cookie['sbRememberMe'])) {
            return false;
        }
        // Ignore Remember Me cookie, if not over HTTPS
        if (!$this->isHttps($_SERVER)) {
            return false;
        }
        // Retrieve the token from the cookie
        $userId = $this->getUserForSessionId($cookie['sbRememberMe'], 30);
        if (is_null($userId)) {
            return false;
        }
        // delete the old cookie id from session_user as new one will be set in createSessionId anyway
        $this->mysqli->query("DELETE FROM `{$this->tablePrefix}session_user` WHERE user_id = " . $userId
            . " AND token = '" . $this->mysqli->real_escape_string($cookie['sbRememberMe']) . "';");
        $this->createSessionId($userId); // incidentally also updates the RM cookie
        return true;
    }

    /**
     * Fetches the first row of a query result.
     *
     * @param string $query SQL query string.
     * @return array<?scalar>|null Associative array of the row or null if no rows.
     * @throws DbmsException on database statement error
     */
    private function fetchFirstRow(string $query): ?array
    {
        $result = $this->mysqli->query($query);
        if ($result === false) {
            throw new DbmsException($this->mysqli->errno . ': ' . $this->mysqli->error);
        } elseif (is_bool($result)) {
            return null;
        }
        $output = $result->fetch_assoc();
        if ($output === false) {
            throw new DbmsException('fetch_assoc failed for fetchFirstRow');
        }
        return $output;
    }

    /**
     * Generates a unique token for user sessions or actions.
     *
     * TODO next phase - CSRF token method used
     *
     * @return string A hexadecimal token string.
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Retrieves the email of the currently authenticated user.
     *
     * @return string The user's email address.
     * @throws UserException If the email has not been set.
     */
    public function getEmail(): string
    {
        if (empty($this->email)) {
            throw new UserException('You should first check the existence of User.');
        }
        return $this->email;
    }

    /**
     * Retrieves the list of groups the user belongs to. It may be empty.
     *
     * Implementation of Seablast\Seablast\IdentityManagerInterface.
     *
     * @return int[] An array of group IDs.
     */
    public function getGroups(): array
    {
        $groups = new GroupManager($this->mysqli, $this->userId, $this->tablePrefix);
        return $groups->getGroupsByUserId();
    }

    /**
     * Retrieves the role ID of the authenticated user.
     *
     * Implementation of Seablast\Seablast\IdentityManagerInterface.
     *
     * @return int The role ID.
     * @throws UserException If the role ID has not been set.
     */
    public function getRoleId(): int
    {
        if (empty($this->roleId)) {
            throw new UserException('You should first check the existence of User.'); // todo check it really here?
        }
        return $this->roleId;
    }

    /**
     * Retrieves the user ID of the authenticated user.
     *
     * Implementation of Seablast\Seablast\IdentityManagerInterface.
     *
     * @return int The user ID.
     * @throws UserException If the user ID has not been set.
     */
    public function getUserId(): int
    {
        if (empty($this->userId)) {
            throw new UserException('You should first check the existence of User.');
        }
        return $this->userId;
    }

    /**
     * Determines if the user with the given session token exists and is not older than specified days.
     *
     * @param string $sessionToken Session token to validate.
     * @param int $days Number of days the token should be considered valid.
     * @return ?int User ID if valid, null otherwise.
     */
    private function getUserForSessionId(string $sessionToken, int $days = 1): ?int
    {
        $sessionTokenEscaped = $this->mysqli->real_escape_string($sessionToken);
        // Calculate $days from now in PHP instead of `NOW() - INTERVAL` in order to cache the SQL responses
        $oneDayTillNow = new DateTime('-' . $days . ' day');
        // Regardless of rounding up, reset minutes (and seconds) to 0
        $oneDayTillNow->setTime((int) $oneDayTillNow->format('H'), 0, 0);
        $pastDate = $oneDayTillNow->format('Y-m-d H:i:s');
        Debugger::barDump($pastDate, 'Past date'); // debug
        $row = $this->fetchFirstRow("SELECT user_id, updated FROM `{$this->tablePrefix}session_user` WHERE token = '"
            . $sessionTokenEscaped . "' AND updated > '" . $pastDate . "';");
        if (is_null($row)) {
            return null;
        }
        // Update last access
        // TODO prolongate session only if the previous access is older than 5 minutes to reduce SQL load
        Debugger::barDump($row, 'User for session'); // debug
        $this->mysqli->query("UPDATE `{$this->tablePrefix}session_user` SET updated = CURRENT_TIMESTAMP WHERE token = '"
            . $sessionTokenEscaped . "';");
        return (int) $row['user_id'];
    }

    /**
     * Determines if the user is authenticated by checking the session.
     *
     * Implementation of Seablast\Seablast\IdentityManagerInterface.
     *
     * @return bool True if authenticated, false otherwise.
     */
    public function isAuthenticated(): bool
    {
        $sessionId = $_SESSION['sbSessionToken'] ?? null;
        if (is_null($sessionId) || !is_string($sessionId)) {
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
     * Checks whether the current request was made using HTTPS.
     *
     * This function supports detection of HTTPS in both Apache and Nginx environments,
     * including setups behind reverse proxies or load balancers (e.g., Nginx, Cloudflare),
     * by inspecting common server variables and headers.
     *
     * For maximum security when behind a proxy, you can pass a list of trusted proxy IPs
     * to avoid spoofed headers like X-Forwarded-Proto.
     *
     * @param array<mixed> $server The $_SERVER array or a custom equivalent.
     * @param array<string> $trustedProxies (optional) Array of trusted proxy IP addresses.
     *                               When specified, proxy-related headers are trusted
     *                               only if the request comes from one of these IPs.
     *
     * @return bool True if the request was made via HTTPS, false otherwise.
     *
     * @example
     * isHttps($_SERVER); // Basic usage
     * isHttps($_SERVER, ['192.168.1.1']); // Usage with trusted proxies
     */
    private function isHttps(array $server, array $trustedProxies = []): bool
    {
        $clientIp = $server['REMOTE_ADDR'] ?? '';

        $proxyHeaders = (
            (!empty($server['HTTP_X_FORWARDED_PROTO']) && is_string($server['HTTP_X_FORWARDED_PROTO'])
                && strtolower($server['HTTP_X_FORWARDED_PROTO']) === 'https') ||
            (!empty($server['HTTP_X_FORWARDED_SSL']) && is_string($server['HTTP_X_FORWARDED_SSL'])
                && strtolower($server['HTTP_X_FORWARDED_SSL']) === 'on')
            );

        return
            (!empty($server['HTTPS']) && is_string($server['HTTPS']) && strtolower($server['HTTPS']) === 'on') ||
            (!empty($server['REQUEST_SCHEME']) && is_string($server['REQUEST_SCHEME'])
                && strtolower($server['REQUEST_SCHEME']) === 'https') ||
            (!empty($server['SERVER_PORT']) && $server['SERVER_PORT'] === '443') ||
            ($proxyHeaders && in_array($clientIp, $trustedProxies, true));
    }

    /**
     * Determines if the current authentication attempt is for a new user.
     *
     * @return bool True if new user, false otherwise.
     * @throws UserException If called at an inappropriate time.
     */
    public function isNewUser(): bool
    {
        if (is_null($this->isNewUser)) {
            throw new UserException('isNewUser should not be called at this moment.');
        }
        return (bool) $this->isNewUser;
    }

    /**
     * Validates an email token and populates user data upon success.
     *
     * ?? Check for sessionToken as well to force login to the same environment.
     *
     * @param string $emailToken Email token to validate.
     * @return bool True if the token is valid, false otherwise.
     */
    public function isTokenValid(string $emailToken): bool
    {
        $row = $this->fetchFirstRow(
            "SELECT id, email FROM `{$this->tablePrefix}email_token` WHERE token = '" . $emailToken
            . "' AND created > (NOW() - INTERVAL 15 MINUTE);"
        );
        if (is_null($row)) {
            return false;
        }
        // Token is one time only
        $this->mysqli->query("DELETE FROM `{$this->tablePrefix}email_token` WHERE id = " . (int) $row['id'] . ";");
         // Update last_access
        $this->mysqli->query("UPDATE `{$this->tablePrefix}users` SET last_login = CURRENT_TIMESTAMP WHERE email = '"
            . (string) $row['email'] . "';");

        $this->populateUserByEmail((string) $row['email']);
        return true;
    }

    /**
     * Logic for the user login. Validate email and return a token to be sent by email.
     *
     * TODO allow inserting the token to an HTML input field.
     *
     * @param string $email
     * @return string
     */
    public function login(string $email): string
    {
        $this->checkEmailOrCreateUser($email);
        $token = $this->generateToken();
        // Generate and store a token for this email
        $this->mysqli->query("INSERT INTO `{$this->tablePrefix}email_token` (email, token, created) VALUES ('"
            . (string) $email . "', '" . (string) $token . "', CURRENT_TIMESTAMP);");
        // todo assert insert doesn't fail
        return $token;
    }

    /**
     * Immediate login.
     *
     * If the email is trusted, e.g. the app got it through social login, just log in.
     *
     * @param string $email
     * @return void
     */
    public function loginWithTrustedEmail(string $email): void
    {
        $this->checkEmailOrCreateUser($email);
        $this->populateUserByEmail($email);
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
        Assert::string($_SESSION['sbSessionToken']);
        $this->mysqli->query("DELETE FROM `{$this->tablePrefix}session_user` WHERE token = '"
            . $_SESSION['sbSessionToken'] . "';");
        unset($_SESSION['sbSessionToken']);
        // todo remove csrf tokens from this browser context
        // Remove "Remember Me" cookie if it exists both from database and from cookies
        if (isset($_COOKIE['sbRememberMe'])) {
            Assert::string($_COOKIE['sbRememberMe']);
            $this->mysqli->query("DELETE FROM `{$this->tablePrefix}session_user` WHERE token = '"
                . (string) $_COOKIE['sbRememberMe'] . "';");
            $this->setCookie('', time() - 3600);
        }
        $this->isAuthenticated = false;
    }

    /**
     * Populates user attributes for user with the given email.
     *
     * Also creates a session.
     *
     * @param string $email
     * @return void
     * @throws UserException An existing user expected.
     */
    private function populateUserByEmail(string $email): void
    {
        $row = $this->fetchFirstRow("SELECT id, role_id FROM `{$this->tablePrefix}users` WHERE email = '"
            . (string) $email . "';");
        if (is_null($row)) {
            throw new UserException('An existing user expected.');
        }
        $this->email = $email;
        $this->roleId = (int) $row['role_id'];
        $this->userId = (int) $row['id'];
        $this->createSessionId($this->userId);
        Debugger::barDump(['email' => $this->email, 'roleId' => $this->roleId, 'userId' => $this->userId], 'User');
        //$this->dbms->query("UPDATE `{$this->tablePrefix}users` SET last_access = CURRENT_TIMESTAMP WHERE
        // email = '{$this->email}'");
    }

    /**
     * Populates user attributes for user with the given user_id.
     *
     * Doesn't create a session.
     *
     * @param int $userId
     * @return void
     * @throws UserException An existing user expected.
     */
    private function populateUserById(int $userId): void
    {
        $row = $this->fetchFirstRow("SELECT email, role_id FROM `{$this->tablePrefix}users` WHERE id = "
            . (int) $userId . ";");
        if (is_null($row)) {
            throw new UserException('An existing user expected.');
        }
        $this->email = (string) $row['email'];
        $this->roleId = (int) $row['role_id'];
        $this->userId = $userId;
        //$this->createSessionId($this->userId);
        Debugger::barDump(['email' => $this->email, 'roleId' => $this->roleId, 'userId' => $this->userId], 'User');
        //$this->dbms->query("UPDATE `{$this->tablePrefix}users` SET last_access = CURRENT_TIMESTAMP
        // WHERE email = '{$this->email}'");
    }

    /**
     * Set cookie the same way for creation and deletion.
     *
     * @param string $value
     * @param int $time
     * @return void
     */
    private function setCookie(string $value, int $time): void
    {
        setcookie(
            'sbRememberMe',
            $value,
            $time, // expire time: days * hours * minutes * seconds
            '', // default cookie path - so appPath/user not appPath
            '', // default cookie host
            true, // Set a long-lived cookie for HTTPS only
            true // http only
        );
    }

    /**
     * Table prefix injection.
     *
     * @param string $tablePrefix
     * @return void
     */
    public function setTablePrefix(string $tablePrefix): void
    {
        $this->tablePrefix = $tablePrefix;
    }
}
