<?php

declare(strict_types=1);

namespace Seablast\Auth;

use DateTime;
use Seablast\Auth\Exceptions\DbmsException;
use Seablast\Auth\Exceptions\UserException;
use Seablast\Interfaces\IdentityManagerInterface;
use Tracy\Debugger;
use Tracy\ILogger;
use Webmozart\Assert\Assert;

/**
 * IdentityManager class manages user authentication and session handling.
 * Uses MySQLi for database access.
 *
 * Inject a table prefix with setTablePrefix() when the application uses prefixed auth tables.
 *
 * Sets the 'sbRememberMe' cookie when Remember Me is enabled and the request is HTTPS.
 *
 * Note: Timestamps and Timezones: Ensure that your PHP and MySQL timezones are properly set,
 * as the code uses CURRENT_TIMESTAMP for time-related operations.
 * TODO: move mutable SQL statements to prepared statements.
 * TODO: test intervals and refactor code
 * TODO: PDO as well as MySQLi.
 */
class IdentityManager implements IdentityManagerInterface
{
    use \Nette\SmartObject;

    /** @var string Cookie path that may be injected. */
    private $cookiePath = '';
    /** @var string User email. */
    private $email;
    /** @var ?bool Flag indicating if the user trying to authenticate is a new user. */
    private $isNewUser = null;
    /** @var \mysqli Database connection. */
    private $mysqli;
    /** @var bool Flag indicating whether the Remember Me cookie can be created and read. */
    private $rememberMeCookieEnabled = true;
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
        // remove never-logged-in users older than 15 minutes (could be triggered by cron instead)
        $this->executeWriteQuery(
            "DELETE u FROM `{$this->tablePrefix}users` AS u WHERE u.last_login IS NULL"
            . " AND u.created < (CURRENT_TIMESTAMP - INTERVAL 15 MINUTE)"
            . " AND NOT EXISTS (SELECT 1 FROM `{$this->tablePrefix}session_user` AS su"
            . " WHERE su.user_id = u.id LIMIT 1);"
        );
        // Validate existence of the user or create it
        // Select email From users and if nothing returned, then INSERT email INTO users
        // Validate email format. Throwing the generic InvalidArgumentException from Webmozart is acceptable
        // for now; tests can catch it specifically if desired.
        Assert::email($email);
        $escapedEmail = $this->mysqli->real_escape_string($email);
        $query = "SELECT email FROM `{$this->tablePrefix}users` WHERE email = '" . $escapedEmail
            . "' LIMIT 1;";
        $result = $this->mysqli->query($query);
        if ($result === false) {
            throw new DbmsException($this->mysqli->errno . ': ' . $this->mysqli->error);
        }
        // Ensure static analyzers know we have a mysqli_result here
        Assert::isInstanceOf($result, \mysqli_result::class);
        if ($result->num_rows === 0) {
            $this->executeWriteQuery(
                "INSERT INTO `{$this->tablePrefix}users` (email, created) VALUES ('" . $escapedEmail
                . "', CURRENT_TIMESTAMP);"
            );
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
     * TODO consider inserting token type (short session, long Remember Me) for selective purge.
     *
     * @param int $userId The user's ID.
     */
    private function createSessionId(int $userId): void
    {
        // Insert a short-lived session token and, when enabled over HTTPS, a long-lived Remember Me token.
        $sessionId = $this->generateToken();
        $values = [
            "(" . (int) $userId . ", '" . $this->mysqli->real_escape_string($sessionId) . "', CURRENT_TIMESTAMP)"
        ];
        $rememberMeToken = null;
        $shouldCreateRememberMe = $this->rememberMeCookieEnabled && $this->isHttps($_SERVER);
        if ($shouldCreateRememberMe) {
            $rememberMeToken = $this->generateToken();
            $values[] = "(" . (int) $userId . ", '" . $this->mysqli->real_escape_string($rememberMeToken)
                . "', CURRENT_TIMESTAMP)";
        }
        $this->executeWriteQuery(
            "INSERT INTO `{$this->tablePrefix}session_user` (user_id, token, updated) VALUES "
            . implode(', ', $values) . ";"
        );
        $_SESSION['sbSessionToken'] = $sessionId;
        // Create a long-lived relogin cookie which expires in 30 days (only for HTTPS)
        if ($rememberMeToken !== null) {
            $this->setCookie(
                $rememberMeToken,
                time() + 30 * 24 * 60 * 60 // expire time: days * hours * minutes * seconds
            );
        } elseif (!$this->rememberMeCookieEnabled) {
            Debugger::barDump('sbRememberMe cookie disabled');
        } else {
            Debugger::barDump('http => no sbRememberMe cookie');
        }
    }

    private function deleteSessionToken(string $token): void
    {
        $this->executeWriteQuery(
            "DELETE FROM `{$this->tablePrefix}session_user` WHERE token = '"
            . $this->mysqli->real_escape_string($token) . "';"
        );
    }

    /**
     * Checks if the Remember Me cookie matches.
     *
     * @param array<string> $cookie The array of cookies.
     * @return bool True if remembered, false otherwise.
     */
    public function doYouRememberMe(array $cookie): bool
    {
        if (!$this->rememberMeCookieEnabled) {
            return false;
        }
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
        $this->executeWriteQuery("DELETE FROM `{$this->tablePrefix}session_user` WHERE user_id = " . $userId
            . " AND token = '" . $this->mysqli->real_escape_string($cookie['sbRememberMe']) . "';");
        $this->createSessionId($userId); // incidentally also updates the RM cookie
        return true;
    }

    /**
     * Executes a write query and throws on database errors.
     *
     * @param string $query SQL query string.
     * @return void
     * @throws DbmsException on database statement error
     */
    private function executeWriteQuery(string $query): void
    {
        if ($this->mysqli->query($query) !== true) {
            throw new DbmsException($this->mysqli->errno . ': ' . $this->mysqli->error);
        }
    }

    /**
     * Fetches the first row of a query result.
     *
     * TODO add ORDER BY to queries, and as there is a LIMIT 1, replace this method with queryStrict.
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
     * TODO next phase - use a CSRF token method.
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
     * @return array<int> An array of group IDs.
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
            throw new UserException('You should first check the existence of User.'); // TODO check it really here?
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
            . $sessionTokenEscaped . "' AND updated > '" . $pastDate . "' LIMIT 1;");
        if (is_null($row)) {
            return null;
        }
        $userId = (int) $row['user_id'];
        // Set the query-log user immediately after resolving `user_id`, before refreshing `session_user.updated`
        $setQueryLogUser = [$this->mysqli, 'setUser'];
        if (is_callable($setQueryLogUser)) {
            call_user_func($setQueryLogUser, $userId);
        }
        // Update last access when the saved timestamp is stale enough.
        Debugger::barDump($row, 'User for session'); // debug
        $fiveMinutesAgo = date('Y-m-d H:i:s', time() - 300);
        if ((string) $row['updated'] < $fiveMinutesAgo) {
            $this->executeWriteQuery(
                "UPDATE `{$this->tablePrefix}session_user` SET updated = CURRENT_TIMESTAMP WHERE token = '"
                . $sessionTokenEscaped . "';"
            );
        } else {
            Debugger::barDump('No session update as younger than 5 minutes');
        }
        return $userId;
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
        $userId = is_string($sessionId) ? $this->getUserForSessionId($sessionId) : null;

        if ($userId === null) {
            // TODO doYouRememberMe?
            return false;
        }

        $this->populateUserById($userId);
        return true;
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
        $emailTokenEscaped = $this->mysqli->real_escape_string($emailToken);
        $row = $this->fetchFirstRow(
            "SELECT id, email FROM `{$this->tablePrefix}email_token` WHERE token = '" . $emailTokenEscaped
            . "' AND created > (NOW() - INTERVAL 15 MINUTE) LIMIT 1;"
        );
        if (is_null($row)) {
            return false;
        }
        // Token is one time only
        $this->executeWriteQuery(
            "DELETE FROM `{$this->tablePrefix}email_token` WHERE id = " . (int) $row['id'] . ";"
        );
         // Update last_access
        $this->executeWriteQuery(
            "UPDATE `{$this->tablePrefix}users` SET last_login = CURRENT_TIMESTAMP WHERE email = '"
            . $this->mysqli->real_escape_string((string) $row['email']) . "';"
        );

        $this->populateUserByEmail((string) $row['email']);
        return true;
    }

    /**
     * Logic for the user login. Validate email and return a token to be sent by email.
     *
     * TODO allow inserting the token into an HTML input field.
     *
     * @param string $email
     * @return string
     */
    public function login(string $email): string
    {
        $this->checkEmailOrCreateUser($email);
        $token = $this->generateToken();
        // Generate and store a token for this email
        $this->executeWriteQuery("INSERT INTO `{$this->tablePrefix}email_token` (email, token, created) VALUES ('"
            . $this->mysqli->real_escape_string($email) . "', '" . $this->mysqli->real_escape_string($token)
            . "', CURRENT_TIMESTAMP);");
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
        // Delete through the helper so escaping and DB error handling stay consistent.
        $this->deleteSessionToken($_SESSION['sbSessionToken']);
        unset($_SESSION['sbSessionToken']);
        // TODO remove csrf tokens from this browser context.
        // Remove "Remember Me" cookie if it exists both from database and from cookies
        if (isset($_COOKIE['sbRememberMe'])) {
            Assert::string($_COOKIE['sbRememberMe']);
            // Delete the Remember Me token through the same path as the short session token.
            $this->deleteSessionToken($_COOKIE['sbRememberMe']);
            $this->setCookie('', time() - 3600);
        }
        // TODO make sure that Seablast knows, i.e. invalidate SB_ROLE_ID and USER_ID.
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
            . $this->mysqli->real_escape_string($email) . "' LIMIT 1;");
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
            . (int) $userId . " LIMIT 1;");
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
        Debugger::barDump($this->cookiePath, 'setcookie - Cookie Path');
        $result = setcookie(
            'sbRememberMe',
            $value,
            $time, // expire time: days * hours * minutes * seconds
            $this->cookiePath, // defined, as '' may change (between /app and /app/user)
            '', // default cookie host
            true, // Set a long-lived cookie for HTTPS only
            true // http only
        );
        if ($result === false) {
            Debugger::log('sbRememberMe cookie could not be set.', ILogger::ERROR);
        }
    }

    /**
     * Cookie path injection.
     *
     * As the default relative path '' may change (between /app and /app/user) causing cookie conflicts.
     *
     * @param string $cookiePath
     * @return void
     */
    public function setCookiePath(string $cookiePath): void
    {
        $this->cookiePath = $cookiePath;
        Debugger::barDump($this->cookiePath, 'Injected cookie path to IdentityManager');
    }

    /**
     * Remember Me cookie feature flag injection.
     *
     * Defaults to true for backwards compatibility with direct IdentityManager users.
     *
     * @param bool $enabled
     * @return void
     */
    public function setRememberMeCookieEnabled(bool $enabled): void
    {
        $this->rememberMeCookieEnabled = $enabled;
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
