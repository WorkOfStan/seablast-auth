<?php

declare(strict_types=1);

namespace Seablast\Auth;

use Seablast\Auth\AuthConstant;
use Seablast\Seablast\SeablastConfiguration;
use Seablast\Seablast\SeablastConstant;
use Seablast\Seablast\SeablastModelInterface;
use Seablast\Seablast\Superglobals;
use stdClass;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Tracy\Debugger;
use Tracy\ILogger;
use Webmozart\Assert\Assert;

/**
 * 0) If authenticated, listen to logout
 * A) If model is invoked with a token as GET parameter, then
 *     a) if token is valid, create session and forward user where they were heading
 *     b) if token is invalid, show login form
 * B) auto relogin if remember me cookie fits database
 * C) If model is invoked with POST parameters email & valid CSRF token => send email with token
 *    Registration & re-login may get different wording
 *    The email token is then processed in step A)
 * D) Show login form to be processed in C)
 */
class UserModel implements SeablastModelInterface
{
    use \Nette\SmartObject;

    private const RETURN_URL_PARAMETER = 'returnUrl';
    private const TOKEN_PARAMETER = 'token';

    /** @var SeablastConfiguration */
    private $configuration;
    /** @var Superglobals */
    private $superglobals;
    /** @var IdentityManager */
    private $user;
    /** @var string Route to the user log-in/log-out page */
    private $userRoute;

    /**
     *
     * @param SeablastConfiguration $configuration
     * @param Superglobals $superglobals
     */
    public function __construct(SeablastConfiguration $configuration, Superglobals $superglobals)
    {
        $this->configuration = $configuration;
        $this->superglobals = $superglobals;
        $this->userRoute = $this->configuration->getString(AuthConstant::USER_ROUTE);
        $this->user = new IdentityManager($this->configuration->mysqli());
        $this->user->setTablePrefix($this->configuration->dbmsTablePrefix());
        $this->user->setCookiePath(
            $this->configuration->getString(SeablastConstant::SB_SESSION_SET_COOKIE_PARAMS_PATH)
        );
        $this->user->setRememberMeCookieEnabled(
            $this->configuration->flag->status(AuthConstant::FLAG_REMEMBER_ME_COOKIE)
        );
    }

