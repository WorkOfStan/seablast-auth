<?php

declare(strict_types=1);

namespace Seablast\Auth\Models;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Seablast\Auth\AuthConstant;
use Seablast\Seablast\SeablastConfiguration;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * API receives social token and retrieves email.
 *
 * The login overrides the current login.
 * If the login fails, make sure to logout.
 * https://developers.facebook.com/docs/instagram-platform/reference/me/ describes how to connect to Facebook.
 */
class SocialLoginFacebook
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
     * Call Facebook API and retrieve email.
     *
     * @param string $accessToken
     * @return ?mixed[]
     */
    public function authTokenToPayload(string $accessToken): ?array
    {
        if (!$this->configuration->exists(AuthConstant::FACEBOOK_APP_ID)) {
            return null;
        }

        // Create a new Guzzle client
        $client = new Client();

        // Define the Graph API URL
        $url = 'https://graph.facebook.com/me';

        try {
            // Make a GET request to Facebook's Graph API
            $response = $client->request('GET', $url, [
                'query' => [
                    'fields' => 'id,name,email', // TODO reduce to email only?
                    'access_token' => $accessToken
                ]
            ]);

            // Get the response body and decode it
            $body = $response->getBody();
            $data = json_decode($body->getContents(), true);
            if (!is_array($data)) {
                $jsonError = json_last_error() . ': ' . json_last_error_msg();
                Debugger::barDump($jsonError, 'Facebook call result error');
                Debugger::log('Facebook call result error: ' . $jsonError, ILogger::ERROR);
                return null;
            }
            // Return contains the user's email if it exists
            return $data;
        } catch (RequestException $e) {
            // Handle the exception (e.g., log the error, display a message, etc.)
            Debugger::barDump('Error fetching user email: ' . $e->getMessage(), 'Facebook API call failed');
            Debugger::log('Facebook API call failed: Error fetching user email: ' . $e->getMessage(), ILogger::ERROR);
            return null;
        }
    }
}
