# Script para testear endpoints expuestos en routes/web.php
# Ejecuta cada curl y muestra si responde correctamente (HTTP 200)

$baseUrl = if ($env:API_SERVICIOS_BASE_URL) { "$($env:API_SERVICIOS_BASE_URL.TrimEnd('/'))/v1" } else { "http://localhost:8001/v1" }
$token = if ($env:INTERNAL_SERVICE_TOKEN) { $env:INTERNAL_SERVICE_TOKEN } else { "change-me" }
$emails = @{ "profesor" = "ahuaccachi28@gmail.com"; "alumno" = "david.arias.my@gmail.com" }

function Test-Endpoint {
    param(
        [string]$url,
        [string]$rol = $null,
        [string]$method = "GET",
        [string]$data = $null,
        [bool]$correoQuery = $false,
        [bool]$correoBody = $false
    )

    $headers = @("X-INTERNAL-SERVICE-TOKEN: $token")
    if ($rol) { $headers += "X-USER-ROL: $rol" }

    $finalUrl = $url
    $finalData = $data

    if ($correoQuery -and $rol -and $emails.ContainsKey($rol)) {
        if ($finalUrl -match '\?') {
            $finalUrl += "&correo=$($emails[$rol])"
        } else {
            $finalUrl += "?correo=$($emails[$rol])"
        }
    }

    if ($correoBody -and $rol -and $emails.ContainsKey($rol)) {
        $finalData = @{ correo = $emails[$rol] } | ConvertTo-Json -Compress
    }

    $curlArgs = @("-s", "-w", "|||%{http_code}")

    foreach ($h in $headers) { 
        $curlArgs += "-H"
        $curlArgs += $h
    }

    $curlArgs += "-X"
    $curlArgs += $method
    # 🔥 Si es endpoint de descarga, no capturar body (binario)
    if ($method -eq "GET" -and $finalUrl -match "/descargar") {
        $curlArgs += "-o"
        $curlArgs += "NUL"
    }
    $tempFile = $null

    # 🔥 SIEMPRE usar archivo temporal para JSON
    if ($finalData) {

        $tempFile = [System.IO.Path]::GetTempFileName()
        $utf8NoBom = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllText($tempFile, $finalData, $utf8NoBom)

        $curlArgs += "-H"
        $curlArgs += "Content-Type: application/json"

        $curlArgs += "--data-binary"
        $curlArgs += "@$tempFile"
    }

    $curlArgs += $finalUrl

    Write-Host "curl.exe $($curlArgs -join ' ')" -ForegroundColor Cyan

    $result = & curl.exe @curlArgs

    if ($tempFile) { Remove-Item $tempFile -ErrorAction SilentlyContinue }

    $split = $result -split '\|\|\|'
    $body = $split[0].Trim()
    $code = if ($split.Length -gt 1) { $split[1].Trim() } else { "" }

    if ($code -eq "200") {
        Write-Host "[OK] $method $finalUrl" -ForegroundColor Green
    } else {
        Write-Host "[FAIL] $method $finalUrl (HTTP $code)" -ForegroundColor Red
        Write-Host $body -ForegroundColor Yellow
    }
}
Write-Host ""
Write-Host "===== TEST ENDPOINTS CRUD =====" -ForegroundColor Cyan


# --- CURSOS ---
# GET /v1/cursos
Write-Host "[TEST] GET /v1/cursos" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/cursos" "profesor" "GET" $null $true $false

# --- CURSO POR ID ---
# GET /v1/cursos/{id}
Write-Host "[TEST] GET /v1/cursos/1 (alumno)" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/cursos/1" "alumno" "GET" $null $true $false

# --- SESIONES POR CURSO ---
# GET /v1/curso/{cursoId}/sesiones
Write-Host "[TEST] GET /v1/curso/1/sesiones" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/curso/1/sesiones" "profesor" "GET" $null $true $false

