<?php

declare(strict_types=1);

namespace App\Security;

use App\Clock\Clock;
use App\Exception\RateLimitException;
use App\Repository\LoginAttemptRepository;

final readonly class LoginRateLimiter
{
    /** Échecs acceptés pour un même compte, toutes adresses confondues. */
    private const MAX_IDENTIFIER_ATTEMPTS = 5;
    /**
     * Échecs acceptés depuis une même adresse, tous comptes confondus. La
     * limite est plus haute qu'au compte : plusieurs personnes partagent une
     * adresse (famille, NAT d'entreprise) sans être une cible.
     */
    private const MAX_ADDRESS_ATTEMPTS = 20;
    private const WINDOW = '15 minutes';

    public function __construct(
        private LoginAttemptRepository $attempts,
        private Clock $clock,
        private string $secret,
    ) {}

    public function assertAllowed(string $username, string $address): void
    {
        $identifierHash = $this->identifierHash($username);
        $addressHash = $this->addressHash($address);
        $since = $this->clock->now()->modify('-' . self::WINDOW)->format('Y-m-d\TH:i:s\Z');
        // Les deux seaux sont comptés séparément : sans cela, un compte visé
        // depuis des adresses changeantes n'est jamais bloqué, et un compte
        // connu peut être verrouillé depuis cinq adresses différentes. Même
        // exception dans les deux cas : l'existence d'un compte n'est pas
        // exposée.
        if ($this->attempts->countByIdentifierSince($identifierHash, $since) >= self::MAX_IDENTIFIER_ATTEMPTS) {
            throw new RateLimitException();
        }
        if ($this->attempts->countByAddressSince($addressHash, $since) >= self::MAX_ADDRESS_ATTEMPTS) {
            throw new RateLimitException();
        }
    }

    public function recordFailure(string $username, string $address): void
    {
        $identifierHash = $this->identifierHash($username);
        $addressHash = $this->addressHash($address);
        $now = $this->clock->now();
        $this->attempts->add(
            $identifierHash,
            $addressHash,
            $now->format('Y-m-d\TH:i:s\Z'),
        );
        $this->attempts->pruneBefore($now->modify('-1 day')->format('Y-m-d\TH:i:s\Z'));
    }

    /**
     * Une connexion réussie oublie les échecs du compte, quelle que soit leur
     * adresse : le détenteur vient de prouver qu'il connaît le mot de passe.
     * Le seau d'adresse n'est pas vidé, sinon un attaquant disposant d'un seul
     * compte valide pourrait réarmer la protection contre le remplissage.
     */
    public function clear(string $username): void
    {
        $this->attempts->clearByIdentifier($this->identifierHash($username));
    }

    private function identifierHash(string $username): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($username), 'UTF-8'), $this->secret);
    }

    private function addressHash(string $address): string
    {
        return hash_hmac('sha256', $address, $this->secret);
    }
}
