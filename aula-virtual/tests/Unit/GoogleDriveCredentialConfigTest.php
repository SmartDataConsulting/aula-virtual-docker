<?php

namespace Tests\Unit;

use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class GoogleDriveCredentialConfigTest extends TestCase
{
    public function test_absolute_service_account_path_is_preserved(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'portal-drive-');

        try {
            config(['services.google_drive.service_account_path' => $path]);

            $this->assertSame($path, $this->invokePrivate(new GoogleDriveService(), 'resolveServiceAccountPath'));
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_relative_service_account_path_is_resolved_from_project_root(): void
    {
        config(['services.google_drive.service_account_path' => 'var/credentials/google.json']);

        $this->assertSame(
            base_path('var/credentials/google.json'),
            $this->invokePrivate(new GoogleDriveService(), 'resolveServiceAccountPath')
        );
    }

    public function test_missing_service_account_configuration_fails_safely(): void
    {
        config(['services.google_drive.service_account_path' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH no esta configurada');

        $this->invokePrivate(new GoogleDriveService(), 'resolveServiceAccountPath');
    }

    public function test_missing_service_account_file_fails_safely(): void
    {
        config(['services.google_drive.service_account_path' => sys_get_temp_dir().'/missing-portal-drive.json']);
        Cache::forget('google_drive_service.access_token');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No se encontro un archivo legible');

        $this->invokePrivate(new GoogleDriveService(), 'getAccessToken');
    }

    public function test_invalid_json_does_not_leak_credential_content(): void
    {
        $path = $this->temporaryCredential('not-json-sensitive-marker');
        config(['services.google_drive.service_account_path' => $path]);
        Cache::forget('google_drive_service.access_token');

        try {
            $this->invokePrivate(new GoogleDriveService(), 'getAccessToken');
            $this->fail('Expected invalid JSON to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('JSON valido', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive-marker', $exception->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_incomplete_credentials_fail_without_exposing_fixture_values(): void
    {
        $path = $this->temporaryCredential('{"client_email":"fixture-only.invalid"}');
        config(['services.google_drive.service_account_path' => $path]);
        Cache::forget('google_drive_service.access_token');

        try {
            $this->invokePrivate(new GoogleDriveService(), 'getAccessToken');
            $this->fail('Expected incomplete credentials to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('incompleto', $exception->getMessage());
            $this->assertStringNotContainsString('fixture-only.invalid', $exception->getMessage());
        } finally {
            unlink($path);
        }
    }

    private function invokePrivate(object $target, string $method): mixed
    {
        return (new ReflectionMethod($target, $method))->invoke($target);
    }

    private function temporaryCredential(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'portal-drive-');
        file_put_contents($path, $contents);

        return $path;
    }
}