    /**
     * Different response for the current authentication state.
     * @return stdClass
     * @throw \Exception if unimplemented HTTP method call
     */
    public function knowledge(): stdClass
    {
        if ($this->user->isAuthenticated()) {
            if (isset($this->superglobals->get['logout'])) {
                $this->user->logout();
                return (object) [
                        'redirectionUrl' => $this->configuration->getString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL)
                        . $this->userRoute, // TODO go home instead?
                ];
            }
            return (object) [
                    'showLogin' => false,
                    'showLogout' => true,
                    'message' => 'Nyní jste přihlášeni jako ' .
                        $this->user->getEmail() . ', užijte si to.',
            ];
        }
        if ($this->superglobals->server['REQUEST_METHOD'] === 'GET') {
            if (isset($this->superglobals->get['token'])) {
                Assert::string($this->superglobals->get['token']);
                if ($this->user->isTokenValid($this->superglobals->get['token'])) {
                    // This answer wouldn't show the menu for authenticated users
                    //return (object) [
                    //    'showLogin' => false,
                    //    'showLogout' => true,
                    //    'message' => 'Právě jste se přihlásili jako ' . $this->user->getEmail() . ', užijte si to.'
                    //    . ' <a href="../content-root">Moje kroniky</a>', // HTML is displayed escaped
                    //];
                    // ... so refresh the page ;-)
                    return (object) [
                            'redirectionUrl' => $this->getPostLoginRedirectUrl(),
                            'httpCode' => 303,
                    ];
                }
                return (object) [
                        'showLogin' => true,
                        'showLogout' => false,
                        'message' => 'Invalid token.',
                ];
            }
            // auto re-login attempt if Remember me cookie allowed
            if (
                $this->configuration->flag->status(AuthConstant::FLAG_REMEMBER_ME_COOKIE) &&
                $this->user->doYouRememberMe(// let through only strings
                    array_filter(
                        $_COOKIE,
                        function ($item) {
                            return is_string($item);
                        }
                    )
                )
            ) {
                Debugger::barDump('Auto-relogin.');
                return (object) [// exactly the same as with valid token
                        'redirectionUrl' =>
                        $this->configuration->getString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL) . $this->userRoute,
                ];
            }
            // First visit
            return (object) [
                    'showLogin' => true,
                    'showLogout' => false,
                    'message' => 'Zalogujte se. Na zadaný email vám přijde webová adresa, '
                        . 'přes kterou se přihlásíte. Žádná hesla nejsou třeba.',
            ];
        } elseif ($this->superglobals->server['REQUEST_METHOD'] === 'POST') {
            if ((isset($this->superglobals->post['csrfToken'])) && (isset($this->superglobals->post['email']))) {
                // validate email
                if (!filter_var($this->superglobals->post['email'], FILTER_VALIDATE_EMAIL)) {
                    return (object) [
                            'showLogin' => true,
                            'showLogout' => false,
                            'message' => 'Invalid email format.',
                    ];
                }
                // CSRF token validation
                if (!$this->hasValidCsrfToken()) {
                    Debugger::barDump("CSRF token mismatch", 'ERROR on input');
                    Debugger::log("CSRF token mismatch", ILogger::ERROR);
                    return (object) [
                            'showLogin' => true,
                            'showLogout' => false,
                            'message' => 'Token mismatch.',
                    ];
                }
                // Assertion only for static analysis as it was already checked above with filter_var.
                Assert::email($this->superglobals->post['email']);
                if ($this->user->isLoginEmailRecentlyRequested($this->superglobals->post['email'])) {
                    return (object) [
                            'showLogin' => false,
                            'showLogout' => false,
                            'message' => 'Na zadaný email vám přijde přihlašovací odkaz. Proklikněte ho.'
                        . ' Žádná hesla nejsou třeba.',
                    ];
                }
                // All is ok. Send the login email.
                $this->sendLoginEmail(
                    $this->superglobals->post['email'],
                    $this->user->login($this->superglobals->post['email'])
                );
                return (object) [
                        'showLogin' => false,
                        'showLogout' => false,
                        'message' => 'Na zadaný email vám přijde přihlašovací odkaz. Proklikněte ho.'
                        . ' Žádná hesla nejsou třeba.',
                ];
            }
        }
        throw new \RuntimeException(
            'Wrong HTTP request: ' . (string) print_r($this->superglobals->server['REQUEST_METHOD'], true)
            . ' (or POST API call requires authentication)'
        );
    }

    /**
     * Checks the CSRF token submitted through a form.
     *
     * @return bool
     */
    private function hasValidCsrfToken(): bool
    {
        if (!isset($this->superglobals->post['csrfToken']) || !is_string($this->superglobals->post['csrfToken'])) {
            return false;
        }
        $csrfTokenManager = new CsrfTokenManager();
        return $csrfTokenManager->isTokenValid(new CsrfToken('sb_json', $this->superglobals->post['csrfToken']));
    }

    /**
     * Sends registration or login email with URL with token.
     *
     * URL is placed instead of %URL% in AppConstant::TEXT_EMAIL_XXX.
     *
     * @param string $emailAddress
     * @param string $token
     * @return void
     */
    private function sendLoginEmail(string $emailAddress, string $token): void
    {
        $loginUrl = $this->buildLoginUrl($token);
        $plainText = str_replace(
            '%URL%',
            $loginUrl,
            $this->configuration->getString(
                $this->user->isNewUser() ? AuthConstant::TEXT_EMAIL_REGISTRATION : AuthConstant::TEXT_EMAIL_LOGIN
            )
        );
        if (!$this->configuration->flag->status(SeablastConstant::USER_MAIL_ENABLED)) {
            Debugger::barDump('Sending emails is not enabled');
            return;
        }
        $sender = new MailOut($this->configuration);
        $subject = $this->configuration->getString(
            $this->user->isNewUser() ? AuthConstant::SUBJECT_EMAIL_REGISTRATION : AuthConstant::SUBJECT_EMAIL_LOGIN
        );
        // Optionally prepare an HTML variant while keeping clean plaintext for clients without HTML.
        //        $htmlBody = sprintf(
        //            '<p>%s</p>',
        //            htmlspecialchars(str_replace("\n", ' ', $plainText), ENT_QUOTES, 'UTF-8')
        //        );
        $sender->send(
            $emailAddress,
            $subject,
            $plainText
            //,
            //    [
            //        // 'cc'  => ['cc@example.com'],
            //        // 'bcc' => 'audit@example.com',
            //        'html' => $htmlBody,
            //        // 'replyTo' => 'support@example.com',
            //        // 'priority' => \Symfony\Component\Mime\Email::PRIORITY_NORMAL,
            //    ]
        );
        Debugger::barDump($this->configuration->getString(SeablastConstant::FROM_MAIL_ADDRESS), 'Email sent from');
    }

    /**
     * Builds an email login URL carrying a safe, app-relative return target.
     *
     * @param string $token
     * @return string
     */
    private function buildLoginUrl(string $token): string
    {
        $query = [
            self::TOKEN_PARAMETER => $token,
            self::RETURN_URL_PARAMETER => $this->getCurrentReturnUrl(),
        ];
        return $this->getAbsoluteUserUrl() . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Returns the safe absolute URL to visit after a successful email-token login.
     *
     * @return string
     */
    private function getPostLoginRedirectUrl(): string
    {
        $returnUrl = $this->superglobals->get[self::RETURN_URL_PARAMETER] ?? null;
        if (!is_string($returnUrl)) {
            return $this->getAbsoluteUserUrl(false);
        }
        $safeReturnUrl = $this->sanitizeReturnUrl($returnUrl);
        if ($safeReturnUrl === null) {
            return $this->getAbsoluteUserUrl(false);
        }
        return rtrim($this->configuration->getString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL), '/')
            . $safeReturnUrl;
    }

    /**
     * Derives an app-relative return target from the current request URI.
     *
     * @return string
     */
    private function getCurrentReturnUrl(): string
    {
        $requestUri = $this->superglobals->server['REQUEST_URI'] ?? null;
        if (!is_string($requestUri)) {
            return $this->getUserRoute();
        }
        $requestParts = parse_url($requestUri);
        if (
            $requestParts === false ||
            isset($requestParts['scheme']) ||
            isset($requestParts['host']) ||
            isset($requestParts['user']) ||
            isset($requestParts['pass']) ||
            !isset($requestParts['path'])
        ) {
            return $this->getUserRoute();
        }

        $appRootUrl = $this->configuration->getString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL);
        $appRootPath = parse_url($appRootUrl, PHP_URL_PATH);
        if ($appRootPath === false) {
            return $this->getUserRoute();
        }
        $appRootPath = rtrim($appRootPath ?? '', '/');
        $requestPath = $requestParts['path'];
        if ($appRootPath !== '') {
            if ($requestPath === $appRootPath) {
                $requestPath = '/';
            } elseif (strpos($requestPath, $appRootPath . '/') === 0) {
                $requestPath = substr($requestPath, strlen($appRootPath));
            } else {
                return $this->getUserRoute();
            }
        }

        $returnUrl = $requestPath;
        if (isset($requestParts['query'])) {
            $returnUrl .= '?' . $requestParts['query'];
        }
        $safeReturnUrl = $this->sanitizeReturnUrl($returnUrl);
        if ($safeReturnUrl === null) {
            return $this->getUserRoute();
        }
        if ($this->getUserRoute() !== '/' && $safeReturnUrl === $this->getUserRoute() . '/') {
            return $this->getUserRoute();
        }
        return $safeReturnUrl;
    }

    /**
     * Validates and normalizes an app-relative return target.
     *
     * @param string $returnUrl
     * @return string|null
     */
    private function sanitizeReturnUrl(string $returnUrl): ?string
    {
        if (
            $returnUrl === '' ||
            $returnUrl[0] !== '/' ||
            strpos($returnUrl, '//') === 0 ||
            preg_match('/[\\x00-\\x1F\\x7F\\\\]/', $returnUrl) === 1
        ) {
            return null;
        }
        $parts = parse_url($returnUrl);
        if (
            $parts === false ||
            isset($parts['scheme']) ||
            isset($parts['host']) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['fragment']) ||
            !isset($parts['path'])
        ) {
            return null;
        }

        $decodedPath = $parts['path'];
        for ($iteration = 0; $iteration < 3; $iteration++) {
            $nextDecodedPath = rawurldecode($decodedPath);
            if ($nextDecodedPath === $decodedPath) {
                break;
            }
            $decodedPath = $nextDecodedPath;
        }
        if (
            strpos($decodedPath, '//') === 0 ||
            preg_match('/[\\x00-\\x1F\\x7F\\\\]/', $decodedPath) === 1
        ) {
            return null;
        }
        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        unset($query[self::TOKEN_PARAMETER], $query[self::RETURN_URL_PARAMETER]);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $parts['path'] . ($queryString === '' ? '' : '?' . $queryString);
    }

    /**
     * Returns the configured user route as a normalized app-relative path.
     *
     * @return string
     */
    private function getUserRoute(): string
    {
        $userRoute = '/' . ltrim($this->userRoute, '/');
        return $userRoute === '/' ? '/' : rtrim($userRoute, '/');
    }

    /**
     * Returns the absolute user URL, optionally with the conventional trailing slash used by login links.
     *
     * @param bool $trailingSlash
     * @return string
     */
    private function getAbsoluteUserUrl(bool $trailingSlash = true): string
    {
        $userUrl = rtrim($this->configuration->getString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL), '/')
            . $this->getUserRoute();
        return $trailingSlash && $this->getUserRoute() !== '/' ? $userUrl . '/' : $userUrl;
    }
}
