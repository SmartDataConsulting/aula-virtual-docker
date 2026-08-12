<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $items = [
            1 => 'Examen Parcial',
            2 => 'Examen Final',
            3 => 'Trabajo Práctico',
            4 => 'Trabajo Final',
        ];

        foreach ($items as $idValor => $descripcion) {
            DB::connection('mysql_cursos')
                ->table('parametros')
                ->updateOrInsert(
                    [
                        'id_maestro' => 21,
                        'id_valor' => $idValor,
                    ],
                    [
                        'desc_maestro' => 'TIPO EVALUACION',
                        'desc_valor' => $descripcion,
                        'flg_activo' => 1,
                        'fecha_actualizacion' => $now,
                    ]
                );
        }
    }

    public function down(): void
    {
        // No se revierte para evitar restaurar datos locales incorrectos del catalogo.
    }
};
