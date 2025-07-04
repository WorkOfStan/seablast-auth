<?php

declare(strict_types=1);

namespace Seablast\Auth\Models;

use GuzzleHttp\Client;
use Seablast\Auth\AuthConstant;
use Seablast\Seablast\SeablastConfiguration;
use Tracy\Debugger;
use Webmozart\Assert\Assert;

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

    /**
     * @param SeablastConfiguration $configuration
     */
    public function __construct(SeablastConfiguration $configuration)
    {
        $this->configuration = $configuration;
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
     * @return array<string>|false
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
        $client = new Client();
        $response = $client->get('https://oauth2.googleapis.com/tokeninfo', [
            'query' => ['id_token' => $idToken]
        ]);

        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody()->getContents(), true);
            // Response conforms to https://github.com/firebase/php-jwt
            // Validate the audience, issuer, etc.
            // TODO check also $data['exp']
            if ($data === false || !is_array($data)) {
                Debugger::barDump($response->getBody()->getContents(), "Unexpected API response");
            } elseif (
                $data['aud'] === $this->configuration->getString(AuthConstant::GOOGLE_CLIENT_ID) &&
                $data['iss'] === 'https://accounts.google.com'
            ) {
                //echo "Token is valid. User information:";
                Assert::allString($data);
                return $data;
            } else {
                Debugger::barDump($data, 'Token is invalid or audience does not match.');
            }
        } else {
            Debugger::barDump(
                [
                    'idToken' => $idToken,
                    'GOOGLE_CLIENT_ID' => $this->configuration->getString(AuthConstant::GOOGLE_CLIENT_ID)
                ],
                'Failed to validate token.'
            );
        }
        return false;
    }
}
