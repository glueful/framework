<?php

declare(strict_types=1);

namespace Glueful\Auth\Contracts;

/**
 * Decorates user *records* (read payloads) with extra fields owned by another extension.
 *
 * The symmetric counterpart to {@see IdentityClaimsProviderInterface}: where that enriches the one
 * AUTHENTICATED identity with claims at login, this enriches an arbitrary BATCH of user records that
 * a read endpoint (e.g. the identity store's `/users` list, `/users/{uuid}`, `/me`) is about to
 * return. It lets an authorization extension (e.g. glueful/aegis) attach a user's `roles` — or a
 * future extension attach teams/departments — without the identity store ever depending on it.
 *
 * Register implementations with the container tag **`users.record_enricher`**; the consumer collects
 * every tagged service and merges their output into each record. Implementations MUST:
 *  - be batch-friendly (resolve all UUIDs in one query — never N+1),
 *  - return ONLY additive fields (they cannot change identity facts), and
 *  - omit users they have nothing for (a missing key means "no extra fields").
 */
interface UserRecordEnricherInterface
{
    /**
     * @param list<string> $userUuids the user UUIDs in the batch being returned
     * @return array<string, array<string, mixed>> uuid => extra fields to merge into that record
     */
    public function enrich(array $userUuids): array;
}
