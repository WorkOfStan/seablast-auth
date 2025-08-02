<?php

declare(strict_types=1);

namespace Seablast\Auth\Models;

use Seablast\Auth\IdentityManager;
use Seablast\Seablast\Apis\GenericRestApiJsonModel;
use Seablast\Seablast\SeablastConfiguration;
use Seablast\Seablast\Superglobals;
use stdClass;
use Tracy\Debugger;
use Tracy\ILogger;
use Webmozart\Assert\Assert;

/**
 * API receives social token and retrieves email, supporting multiple providers.
 *
 * The login overrides the current login.
 * If the login fails, for other reason than mismatch CSRF, user is logged out.
 */
class ApiSocialLoginModel extends GenericRestApiJsonModel
{
    use \Nette\SmartObject;

    /** @var IdentityManager */
    protected $identity;

    /** @var object|null */
    protected $socialProvider = null;

    /**
     * @param SeablastConfiguration $configuration
     * @param Superglobals $superglobals
     */
    public function __construct(SeablastConfiguration $configuration, Superglobals $superglobals)
    {
        parent::__construct($configuration, $superglobals);
    }

    /**
     * Return the knowledge calculated in this model.
     *
     * @return stdClass
     */
    public function knowledge(): stdClass
    {
        /** @var \stdClass $result */
        $result = parent::knowledge();
        if ($result->httpCode >= 400) {
            // Error state means that further processing is not desired
            return $result;
        }
        try {
            $this->identity = new IdentityManager($this->configuration->mysqli());
            $this->identity->setTablePrefix($this->configuration->dbmsTablePrefix());
            $this->identity->setCookiePath(
                $this->configuration->getString(SeablastConstant::SB_SESSION_SET_COOKIE_PARAMS_PATH)
            );
            // Business logic starts here
            // If logged in, log out first
            if ($this->identity->isAuthenticated()) {
                $this->identity->logout();
            }

            $this->executeBusinessLogic();
            Assert::propertyExists($result, 'rest');
            Assert::isInstanceOf($result->rest, \stdClass::class);  // …and `rest` is an object
            $result->rest->message = $this->message;
            $result->httpCode = $this->httpCode;
            if ($this->httpCode === 200) {
                $result->rest->success = 'ok';
            }
        } catch (\Exception $e) {
            $this->httpCode = 500;
            $this->message = 'Unexpected server error';
            Debugger::log($e->getMessage(), ILogger::ERROR);
        }

        return $result;
    }

    /**
     * Process the input by invoking partial calculations.
     *
     * @return void
     * @throws \Exception
     */
    private function executeBusinessLogic(): void
    {
        if ($this->superglobals->server['REQUEST_METHOD'] !== 'POST') {
            $this->httpCode = 405;
            $this->message = 'Invalid request method';
            Debugger::log(
                'Invalid HTTP request method: ' . (string) print_r($this->superglobals->server['REQUEST_METHOD'], true),
                ILogger::WARNING
            );
            return;
        }

        $authToken = $this->data->authToken ?? null;
        $provider = $this->data->provider ?? null;

        if (empty($authToken) || !is_string($authToken)) {
            $this->httpCode = 401;
            $this->message = 'Missing or invalid auth token';
            return;
        }

        if (empty($provider) || !is_string($provider)) {
            $this->httpCode = 401;
            $this->message = 'Missing provider';
            return;
        }

        switch ($provider) {
            case 'google':
                $this->socialProvider = new SocialLoginGoogle($this->configuration);
                break;
            case 'facebook':
                $this->socialProvider = new SocialLoginFacebook($this->configuration);
                break;
            default:
                $this->httpCode = 401;
                $this->message = 'Unsupported provider';
                return;
        }
        $payload = $this->socialProvider->authTokenToPayload($authToken);
        $this->processPayloadEmail($payload, $authToken, 'Login successful - ' . $provider);
    }

    /**
     * Arrange login based on $payload['email'] which should contain the user's email.
     *
     * @param array<mixed>|false|null $payload
     * @param string $authToken Just for logging problems
     * @param string $okMessage
     * @return void
     */
    private function processPayloadEmail($payload, string $authToken, string $okMessage): void
    {
        $this->message = 'Internal login failed';
        $this->httpCode = 500;
        if (is_null($payload)) {
            Debugger::barDump($authToken, 'Invalid ID token');
            Debugger::log('Invalid ID token: ' . $authToken, ILogger::ERROR);
            $this->message = 'Invalid ID token';
            $this->httpCode = 403;
            return;
        } elseif (!isset($payload['email']) || empty($payload['email'])) {
            Debugger::barDump($authToken, 'Missing email for token');
            Debugger::log('Missing email for ID token: ' . $authToken, ILogger::ERROR);
            $this->message = 'Missing email for ID token';
            $this->httpCode = 401;
            return;
        }
        Assert::string($payload['email']);
        $this->identity->loginWithTrustedEmail($payload['email']);
        if ($this->identity->isAuthenticated()) {
            $this->message = $okMessage;
            $this->httpCode = 200;
        }
    }
}
