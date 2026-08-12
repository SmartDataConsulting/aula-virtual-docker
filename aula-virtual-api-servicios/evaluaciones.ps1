# =================== PRUEBAS CREAR EVALUACIONES ===================
Write-Host "\n--- /evaluaciones (EvaluacionController@crear) ---" -ForegroundColor Yellow

function Test-CrearEvaluacion {
    param(
        [string]$rol,
        [string]$correo,
        [int]$cursoId,
        [int]$tipo,
        [string]$nombre,
        [string]$desc,
        [string]$expectedCode = "200"
    )
    $url = "$baseUrl/evaluaciones"
    $headers = @(
        "X-INTERNAL-SERVICE-TOKEN: $token",
        "X-USER-ROL: $rol",
        "X-USER-EMAIL: $correo",
        "Content-Type: application/json"
    )
    $bodyObj = @{ curso_id = $cursoId; tipo = $tipo; nombre = $nombre }
    $bodyJson = $bodyObj | ConvertTo-Json -Compress
    $curlArgs = @("-s", "-w", "|||%{http_code}")
    foreach ($h in $headers) {
        $curlArgs += "-H"
        $curlArgs += $h
    }
    $curlArgs += "--data-binary"
    $curlArgs += "@$([System.IO.Path]::GetTempFileName())"
    [System.IO.File]::WriteAllText($curlArgs[-1].Substring(1), $bodyJson, [System.Text.Encoding]::UTF8)
    $curlArgs += $url
    $curlCmd = "curl.exe $($curlArgs -join ' ')"
    Write-Host $desc -ForegroundColor Magenta
    Write-Host "JSON enviado:" -ForegroundColor DarkCyan
    Write-Host $bodyJson -ForegroundColor Cyan
    Write-Host $curlCmd -ForegroundColor Cyan
    $result = & curl.exe @curlArgs
    Remove-Item $curlArgs[-3].Substring(1) -ErrorAction SilentlyContinue
    $split = $result -split '\|\|\|'
    $body = $split[0].Trim()
    $code = if ($split.Length -gt 1) { $split[1].Trim() } else { "" }
    if ($code -eq $expectedCode) {
        Write-Host "[OK] POST /v1/evaluaciones (rol: $rol, correo: $correo, HTTP $code)" -ForegroundColor Green
        Write-Host $body -ForegroundColor Gray
    } else {
        Write-Host "[FAIL] POST /v1/evaluaciones (rol: $rol, correo: $correo, HTTP $code)" -ForegroundColor Red
        Write-Host $body -ForegroundColor Yellow
    }
}

# Crear Examen Parcial (tipo 1)
Test-CrearEvaluacion -rol "operador" -correo "ahuaccachi28@gmail.com" -cursoId 7 -tipo 1 -nombre "Examen Parcial" -desc "Crear Examen Parcial curso 7" -expectedCode "200"
# Crear Examen Final (tipo 2)
Test-CrearEvaluacion -rol "operador" -correo "ahuaccachi28@gmail.com" -cursoId 7 -tipo 2 -nombre "Examen Final" -desc "Crear Examen Final curso 7" -expectedCode "200"
# Crear Trabajo Parcial (tipo 3)
Test-CrearEvaluacion -rol "operador" -correo "ahuaccachi28@gmail.com" -cursoId 7 -tipo 3 -nombre "Trabajo Parcial" -desc "Crear Trabajo Parcial curso 7" -expectedCode "200"

# =================== VARIABLES GLOBALES ===================
$baseUrl = "http://localhost:8001/v1"
$token = "Sm@rtD@t@S3erv1c3sS3cr3t"

