<?php

namespace App\Enums;

class TipoEncuesta
{
    public const SESION = 1;
    public const CURSO = 2;

    public static function esSesion(int $tipo): bool
    {
        return $tipo === self::SESION;
    }

    public static function esCurso(int $tipo): bool
    {
        return $tipo === self::CURSO;
    }
}