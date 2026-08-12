<?php

namespace Tests\Feature\Backoffice;

use App\Services\Support\ServiceResult;
use App\Services\VideoService;
use App\Support\AuthSessionKeys;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class SessionVideoRoutesTest extends TestCase
{
    public function test_start_upload_delegates_to_video_service(): void
    {
        $this->mock(VideoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('startUpload')
                ->once()
                ->with(1528, [
                    'filename' => 'clase.mp4',
                    'mime_type' => 'video/mp4',
                    'filesize' => 5242880,
                ])
                ->andReturn(ServiceResult::success([
                    'upload_id' => 77,
                    'bytes_uploaded' => 0,
                    'status' => 'uploading',
                    'file_id' => null,
                ]));
        });

        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->post('/backoffice/courses/39/sessions/1528/video/start-upload', [
                'filename' => 'clase.mp4',
                'mime_type' => 'video/mp4',
                'filesize' => 5242880,
            ]);

        $response->assertOk()
            ->assertJson([
                'upload_id' => 77,
                'bytes_uploaded' => 0,
                'status' => 'uploading',
                'file_id' => null,
            ]);
    }

    public function test_start_upload_validates_required_fields(): void
    {
        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->post('/backoffice/courses/39/sessions/1528/video/start-upload', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filename', 'mime_type', 'filesize']);
    }

    public function test_upload_chunk_validates_required_fields(): void
    {
        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->post('/backoffice/courses/39/sessions/1528/video/upload-chunk', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['chunk', 'upload_id', 'chunk_index', 'total_chunks']);
    }

    public function test_upload_chunk_delegates_to_video_service(): void
    {
        $file = UploadedFile::fake()->create('clase.mp4', 5120, 'video/mp4');

        $this->mock(VideoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('uploadChunk')
                ->once()
                ->with(
                    1528,
                    Mockery::type(UploadedFile::class),
                    77,
                    0,
                    1,
                    [
                        'filename' => 'clase.mp4',
                        'mime_type' => 'video/mp4',
                        'filesize' => 5242880,
                        'start_byte' => 0,
                        'end_byte' => 5242879,
                    ]
                )
                ->andReturn(ServiceResult::success([
                    'status' => 'upload_completed',
                    'bytes_uploaded' => 5242880,
                    'file_id' => 'drive-file-123',
                    'upload_id' => 77,
                ]));
        });

        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->post('/backoffice/courses/39/sessions/1528/video/upload-chunk', [
                'chunk' => $file,
                'upload_id' => 77,
                'chunk_index' => 0,
                'total_chunks' => 1,
                'filename' => 'clase.mp4',
                'mime_type' => 'video/mp4',
                'filesize' => 5242880,
                'start_byte' => 0,
                'end_byte' => 5242879,
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'upload_completed',
                'bytes_uploaded' => 5242880,
                'file_id' => 'drive-file-123',
                'upload_id' => 77,
            ]);
    }

    public function test_finalize_upload_validates_required_fields(): void
    {
        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->post('/backoffice/courses/39/sessions/1528/video/finalize-upload', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['upload_id', 'filesize']);
    }

    public function test_finalize_upload_delegates_to_video_service(): void
    {
        $this->mock(VideoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('finalizeUpload')
                ->once()
                ->with(1528, 'drive-file-123', 77, 5242880)
                ->andReturn(ServiceResult::success([
                    'status' => 'processing',
                    'file_id' => 'drive-file-123',
                    'upload_id' => 77,
                    'bytes_uploaded' => 5242880,
                    'filesize' => 5242880,
                ]));
        });

        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->post('/backoffice/courses/39/sessions/1528/video/finalize-upload', [
                'upload_id' => 77,
                'filesize' => 5242880,
                'file_id' => 'drive-file-123',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'processing',
                'file_id' => 'drive-file-123',
                'upload_id' => 77,
            ]);
    }

    public function test_upload_progress_delegates_to_video_service(): void
    {
        $this->mock(VideoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getUploadProgress')
                ->once()
                ->with(1528)
                ->andReturn(ServiceResult::success([
                    'upload_id' => 77,
                    'bytes_uploaded' => 0,
                    'filesize' => 5242880,
                    'status' => 'uploading',
                    'file_id' => null,
                ]));
        });

        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->get('/backoffice/sessions/1528/video/upload-progress');

        $response->assertOk()
            ->assertJson([
                'upload_id' => 77,
                'bytes_uploaded' => 0,
                'filesize' => 5242880,
                'status' => 'uploading',
                'file_id' => null,
            ]);
    }

    public function test_status_delegates_to_video_service(): void
    {
        $this->mock(VideoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getVideoStatus')
                ->once()
                ->with(1528)
                ->andReturn(ServiceResult::success([
                    'status' => 'ready',
                    'file_id' => 'drive-file-123',
                ]));
        });

        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->get('/backoffice/courses/39/sessions/1528/video/status');

        $response->assertOk()
            ->assertJson([
                'status' => 'ready',
                'file_id' => 'drive-file-123',
            ]);
    }

    public function test_cancel_upload_validates_required_fields(): void
    {
        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->post('/backoffice/courses/39/sessions/1528/video/cancel-upload', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['upload_id']);
    }

    public function test_cancel_upload_delegates_to_video_service(): void
    {
        $this->mock(VideoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('cancelUpload')
                ->once()
                ->with(1528, 77)
                ->andReturn(ServiceResult::success([
                    'status' => 'cancelled',
                    'upload_id' => 77,
                ]));
        });

        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->post('/backoffice/courses/39/sessions/1528/video/cancel-upload', [
                'upload_id' => 77,
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'cancelled',
                'upload_id' => 77,
            ]);
    }

    public function test_delete_video_delegates_to_video_service(): void
    {
        $this->mock(VideoService::class, function (MockInterface $mock) {
            $mock->shouldReceive('deleteVideo')
                ->once()
                ->with(1528)
                ->andReturn(ServiceResult::success([
                    'status' => 'deleted',
                    'file_id' => 'drive-file-123',
                ]));
        });

        $response = $this->withSession($this->authSession())
            ->withHeader('Accept', 'application/json')
            ->delete('/backoffice/courses/39/sessions/1528/video');

        $response->assertOk()
            ->assertJson([
                'status' => 'deleted',
                'file_id' => 'drive-file-123',
            ]);
    }

    private function authSession(): array
    {
        return [
            AuthSessionKeys::LOGGED_IN => true,
            AuthSessionKeys::USER_ID => 37,
            AuthSessionKeys::USER_EMAIL => 'test@smartdata.com.pe',
            AuthSessionKeys::USER_NAME => 'Usuario Test',
            AuthSessionKeys::JWT_TOKEN => null,
            AuthSessionKeys::USER_ROLE => 'admin',
        ];
    }
}
