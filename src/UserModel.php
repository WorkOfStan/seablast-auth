<?php

declare(strict_types=1);

namespace Seablast\Auth;

use Seablast\Auth\AuthConstant;
use Seablast\Seablast\SeablastConfiguration;
use Seablast\Seablast\SeablastConstant;
use Seablast\Seablast\SeablastModelInterface;
use Seablast\Seablast\Superglobals;
use stdClass;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;
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

    /** @var SeablastConfiguration */
    private $configuration;
    /** @var Superglobals */
    private $superglobals;
    /** @var IdentityManager */
    private $user;

    /**
     *
     * @param SeablastConfiguration $configuration
     * @param Superglobals $superglobals
     */
    public function __construct(SeablastConfiguration $configuration, Superglobals $superglobals)
    {
        $this->configuration = $configuration;
        $this->superglobals = $superglobals;
        $this->user = new IdentityManager($this->configuration->dbms());
    }

    /**
     * Different reaction to different authentization state
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
                        . '/user', // Todo go home instead?
                ];
            }
            return (object) [
                    'showLogin' => false,
                    'showLogout' => true,
                    'message' => 'Již jste přihlášeni jako ' . $this->user->getEmail() . ', užijte si to',
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
                    // todo go to the original target insted of /user
                    return (object) [
                            'redirectionUrl' =>
                            $this->configuration->getString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL) . '/user',
                    ];
                }
                return (object) [
                        'showLogin' => true,
                        'showLogout' => false,
                        'message' => 'Invalid token.',
                ];
            }
            // auto re-login attempt
            if ($this->user->doYouRememberMe($_COOKIE)) {
                Debugger::barDump('Auto-relogin.');
                return (object) [// exactly the same as with valid token
                        'redirectionUrl' =>
                        $this->configuration->getString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL) . '/user',
                ];
            }
            // první přístup
            return (object) [
                    'showLogin' => true,
                    'showLogout' => false,
                    'message' => 'Zalogujte se. Na zadaný email vám přijde webová adresa, přes kterou se přihlásíte.'
                    . ' Žádná hesla nejsou třeba.',
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
                Assert::string($this->superglobals->post['csrfToken']);
                $csrfTokenManager = new CsrfTokenManager();
                if (
                    !$csrfTokenManager->isTokenValid(new CsrfToken('sb_json', $this->superglobals->post['csrfToken']))
                ) {
                    Debugger::barDump("CSRF token mismatch", 'ERROR on input');
                    Debugger::log("CSRF token mismatch", ILogger::ERROR);
                    return (object) [
                            'showLogin' => true,
                            'showLogout' => false,
                            'message' => 'Token mismatch.',
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
                        'message' => 'Na zadaný email vám přijde přihlašovací adresa. Proklikněte jí.'
                        . ' Žádná hesla nejsou třeba.',
                ];
            }
        }
        throw new \Exception('Wrong HTTP request: ' . $this->superglobals->server['REQUEST_METHOD']);
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
        $loginUrl = $this->configuration->getString(SeablastConstant::SB_APP_ROOT_ABSOLUTE_URL)
            . '/user/?token=' . $token; // TODO session should keep the original target URL - deep loging
        Debugger::barDump($loginUrl, $this->user->isNewUser() ? 'registerUrl' : 'loginUrl');
        $plainText = str_replace(
            '%URL%',
            $loginUrl,
            $this->configuration->getString(
                $this->user->isNewUser() ? AuthConstant::TEXT_EMAIL_REGISTRATION : AuthConstant::TEXT_EMAIL_LOGIN
            )
        );
        Debugger::barDump($plainText, 'plainText');
        if (!$this->configuration->flag->status(SeablastConstant::USER_MAIL_ENABLED)) {
            Debugger::barDump('Sending emails is not enabled');
            return;
        }
        $transport = Transport::fromDsn(
            'smtp://' . SeablastConstant::SB_SMTP_HOST . ':' . SeablastConstant::SB_SMTP_PORT
        );
        $mailer = new Mailer($transport);
        $emailInstance = (new Email())
            ->from($this->configuration->getString(SeablastConstant::FROM_MAIL_ADDRESS))
            ->to($emailAddress)
            //->cc('cc@example.com')
            //->bcc('bcc@example.com')
            //->replyTo('fabien@example.com')
            //->priority(Email::PRIORITY_HIGH)
            ->subject($this->configuration->getString(
                $this->user->isNewUser() ? AuthConstant::SUBJECT_EMAIL_REGISTRATION : AuthConstant::SUBJECT_EMAIL_LOGIN
            ))
            ->text($plainText)
        //->html('<p>See Twig integration for better HTML integration!</p>')
        ;
        $mailer->send($emailInstance);
        Debugger::barDump($this->configuration->getString(SeablastConstant::FROM_MAIL_ADDRESS), 'Email sent from');
    }
}