# --- ANUNCIOS ---
# GET /v1/anuncios/{entidadTipo}/{entidadId}
Write-Host "[TEST] GET /v1/anuncios/curso/1" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/anuncios/curso/1" "profesor" "GET" $null $false $false
Write-Host "[TEST] GET /v1/anuncios/sesion/1" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/anuncios/sesion/1" "profesor" "GET" $null $false $false
# POST /v1/anuncios/{entidadTipo}/{entidadId}/con-lectura
Write-Host "[TEST] POST /v1/anuncios/curso/1/con-lectura" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/anuncios/curso/1/con-lectura" "profesor" "POST" $null $false $true
Write-Host "[TEST] POST /v1/anuncios/sesion/1/con-lectura" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/anuncios/sesion/1/con-lectura" "profesor" "POST" $null $false $true
# POST /v1/anuncios
$anuncioObj = @{ entidad_tipo = "curso"; entidad_id = 1; titulo = "Test anuncio"; contenido = "Desde PS"; creado_por = 1 }
$anuncioData = $anuncioObj | ConvertTo-Json -Compress
# Crear archivo temporal JSON sin BOM
$anuncioTempFile = [System.IO.Path]::GetTempFileName()
$utf8NoBom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllText($anuncioTempFile, $anuncioData, $utf8NoBom)
Write-Host "[TEST] POST /v1/anuncios" -ForegroundColor Cyan
$curlManual = 'curl.exe -X POST "' + $baseUrl + '/anuncios" -H "X-INTERNAL-SERVICE-TOKEN: ' + $token + '" -H "X-USER-ROL: profesor" -H "Content-Type: application/json" --data-binary @' + $anuncioTempFile + '"'
Write-Host $curlManual -ForegroundColor Cyan
$result = & curl.exe -s -w "|||%{http_code}" -H "X-INTERNAL-SERVICE-TOKEN: $token" -H "X-USER-ROL: profesor" -H "Content-Type: application/json" -X POST --data-binary @$anuncioTempFile "$baseUrl/anuncios"
$split = $result -split "\|\|\|"
$body = $split[0].Trim()
$code = if ($split.Length -gt 1) { $split[1].Trim() } else { "" }
if ($code -eq "200") {
    Write-Host "[OK] POST $baseUrl/anuncios" -ForegroundColor Green
} else {
    Write-Host "[FAIL] POST $baseUrl/anuncios (HTTP $code): $body" -ForegroundColor Red
    Write-Host "--- BODY ---" -ForegroundColor DarkYellow
    Write-Host $body -ForegroundColor Yellow
    Write-Host "--- END BODY ---" -ForegroundColor DarkYellow
}
Remove-Item $anuncioTempFile -ErrorAction SilentlyContinue
# Obtener ID anuncio creado
$anuncios = & curl.exe -s -H "X-INTERNAL-SERVICE-TOKEN: $token" -H "X-USER-ROL: profesor" "$baseUrl/anuncios/curso/1"
$anuncioId = ($anuncios | ConvertFrom-Json)[0].id
Write-Host "Anuncio ID: $anuncioId" -ForegroundColor Yellow
if (-not $anuncioId) { Write-Host "Abortado anuncios"; return }
# PUT /v1/anuncios/{anuncioId}
$anuncioEditObj = @{ titulo = "Editado PS"; contenido = "Editado"; tipo = "importante"; editado_por = 1 }
$anuncioEditData = $anuncioEditObj | ConvertTo-Json -Compress
Write-Host "[TEST] PUT /v1/anuncios/$anuncioId" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/anuncios/$anuncioId" "profesor" "PUT" $anuncioEditData $false $false
# DELETE /v1/anuncios/{anuncioId}
Write-Host "[TEST] DELETE /v1/anuncios/$anuncioId" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/anuncios/$anuncioId" "profesor" "DELETE" $null $false $false
# POST /v1/anuncios/{anuncioId}/leer
Write-Host "[TEST] POST /v1/anuncios/$anuncioId/leer" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/anuncios/$anuncioId/leer" "profesor" "POST" $null $false $true
# POST /v1/anuncios/{entidadTipo}/{entidadId}/leer-todos
Write-Host "[TEST] POST /v1/anuncios/curso/1/leer-todos" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/anuncios/curso/1/leer-todos" "profesor" "POST" $null $false $true

# --- CRUD SESION MATERIAL ---
$sesionId = 611
# GET /v1/sesiones/{sesionId}/materiales
Write-Host "[TEST] GET /v1/sesiones/$sesionId/materiales" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/sesiones/$sesionId/materiales" "profesor" "GET" $null $false $false
# POST /v1/sesiones/{sesionId}/materiales
$createObj = @{
    curso_edicion_sesion_id = $sesionId
    titulo = "Material PS"
    descripcion = "Creado desde PowerShell"
    tipo = "link"
    url_externa = "https://example.com"
    orden = 1
    subido_por = 1
}
$createData = $createObj | ConvertTo-Json -Compress
Write-Host "[TEST] POST /v1/sesiones/$sesionId/materiales" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/sesiones/$sesionId/materiales" "profesor" "POST" $createData $false $false
# Obtener el ID del material creado
$list = & curl.exe -s -H "X-INTERNAL-SERVICE-TOKEN: $token" -H "X-USER-ROL: profesor" "$baseUrl/sesiones/$sesionId/materiales"
$materialId = ($list | ConvertFrom-Json)[0].id
Write-Host "Material ID: $materialId" -ForegroundColor Yellow
if (-not $materialId) { Write-Host "Abortado materiales"; return }
# PUT /v1/sesiones/{sesionId}/materiales/{id}
$updateObj = @{
    curso_edicion_sesion_id = $sesionId
    titulo = "Material ACTUALIZADO"
    descripcion = "Update"
    tipo = "link"
    url_externa = "https://openai.com"
    orden = 2
}
$updateData = $updateObj | ConvertTo-Json -Compress
Write-Host "[TEST] PUT /v1/sesiones/$sesionId/materiales/$materialId" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/sesiones/$sesionId/materiales/$materialId" "profesor" "PUT" $updateData $false $false
# DELETE /v1/sesiones/{sesionId}/materiales/{id}
Write-Host "[TEST] DELETE /v1/sesiones/$sesionId/materiales/$materialId" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/sesiones/$sesionId/materiales/$materialId" "profesor" "DELETE" $null $false $false
# GET /v1/materiales/{id}/descargar
$downloadId = 50
Write-Host "[TEST] GET /v1/materiales/$downloadId/descargar" -ForegroundColor Cyan
Test-Endpoint "$baseUrl/materiales/$downloadId/descargar" "profesor" "GET" $null $false $false

Write-Host "===== FIN CRUD =====" -ForegroundColor Cyan
