<?php

declare(strict_types=1);

namespace Seablast\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Seablast\Seablast\SeablastConfiguration;

class TurnstileVerifier
{
    /** @var ClientInterface */
    private $client;
    /** @var SeablastConfiguration */
    private $configuration;

    public function __construct(SeablastConfiguration $configuration, ?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client();
        $this->configuration = $configuration;
    }

    private function configuredString(string $key): string
    {
        return $this->configuration->exists($key) ? trim($this->configuration->getString($key)) : '';
    }

    public function isEnabled(): bool
    {
        return $this->configuredString(AuthConstant::CLOUDFLARE_TURNSTILE_SITE_KEY) !== ''
            && $this->configuredString(AuthConstant::CLOUDFLARE_TURNSTILE_SECRET_KEY) !== '';
    }

    public function verify(string $token): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }
        $hostnames = array_filter(array_map(
            'trim',
            explode(',', $this->configuredString(AuthConstant::CLOUDFLARE_TURNSTILE_HOSTNAMES))
        ));
        if (trim($token) === '' || strlen($token) > 2048 || $hostnames === []) {
            return false;
        }
        try {
            $response = $this->client->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'allow_redirects' => false,
                'connect_timeout' => 3,
                'form_params' => [
                    'secret' => $this->configuredString(AuthConstant::CLOUDFLARE_TURNSTILE_SECRET_KEY),
                    'response' => $token,
                ],
                'http_errors' => false,
                'timeout' => 10,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return false;
            }
            $result = json_decode((string) $response->getBody(), true);
            return is_array($result)
                && ($result['success'] ?? null) === true
                && ($result['action'] ?? null) === 'login_email'
                && is_string($result['hostname'] ?? null)
                && in_array($result['hostname'], $hostnames, true);
        } catch (GuzzleException $exception) {
            // Never log the exception: its request contains the secret and the submitted token.
            return false;
        }
    }
}
