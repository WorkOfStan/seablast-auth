<?php

declare(strict_types=1);

namespace Seablast\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Seablast\Seablast\SeablastConfiguration;
use Tracy\Debugger;
use Tracy\ILogger;

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

    private function deny(string $reason): bool
    {
        Debugger::log('Turnstile verification rejected: ' . $reason, ILogger::DEBUG);
        return false;
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
        if (trim($token) === '') {
            return $this->deny('missing_or_empty_token');
        }
        if (strlen($token) > 2048) {
            return $this->deny('token_too_long');
        }
        if ($hostnames === []) {
            return $this->deny('empty_hostname_allowlist');
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
                return $this->deny('http_status=' . $response->getStatusCode());
            }
            $result = json_decode((string) $response->getBody(), true);
            if (!is_array($result)) {
                return $this->deny('invalid_json_response');
            }
            if (($result['success'] ?? null) !== true) {
                // Only documented codes are logged; arbitrary response values could contain sensitive data.
                $knownCodes = [
                    'missing-input-secret', 'invalid-input-secret', 'missing-input-response',
                    'invalid-input-response', 'bad-request', 'timeout-or-duplicate', 'internal-error',
                ];
                $codes = $result['error-codes'] ?? [];
                $safeCodes = [];
                if (is_array($codes)) {
                    foreach ($codes as $code) {
                        if (is_string($code) && in_array($code, $knownCodes, true)) {
                            $safeCodes[] = $code;
                        }
                    }
                }
                return $this->deny('success_not_true; error_codes=' . implode(',', array_unique($safeCodes)));
            }
            if (($result['action'] ?? null) !== 'login_email') {
                return $this->deny('action_mismatch');
            }
            if (!is_string($result['hostname'] ?? null) || !in_array($result['hostname'], $hostnames, true)) {
                return $this->deny('hostname_mismatch');
            }
            return true;
        } catch (GuzzleException $exception) {
            // Never log the exception: its request contains the secret and the submitted token.
            return $this->deny('connection_or_request_error');
        }
    }
}
