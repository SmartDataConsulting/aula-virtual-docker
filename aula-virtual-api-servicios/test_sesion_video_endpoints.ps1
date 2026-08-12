# Script para testear endpoints reales de SesionVideoController.
# Usa $env:API_SERVICIOS_BASE_URL y $env:INTERNAL_SERVICE_TOKEN si existen.

$baseUrl = if ($env:API_SERVICIOS_BASE_URL) { "$($env:API_SERVICIOS_BASE_URL.TrimEnd('/'))/v1" } else { "http://localhost:8001/v1" }
$token = if ($env:INTERNAL_SERVICE_TOKEN) { $env:INTERNAL_SERVICE_TOKEN } else { "change-me" }
$sesionId = if ($env:SESION_ID) { [int]$env:SESION_ID } else { 611 }

function Test-VideoEndpoint {
    param(
        [string]$url,
        [string]$method = "GET",
        [string]$data = $null
    )

    $headers = @("X-INTERNAL-SERVICE-TOKEN: $token")
    $curlArgs = @("-s", "-w", "|||%{http_code}")

    foreach ($h in $headers) {
        $curlArgs += "-H"
        $curlArgs += $h
    }

    $curlArgs += "-X"
    $curlArgs += $method

    $tempFile = $null
    if ($data) {
        $tempFile = [System.IO.Path]::GetTempFileName()
        $utf8NoBom = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllText($tempFile, $data, $utf8NoBom)
        $curlArgs += "-H"
        $curlArgs += "Content-Type: application/json"
        $curlArgs += "--data-binary"
        $curlArgs += "@$tempFile"
    }

    $curlArgs += $url
    Write-Host "curl.exe $($curlArgs -join ' ')" -ForegroundColor Cyan

    $result = & curl.exe @curlArgs
    if ($tempFile) { Remove-Item $tempFile -ErrorAction SilentlyContinue }

    $split = $result -split '\|\|\|'
    $body = $split[0].Trim()
    $code = if ($split.Length -gt 1) { $split[1].Trim() } else { "" }

    if ($code -eq "200") {
        Write-Host "[OK] $method $url" -ForegroundColor Green
    } else {
        Write-Host "[FAIL] $method $url (HTTP $code)" -ForegroundColor Red
        Write-Host $body -ForegroundColor Yellow
        try {
            $json = $body | ConvertFrom-Json
            if ($json.correlation_id) {
                Write-Host "Correlation ID: $($json.correlation_id)" -ForegroundColor Magenta
            }
        } catch {}
    }
}

Write-Host ""
Write-Host "===== TEST SESION VIDEO ENDPOINTS =====" -ForegroundColor Cyan

$startData = @{
    upload_url = "https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=test"
    filename = "video_test.mp4"
    mime_type = "video/mp4"
    filesize = 12345678
} | ConvertTo-Json -Compress
Write-Host "[TEST] POST /v1/sesiones/$sesionId/video/upload-started" -ForegroundColor Cyan
Test-VideoEndpoint "$baseUrl/sesiones/$sesionId/video/upload-started" "POST" $startData

Write-Host "[TEST] GET /v1/sesiones/$sesionId/video/upload-progress" -ForegroundColor Cyan
Test-VideoEndpoint "$baseUrl/sesiones/$sesionId/video/upload-progress" "GET"

$progressData = @{ upload_id = 1; bytes_uploaded = 1024; status = "uploading" } | ConvertTo-Json -Compress
Write-Host "[TEST] POST /v1/sesiones/$sesionId/video/upload-progress" -ForegroundColor Cyan
Test-VideoEndpoint "$baseUrl/sesiones/$sesionId/video/upload-progress" "POST" $progressData

$cancelData = @{ upload_id = 1 } | ConvertTo-Json -Compress
Write-Host "[TEST] POST /v1/sesiones/$sesionId/video/upload-cancelled" -ForegroundColor Cyan
Test-VideoEndpoint "$baseUrl/sesiones/$sesionId/video/upload-cancelled" "POST" $cancelData

$finalizeData = @{
    upload_id = 1
    drive_file_id = "fake_drive_file_id"
    filesize = 12345678
    bytes_uploaded = 12345678
    status = "uploaded"
} | ConvertTo-Json -Compress
Write-Host "[TEST] POST /v1/sesiones/$sesionId/video/upload-completed" -ForegroundColor Cyan
Test-VideoEndpoint "$baseUrl/sesiones/$sesionId/video/upload-completed" "POST" $finalizeData

Write-Host "[TEST] GET /v1/sesiones/$sesionId/video/status" -ForegroundColor Cyan
Test-VideoEndpoint "$baseUrl/sesiones/$sesionId/video/status" "GET"

Write-Host "===== FIN SESION VIDEO ENDPOINTS =====" -ForegroundColor Cyan
