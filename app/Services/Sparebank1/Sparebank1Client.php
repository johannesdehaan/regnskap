<?php

namespace App\Services\Sparebank1;

use App\Enums\BankConnectionStatus;
use App\Exceptions\Sparebank1Exception;
use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class Sparebank1Client
{
    public const ACCEPT = 'application/vnd.sparebank1.v1+json; charset=utf-8';

    public function configured(): bool
    {
        return $this->credential('client_id') !== '' && $this->credential('client_secret') !== '';
    }

    public function redirectUri(): string
    {
        $configured = config('sparebank1.redirect_uri');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return route('sparebank1.callback');
    }

    public function authorizeUrl(string $state, string $finInst = ''): string
    {
        $this->guardConfigured();

        $query = [
            'response_type' => 'code',
            'client_id' => $this->credential('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ];

        $scope = config('sparebank1.scope');

        if (is_string($scope) && $scope !== '') {
            $query['scope'] = $scope;
        }

        if ($finInst !== '') {
            $query['finInst'] = $finInst;
        }

        return $this->endpoint('authorize_url').'?'.http_build_query($query);
    }

    public function connect(User $user, string $code, string $finInst = ''): BankConnection
    {
        $tokens = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->credential('client_id'),
            'client_secret' => $this->credential('client_secret'),
        ]);

        $connection = $user->bankConnection()->firstOrNew([
            'user_id' => $user->id,
        ]);

        if ($finInst !== '') {
            $connection->fin_inst = $finInst;
        }

        $this->persist($connection, $tokens);

        return $connection;
    }

    public function refresh(BankConnection $connection): void
    {
        if ($connection->refresh_token === null || $connection->refresh_token === '') {
            $this->markNeedsReauth($connection, 'Refresh-token mangler. Koble til på nytt med BankID.');

            throw new Sparebank1Exception('Refresh-token mangler. Koble til på nytt med BankID.');
        }

        if ($connection->refresh_token_expires_at?->isPast()) {
            $this->markNeedsReauth($connection, 'Refresh-tokenet er utløpt (30 dager). Koble til på nytt med BankID.');

            throw new Sparebank1Exception('Refresh-tokenet er utløpt (30 dager). Koble til på nytt med BankID.', 'invalid_grant');
        }

        try {
            $tokens = $this->token([
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
                'client_id' => $this->credential('client_id'),
                'client_secret' => $this->credential('client_secret'),
            ]);
        } catch (Sparebank1Exception $exception) {
            if ($exception->oauthError === 'invalid_grant') {
                $this->markNeedsReauth($connection, 'Innloggingen er utløpt. Koble til på nytt med BankID.');
            }

            throw $exception;
        }

        $this->persist($connection, $tokens);
    }

    public function accessToken(BankConnection $connection): string
    {
        $expires = $connection->access_token_expires_at;
        $usable = is_string($connection->access_token)
            && $connection->access_token !== ''
            && $expires !== null
            && $expires->isFuture();

        if (! $usable) {
            $this->refresh($connection);
            $connection->refresh();
        }

        if (! is_string($connection->access_token) || $connection->access_token === '') {
            throw new Sparebank1Exception('Mangler access-token. Koble til på nytt med BankID.');
        }

        return $connection->access_token;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(BankConnection $connection): array
    {
        $response = $this->get($connection, 'accounts', [
            'includeNokAccounts' => 'true',
            'includeCreditCardAccounts' => 'true',
            'includeCurrencyAccounts' => 'true',
            'includeBsuAccounts' => 'true',
        ]);

        $payload = $this->object($response);
        $rows = $payload['accounts'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $accounts = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $accounts[] = $row;
            }
        }

        return $accounts;
    }

    /**
     * @return array{transactions: list<array<string, mixed>>, errors: list<string>}
     */
    public function transactions(BankConnection $connection, string $accountKey, string $from, string $to): array
    {
        $response = $this->get($connection, 'transactions', [
            'accountKey' => $accountKey,
            'fromDate' => $from,
            'toDate' => $to,
            'rowLimit' => 1000,
            'Transaction source' => 'ALL',
        ]);

        $payload = $this->object($response);
        $rows = $payload['transactions'] ?? [];
        $transactions = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $transactions[] = $row;
                }
            }
        }

        return [
            'transactions' => $transactions,
            'errors' => $this->knownErrors($payload['errors'] ?? []),
        ];
    }

    /**
     * @param  array<string, string>  $form
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, token_type: string, scope: ?string}
     */
    private function token(array $form): array
    {
        $this->guardConfigured();

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(30)
                ->withOptions(['allow_redirects' => false, 'http_errors' => false])
                ->post($this->endpoint('token_url'), $form);
        } catch (ConnectionException) {
            throw new Sparebank1Exception('Fikk ikke kontakt med SpareBank 1.');
        }

        if ($response->status() === 429) {
            throw new Sparebank1Exception('SpareBank 1 ba oss vente. Prøv igjen senere.');
        }

        $body = $this->object($response);

        if ($response->failed()) {
            $error = $body['error'] ?? null;
            $code = is_string($error) && preg_match('/^[a-z_]+$/', $error) === 1 ? $error : null;

            throw new Sparebank1Exception($this->tokenErrorMessage($code), $code);
        }

        $accessToken = $body['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new Sparebank1Exception('SpareBank 1 returnerte ikke et access-token.');
        }

        $refresh = $body['refresh_token'] ?? null;
        $expiresIn = $body['expires_in'] ?? null;
        $seconds = is_numeric($expiresIn) ? (int) $expiresIn : (int) config('sparebank1.access_token_ttl');

        if ($seconds <= 0) {
            $seconds = (int) config('sparebank1.access_token_ttl');
        }

        $tokenType = $body['token_type'] ?? 'Bearer';
        $scope = $body['scope'] ?? null;

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            'expires_in' => $seconds,
            'token_type' => is_string($tokenType) && $tokenType !== '' ? $tokenType : 'Bearer',
            'scope' => is_string($scope) && $scope !== '' ? $scope : null,
        ];
    }

    /**
     * @param  array{access_token: string, refresh_token: ?string, expires_in: int, token_type: string, scope: ?string}  $tokens
     */
    private function persist(BankConnection $connection, array $tokens): void
    {
        $margin = (int) config('sparebank1.expiry_margin_seconds');
        $ttl = max(30, $tokens['expires_in'] - $margin);
        $refresh = $tokens['refresh_token'];
        $refreshExpiry = $connection->refresh_token_expires_at;

        if ($refresh === null) {
            $refresh = $connection->refresh_token;
        } elseif ($connection->refresh_token === null || ! hash_equals($connection->refresh_token, $refresh)) {
            $refreshExpiry = now()->addDays((int) config('sparebank1.refresh_token_ttl_days'));
        }

        $connection->fill([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $refresh,
            'token_type' => $tokens['token_type'],
            'scope' => $tokens['scope'] ?? $connection->scope,
            'access_token_expires_at' => now()->addSeconds($ttl),
            'refresh_token_expires_at' => $refreshExpiry ?? now()->addDays((int) config('sparebank1.refresh_token_ttl_days')),
            'status' => BankConnectionStatus::Connected,
            'last_sync_error' => null,
        ])->save();
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function get(BankConnection $connection, string $path, array $query): Response
    {
        $response = $this->send($connection, $path, $query);

        if ($response->status() !== 401) {
            return $this->guardApi($response);
        }

        $this->refresh($connection);
        $connection->refresh();

        return $this->guardApi($this->send($connection, $path, $query));
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function send(BankConnection $connection, string $path, array $query): Response
    {
        try {
            return Http::withToken($this->accessToken($connection))
                ->withHeaders([
                    'Accept' => self::ACCEPT,
                    'User-Agent' => 'personlig-regnskap/sparebank1',
                ])
                ->timeout(30)
                ->withOptions(['allow_redirects' => false, 'http_errors' => false])
                ->get($this->api($path), $query);
        } catch (ConnectionException) {
            throw new Sparebank1Exception('Fikk ikke kontakt med SpareBank 1.');
        }
    }

    private function guardApi(Response $response): Response
    {
        if ($response->status() === 401) {
            throw new Sparebank1Exception('Tilgangen ble avvist. Koble til på nytt med BankID.', 'invalid_grant');
        }

        if ($response->status() === 429) {
            throw new Sparebank1Exception('SpareBank 1 ba oss vente. Prøv igjen senere.');
        }

        if ($response->failed()) {
            throw new Sparebank1Exception('SpareBank 1 svarte med HTTP '.$response->status().'.');
        }

        return $response;
    }

    private function markNeedsReauth(BankConnection $connection, string $message): void
    {
        $connection->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
            'status' => BankConnectionStatus::NeedsReauth,
            'last_sync_error' => $message,
        ])->save();
    }

    private function tokenErrorMessage(?string $code): string
    {
        return match ($code) {
            'invalid_client' => 'SpareBank 1 avslo klienten. Sjekk SPAREBANK1_CLIENT_ID og SPAREBANK1_CLIENT_SECRET.',
            'invalid_grant' => 'Innloggingen er utløpt. Koble til på nytt med BankID.',
            default => 'SpareBank 1 avslo token-forespørselen.',
        };
    }

    /**
     * @return list<string>
     */
    private function knownErrors(mixed $errors): array
    {
        if (! is_array($errors)) {
            return [];
        }

        $known = [];

        foreach ($errors as $error) {
            if ($error === 'CREDIT_ACCOUNTS_FAILED' || $error === 'RECENT_TRANSACTIONS_FAILED') {
                $known[] = $error;
            }
        }

        return $known;
    }

    /**
     * @return array<string, mixed>
     */
    private function object(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            return [];
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $object[$key] = $value;
            }
        }

        return $object;
    }

    private function api(string $path): string
    {
        return rtrim($this->endpoint('api_url'), '/').'/personal/banking/'.ltrim($path, '/');
    }

    private function endpoint(string $key): string
    {
        $value = config('sparebank1.'.$key);

        if (! is_string($value) || $value === '') {
            throw new Sparebank1Exception('SpareBank 1-endepunktet '.$key.' er ikke satt.');
        }

        return $value;
    }

    private function credential(string $key): string
    {
        $value = config('sparebank1.'.$key);

        return is_string($value) ? $value : '';
    }

    private function guardConfigured(): void
    {
        if (! $this->configured()) {
            throw new Sparebank1Exception('SPAREBANK1_CLIENT_ID og SPAREBANK1_CLIENT_SECRET mangler.');
        }
    }
}
