<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

class SessionRepository
{
    private Connection $db;

    public function __construct(?Connection $db = null, ?ApplicationContext $context = null)
    {
        $this->db = $db ?? Connection::fromContext($context);
    }

    /** @return array<string, mixed>|null */
    public function getActiveByUuid(string $sessionUuid): ?array
    {
        if ($sessionUuid === '') {
            return null;
        }

        $result = $this->db->table('auth_sessions')
            ->select(['*'])
            ->where(['uuid' => $sessionUuid, 'status' => 'active'])
            ->limit(1)
            ->get();

        return $result !== [] ? $result[0] : null;
    }

    public function markSeen(string $sessionUuid, ?string $expiresAt = null): bool
    {
        $update = [
            'last_seen_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($expiresAt !== null && $expiresAt !== '') {
            $update['expires_at'] = $expiresAt;
        }

        $affected = $this->db->table('auth_sessions')
            ->where(['uuid' => $sessionUuid, 'status' => 'active'])
            ->update($update);

        return $affected > 0;
    }

    public function revokeByUuid(string $sessionUuid): bool
    {
        $affected = $this->db->table('auth_sessions')
            ->where(['uuid' => $sessionUuid, 'status' => 'active'])
            ->update([
                'status' => 'revoked',
                'revoked_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected > 0;
    }
}
