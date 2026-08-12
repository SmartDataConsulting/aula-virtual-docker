<?php

namespace App\Support;

/**
 * Llaves usadas en la sesion de autenticacion.
 */
class AuthSessionKeys
{
    public const LOGGED_IN = 'logged_in';
    public const USER_ID = 'user_id';
    public const USER_EMAIL = 'user_email';
    public const USER_NAME = 'user_name';
    public const JWT_TOKEN = 'jwt_token';
    public const USER_ROLE = 'user_role';

    public static function all(): array
    {
        return [
            self::LOGGED_IN,
            self::USER_ID,
            self::USER_EMAIL,
            self::USER_NAME,
            self::JWT_TOKEN,
            self::USER_ROLE, 
        ];
    }
}