# =================== FUNCIONES ===================
function Test-EvaluacionesPorCurso {
    param(
        [string]$rol,
        [string]$correo,
        [string]$desc,
        [string]$expectedCode = "200"
    )
    $url = "$baseUrl/cursos/1/evaluaciones"
    $headers = @(
        "X-INTERNAL-SERVICE-TOKEN: $token",
        "X-USER-ROL: $rol",
        "X-USER-EMAIL: $correo"
    )
    $curlArgs = @("-s", "-w", "|||%{http_code}")
    foreach ($h in $headers) {
        $curlArgs += "-H"
        $curlArgs += $h
    }
    $curlArgs += $url
    $curlCmd = "curl.exe $($curlArgs -join ' ')"
    Write-Host $desc -ForegroundColor Magenta
    Write-Host $curlCmd -ForegroundColor Cyan
    $result = & curl.exe @curlArgs
    $split = $result -split '\|\|\|'
    $body = $split[0].Trim()
    $code = if ($split.Length -gt 1) { $split[1].Trim() } else { "" }

    if ($code -eq $expectedCode) {
        Write-Host "[OK] GET /v1/cursos/1/evaluaciones (rol: $rol, correo: $correo, HTTP $code)" -ForegroundColor Green
        Write-Host $body -ForegroundColor Gray
    } else {
        Write-Host "[FAIL] GET /v1/cursos/1/evaluaciones (rol: $rol, correo: $correo, HTTP $code)" -ForegroundColor Red
        Write-Host $body -ForegroundColor Yellow
    }
}

function Test-EvaluacionesCursos {
    param(
        [string]$rol,
        [string]$correo,
        [string]$desc,
        [string]$expectedCode = "200"
    )
    $url = "$baseUrl/evaluaciones/cursos"
    $headers = @(
        "X-INTERNAL-SERVICE-TOKEN: $token",
        "X-USER-ROL: $rol",
        "X-USER-EMAIL: $correo"
    )
    $curlArgs = @("-s", "-w", "|||%{http_code}")
    foreach ($h in $headers) {
        $curlArgs += "-H"
        $curlArgs += $h
    }
    $curlArgs += $url
    $curlCmd = "curl.exe $($curlArgs -join ' ')"
    Write-Host $desc -ForegroundColor Magenta
    Write-Host $curlCmd -ForegroundColor Cyan
    $result = & curl.exe @curlArgs
    $split = $result -split '\|\|\|'
    $body = $split[0].Trim()
    $code = if ($split.Length -gt 1) { $split[1].Trim() } else { "" }

    if ($code -eq $expectedCode) {
        Write-Host "[OK] GET /v1/evaluaciones/cursos (rol: $rol, correo: $correo, HTTP $code)" -ForegroundColor Green
        Write-Host $body -ForegroundColor Gray
    } else {
        Write-Host "[FAIL] GET /v1/evaluaciones/cursos (rol: $rol, correo: $correo, HTTP $code)" -ForegroundColor Red
        Write-Host $body -ForegroundColor Yellow
    }
}

# =================== PRUEBAS ===================
Write-Host "\n--- /cursos/1/evaluaciones (ListarEvaluacionesPorCurso) ---" -ForegroundColor Yellow
# Caso: operador
Test-EvaluacionesPorCurso -rol "operador" -correo "ahuaccachi28@gmail.com" -desc "Caso: operador (evaluaciones por curso, correo: ahuaccachi28@gmail.com)" -expectedCode "200"
# Caso: admin
Test-EvaluacionesPorCurso -rol "admin" -correo "test@smartdata.com.pe" -desc "Caso: admin (evaluaciones por curso, correo: test@smartdata.com.pe)" -expectedCode "200"

Write-Host "\n--- /evaluaciones/cursos (listarCursosParaEvaluaciones) ---" -ForegroundColor Yellow
# Caso 1: operador
Test-EvaluacionesCursos -rol "operador" -correo "ahuaccachi28@gmail.com" -desc "Caso: operador (correo: ahuaccachi28@gmail.com)" -expectedCode "200"
# Caso 2: admin
Test-EvaluacionesCursos -rol "admin" -correo "test@smartdata.com.pe" -desc "Caso: admin (correo: test@smartdata.com.pe)" -expectedCode "200"
# Caso 3: alumno
Test-EvaluacionesCursos -rol "alumno" -correo "david.arias.my@gmail.com" -desc "Caso: alumno (correo: david.arias.my@gmail.com)" -expectedCode "200"
