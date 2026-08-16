<?php

declare(strict_types=1);

namespace Seablast\Auth\Models;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
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
     * Call Facebook API and retrieve email.
     *
     * @param string $accessToken
     * @return ?mixed[]
     */
    public function authTokenToPayload(string $accessToken): ?array
    {
        if (
            !$this->configuration->exists(AuthConstant::FACEBOOK_APP_ID) ||
            !$this->configuration->exists(AuthConstant::FACEBOOK_APP_SECRET)
        ) {
            return null;
        }

        if (!$this->isAccessTokenValidForApp($accessToken)) {
            return null;
        }

        // The HTTP client is injected through the constructor so tests can validate responses without network calls.
        try {
            // Make a GET request to Facebook's Graph API
            $response = $this->client->request('GET', $this->graphUrl('me'), [
                'http_errors' => false,
                'query' => [
                    'fields' => 'id,email',
                    'access_token' => $accessToken
                ]
            ]);

            if ($response->getStatusCode() !== 200) {
                Debugger::log(
                    'Facebook /me request failed with status ' . $response->getStatusCode(),
                    ILogger::ERROR
                );
                return null;
            }
            $data = $this->decodeJsonResponse($response, 'Facebook /me');
            if (!is_array($data)) {
                return null;
            }
            // Return contains the user's email if it exists
            return $data;
        } catch (RequestException $e) {
            // Log only the exception class because Guzzle messages can include token-bearing request URIs.
            Debugger::log('Facebook /me request failed: ' . get_class($e), ILogger::ERROR);
            return null;
        } catch (\Exception $e) {
            // Log only the exception class because Guzzle messages can include token-bearing request URIs.
            Debugger::log('Facebook /me request failed: ' . get_class($e), ILogger::ERROR);
            return null;
        }
    }

    /**
     * Decode a JSON API response without logging token-bearing request details.
     *
     * @param ResponseInterface $response
     * @param string $context
     * @return array<mixed>|null
     */
    private function decodeJsonResponse(ResponseInterface $response, string $context): ?array
    {
        $data = json_decode($response->getBody()->getContents(), true);
        if (!is_array($data)) {
            $jsonError = json_last_error() . ': ' . json_last_error_msg();
            Debugger::barDump($jsonError, $context . ' result error');
            Debugger::log($context . ' result error: ' . $jsonError, ILogger::ERROR);
            return null;
        }
        return $data;
    }

    /**
     * Returns a versioned Facebook Graph API URL.
     *
     * @param string $path
     * @return string
     */
    private function graphUrl(string $path): string
    {
        // Define the Graph API URL through the configured API version.
        $version = $this->configuration->exists(AuthConstant::FACEBOOK_API_VERSION)
            ? $this->configuration->getString(AuthConstant::FACEBOOK_API_VERSION)
            : 'v21.0';
        return 'https://graph.facebook.com/' . rawurlencode($version) . '/' . ltrim($path, '/');
    }

    /**
     * Validate that Facebook issued the access token for this configured app.
     *
     * @param string $accessToken
     * @return bool
     */
    private function isAccessTokenValidForApp(string $accessToken): bool
    {
        try {
            $response = $this->client->request('GET', $this->graphUrl('debug_token'), [
                'http_errors' => false,
                'query' => [
                    'input_token' => $accessToken,
                    'access_token' => $this->configuration->getString(AuthConstant::FACEBOOK_APP_ID)
                        . '|' . $this->configuration->getString(AuthConstant::FACEBOOK_APP_SECRET),
                ],
            ]);
        } catch (RequestException $e) {
            // Log only the exception class because Guzzle messages can include token-bearing request URIs.
            Debugger::log('Facebook debug_token request failed: ' . get_class($e), ILogger::ERROR);
            return false;
        } catch (\Exception $e) {
            // Log only the exception class because Guzzle messages can include token-bearing request URIs.
            Debugger::log('Facebook debug_token request failed: ' . get_class($e), ILogger::ERROR);
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            Debugger::log(
                'Facebook debug_token request failed with status ' . $response->getStatusCode(),
                ILogger::ERROR
            );
            return false;
        }

        $payload = $this->decodeJsonResponse($response, 'Facebook debug_token');
        if (!isset($payload['data']) || !is_array($payload['data'])) {
            Debugger::log('Facebook debug_token response missing data.', ILogger::ERROR);
            return false;
        }

        $data = $payload['data'];
        if (
            !isset($data['is_valid'], $data['app_id']) ||
            $data['is_valid'] !== true ||
            (string) $data['app_id'] !== $this->configuration->getString(AuthConstant::FACEBOOK_APP_ID)
        ) {
            return false;
        }

        if (isset($data['expires_at']) && is_numeric($data['expires_at'])) {
            $expiresAt = (int) $data['expires_at'];
            if ($expiresAt !== 0 && $expiresAt <= time()) {
                return false;
            }
        }

        return true;
    }
}
