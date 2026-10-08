<?php

namespace Omnibill\ChorusPro;

use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\ProviderException;
use Omnibill\Http\Answer;
use Omnibill\Http\ClientCredentials;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Chorus Pro's "Factures" API through PISTE: a bearer token (OAuth 2.0
 * client credentials of a PISTE application) and the cpro-account header
 * (the technical account, "login:password" in base64) on each call; every
 * answer carries codeRetour (0: done) and libelle.
 */
final class Api
{
    public const PROVIDER = 'chorus-pro';
    public const API = 'https://api.piste.gouv.fr/cpro/factures';
    public const OAUTH = 'https://oauth.piste.gouv.fr/api/oauth/token';
    public const SANDBOX_API = 'https://sandbox-api.piste.gouv.fr/cpro/factures';
    public const SANDBOX_OAUTH = 'https://sandbox-oauth.piste.gouv.fr/api/oauth/token';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ClientCredentials $credentials,
        #[\SensitiveParameter] private readonly string $account,
        public readonly string $apiUrl = self::API,
        public readonly ?int $userId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed> the answer, codeRetour 0
     */
    public function call(string $path, array $body): array
    {
        $body = array_filter(['idUtilisateurCourant' => $this->userId] + $body, static fn ($v) => null !== $v);
        for ($attempt = 0; ; ++$attempt) {
            $answer = Answer::send($this->http, self::PROVIDER, 'POST', $this->apiUrl.$path, [
                'headers' => ['Authorization' => 'Bearer '.$this->credentials->token(), 'cpro-account' => $this->account, 'Accept' => 'application/json'],
                'json' => $body,
            ]);
            if (401 === $answer->status && 0 === $attempt) {
                $this->credentials->reset();
                continue;
            }
            break;
        }
        $data = json_decode($answer->body, true);
        if (\in_array($answer->status, [401, 403], true)) {
            throw new InvalidKeyException(self::PROVIDER, \sprintf('PISTE or the technical account refused the call (HTTP %d): %s', $answer->status, \is_array($data) ? ($data['libelle'] ?? $data['message'] ?? $data['error'] ?? '') : mb_substr(trim($answer->body), 0, 200)), (string) $answer->status);
        }
        if (!\is_array($data)) {
            throw new ProviderException(self::PROVIDER, \sprintf('HTTP %d, not a JSON answer: %s', $answer->status, mb_substr(trim($answer->body), 0, 200)));
        }
        if (0 !== (int) ($data['codeRetour'] ?? -1) || $answer->status >= 400) {
            throw new ProviderException(self::PROVIDER, \sprintf('%s answered %s: %s', $path, $data['codeRetour'] ?? $answer->status, $data['libelle'] ?? 'no libelle'), isset($data['codeRetour']) ? (string) $data['codeRetour'] : (string) $answer->status);
        }

        return $data;
    }
}
