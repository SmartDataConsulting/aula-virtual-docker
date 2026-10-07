<?php

namespace App\Support;

final class AulaProfile
{
    public static function role(): string
    {
        // Old operator sessions may belong to teachers: never fall back to the system role.
        return BackofficePermission::normalizeRole(session(AuthSessionKeys::AULA_ROLE, ''));
    }
}
