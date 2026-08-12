<?php

namespace App\Services\Support;

/**
 * Wrapper simple para resultados de servicios.
 */
class ServiceResult
{
    public function __construct(
        private readonly bool $ok,
        private readonly mixed $data,   // 👈 CAMBIAR AQUÍ
        private readonly int $status,
        private readonly array $error
    ) {
    }

    public static function success(mixed $data, int $status = 200): self   // 👈 CAMBIAR AQUÍ
    {
        return new self(true, $data, $status, []);
    }

    public static function failure(array $error, int $status = 0): self
    {
        return new self(false, null, $status, $error);   // 👈 mejor null que []
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function data(): mixed   // 👈 CAMBIAR AQUÍ
    {
        return $this->data;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function error(): array
    {
        return $this->error;
    }
    public function failed(): bool
    {
        return $this->status !== 200;
    }
}