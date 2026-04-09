<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = getJsonInput();
    $fecha = $input['fecha'] ?? null;
    $filas = $input['filas'] ?? [];

    if (!$fecha || empty($filas)) {
        jsonResponse(['error' => 'No se recibió la fecha o el archivo está vacío'], 400);
    }

    $pdo->beginTransaction();
    try {
        // Obtenemos los tickets afectados para borrar cualquier registro PREVIO de esa fecha
        // (ya que vamos a reescribir con seguridad lo que venga en el archivo)
        $stmtDelete = $pdo->prepare("DELETE FROM gestion_diaria WHERE fecha = :fecha");
        $stmtDelete->execute(['fecha' => $fecha]);

        $inserted = 0;
        
        $stmtInsert = $pdo->prepare("
            INSERT INTO gestion_diaria
            (ticket_id, fecha, tiempo_estimado, tiempo_dedicado, estado_gestion_id, fue_gestionado)
            VALUES (:id, :fecha, :te, :td, :egi, :fg)
        ");

        $stmtTicketUpdate = $pdo->prepare("
            UPDATE tickets
            SET estado_gestion_id = :egi
            WHERE id = :id AND :fecha = CURRENT_DATE()
        ");

        foreach ($filas as $fila) {
            // Saltamos filas que no estén bien formateadas
            if (!isset($fila['ID_TICKET'])) continue;
            
            $id = intval($fila['ID_TICKET']);
            $egi = intval($fila['ESTADO_GESTION_ID'] ?? 1);
            $te = floatval($fila['TIEMPO_ESTIMADO'] ?? 0);
            $td = floatval($fila['TIEMPO_DEDICADO'] ?? 0);
            $fg = intval($fila['FUE_GESTIONADO'] ?? 0);

            // 1. Insertamos / Remplazamos en gestion_diaria
            $stmtInsert->execute([
                'id' => $id,
                'fecha' => $fecha,
                'te' => $te,
                'td' => $td,
                'egi' => $egi,
                'fg' => $fg
            ]);
            $inserted++;

            // 2. Si la fecha es hoy, actualizamos la tabla maestra de tickets tbn
            $stmtTicketUpdate->execute([
                'id' => $id,
                'egi' => $egi,
                'fecha' => $fecha
            ]);
            
            // 3. Dejar log de restauración
            $pdo->prepare("INSERT INTO historial_gestion (ticket_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (:id, :campo, :ant, :nue)")
                ->execute(['id' => $id, 'campo' => "Restauración de Respaldo ($fecha)", 'ant' => '*', 'nue' => 'Restaurado por usuario']);
        }

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'restaurados' => $inserted
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Error al restaurar: ' . $e->getMessage()], 500);
    }
} else {
    jsonResponse(['error' => 'Metodo no permitido'], 405);
}
