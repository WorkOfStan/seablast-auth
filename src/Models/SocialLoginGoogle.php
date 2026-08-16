<?php

declare(strict_types=1);

namespace Seablast\Auth\Models;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Seablast\Auth\AuthConstant;
use Seablast\Seablast\SeablastConfiguration;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * API receives social token and retrieves email.
 *
 * The login overrides the current login.
 * If the login fails, make sure to logout.
 * Guzzle connects to the tokeninfo/verifyId API.
 * (Instead of using the huge https://github.com/googleapis/google-api-php-client .)
 */
class SocialLoginGoogle
{
    use \Nette\SmartObject;

    /** @var SeablastConfiguration */
    protected $configuration;
    /** @var ClientInterface */
    private $client;

    /**
     * @param SeablastConfiguration $configuration
     * @param ClientInterface|null $client
     */
    public function __construct(SeablastConfiguration $configuration, ?ClientInterface $client = null)
    {
        $this->configuration = $configuration;
        $this->client = $client ?: new Client();
    }

    /**
     * Verify idToken on Google.
     *
     * One-off token is sent to Google server which returns the user identity. If email is returned, log the user in.
     *
     * Replacement of \Google_Client->verifyIdToken which is part of huge (and unused) google/apiclient
     * which is located in the https://github.com/googleapis/google-api-php-client repository.
     *
     * @param string $idToken
     * @return array<string,string>|false
     */
    public function authTokenToPayload(string $idToken)
    {
        // This code below expect "google/apiclient": "*", which is too big a canon
        //$client = new \Google_Client(['client_id' => $this->configuration->getString('GOOGLE_CLIENT_ID')]);
        //return $client->verifyIdToken($authToken);
        // So the code below just uses the Google API directly.
        if (!$this->configuration->exists(AuthConstant::GOOGLE_CLIENT_ID)) {
            return false;
        }
        try {
            $response = $this->client->request('GET', 'https://oauth2.googleapis.com/tokeninfo', [
                'http_errors' => false,
                'query' => ['id_token' => $idToken]
            ]);
        } catch (\Exception $e) {
            Debugger::log('Google tokeninfo request failed: ' . get_class($e), ILogger::ERROR);
            return false;
        }

        if ($response->getStatusCode() === 200) {
            $body = $response->getBody()->getContents();
            $data = $this->normalizePayload(json_decode($body, true));
            // Response conforms to https://github.com/firebase/php-jwt
            // Validate the audience, issuer, email, and expiration if present.
            if (is_null($data)) {
                Debugger::barDump(json_last_error_msg(), "Unexpected Google tokeninfo response");
            } elseif ($this->isValidPayload($data)) {
                return $data;
            } else {
                Debugger::barDump(
                    [
                        'audienceMatches' => isset($data['aud'])
                            && $data['aud'] === $this->configuration->getString(AuthConstant::GOOGLE_CLIENT_ID),
                        'issuer' => $data['iss'] ?? null,
                        'hasEmail' => !empty($data['email']),
                        'hasExpiration' => isset($data['exp']),
                        'emailVerified' => $data['email_verified'] ?? null
                    ],
                    'Google tokeninfo payload did not pass validation.'
                );
            }
        } else {
            Debugger::barDump(
                [
                    'statusCode' => $response->getStatusCode(),
                    'GOOGLE_CLIENT_ID' => $this->configuration->getString(AuthConstant::GOOGLE_CLIENT_ID)
                ],
                'Google tokeninfo request failed.'
            );
        }
        return false;
    }

    /**
     * Validate required Google tokeninfo payload fields.
     *
     * @param array<string,string> $data
     * @return bool
     */
    private function isValidPayload(array $data): bool
    {
        if (
            !isset($data['aud'], $data['iss'], $data['email']) ||
            $data['aud'] !== $this->configuration->getString(AuthConstant::GOOGLE_CLIENT_ID) ||
            !in_array($data['iss'], ['https://accounts.google.com', 'accounts.google.com'], true) ||
            filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false ||
            !isset($data['exp'], $data['email_verified'])
        ) {
            return false;
        }
        $emailVerified = strtolower($data['email_verified']);
        if (!in_array($emailVerified, ['true', '1'], true)) {
            return false;
        }
        // exp is in seconds since epoch.
        return is_numeric($data['exp']) && (int) $data['exp'] > time();
    }

    /**
     * Normalize tokeninfo JSON into a flat string array.
     *
     * @param mixed $data
     * @return array<string,string>|null
     */
    private function normalizePayload($data): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        $output = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                return null;
            }
            $output[$key] = (string) $value;
        }
        return $output;
    }
}
