<?php

namespace App\Repositories;

use App\Helpers\DbSafe;

class PagoRepository
{
    public function listarPagosPorCorreo(string $email)
    {
        $sql = "
            SELECT 
                product_name AS curso,
                edicion,
                currency AS moneda,
                original_price AS precio_curso,
                installment_number AS cuota,
                installments AS cantidad_cuotas,
                new_price AS importe_cuota,
                CASE 
                    WHEN status = 'activo' THEN 'Pendiente'
                    WHEN status = 'usado' THEN 'Pagado'
                    ELSE status
                END AS estado,
                installment_date AS fecha_vencimiento,
                paid_date AS fecha_pago
            FROM discounts
            WHERE email = ?
            ORDER BY installment_date ASC, installment_number ASC
        ";

        return DbSafe::select('mysql_cursos', $sql, [$email]);
    }
}