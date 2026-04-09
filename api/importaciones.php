<?php
// API de Importaciones: GET (última) y DELETE (deshacer/rollback)
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET: Obtener información de la última importación
if ($method === 'GET') {
    $sql = "SELECT id, archivo_nombre, fecha_importacion, total_insertados, total_actualizados FROM importaciones ORDER BY id DESC LIMIT 1";
    $stmt = $pdo->query($sql);
    $last = $stmt->fetch();
    if (!$last) jsonResponse(['error' => 'No hay importaciones registradas'], 404);
    jsonResponse($last);
}

// DELETE: Deshacer última importación restaurando snapshot
if ($method === 'DELETE') {
    // Obtener última importación con su snapshot
    $stmt = $pdo->query("SELECT * FROM importaciones ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetch();
    if (!$last) jsonResponse(['error' => 'No hay importaciones que deshacer'], 404);

    // Decodificar snapshot de tickets antes de la importación
    $snapshot = json_decode($last['snapshot_completo'], true);

    $pdo->beginTransaction();
    try {
        // 1. Desactivar temporalmente restricciones de integridad
        // Esto evita que 'DELETE FROM tickets' active el Cascade Delete en 'gestion_diaria'
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        // 2. Eliminar los registros de activos diarios del día de la importación (si se conoce)
        if (!empty($last['fecha_objetivo'])) {
            $stmtDelActivos = $pdo->prepare("DELETE FROM tickets_activos_dia WHERE fecha = :f");
            $stmtDelActivos->execute(['f' => $last['fecha_objetivo']]);
        }

        // 3. Limpiar tabla principal de tickets
        $pdo->exec("DELETE FROM tickets");

        // 4. Restaurar desde el Snapshot
        if (!empty($snapshot)) {
            $sql = "INSERT INTO tickets (id, gravedad, fecha_apertura, tipo_solicitud, resumen, ";
            $sql .= "estado_excel, cliente, modulo, componente, responsable, ultima_actualizacion, ";
            $sql .= "dias_ultima_derivacion, puntaje, iteraciones, fecha_entrega, estado_gestion_id, ";
            $sql .= "tiempo_estimado, tiempo_dedicado, fue_gestionado_manualmente, fecha_gestion, ";
            $sql .= "es_activo, created_at, updated_at) VALUES ";
            $sql .= "(:id,:grav,:fa,:tipo,:res,:ee,:cli,:mod,:comp,:resp,:ua,:dias,:punt,:iter,:fe,:egi,:te,:td,:fgm,:fg,:ea,:ca,:upd)";
            $stmt = $pdo->prepare($sql);

            foreach ($snapshot as $t) {
                $stmt->execute([
                    'id' => $t['id'],
                    'grav' => $t['gravedad'],
                    'fa' => $t['fecha_apertura'],
                    'tipo' => $t['tipo_solicitud'],
                    'res' => $t['resumen'],
                    'ee' => $t['estado_excel'],
                    'cli' => $t['cliente'],
                    'mod' => $t['modulo'],
                    'comp' => $t['componente'],
                    'resp' => $t['responsable'],
                    'ua' => $t['ultima_actualizacion'],
                    'dias' => $t['dias_ultima_derivacion'],
                    'punt' => $t['puntaje'],
                    'iter' => $t['iteraciones'],
                    'fe' => $t['fecha_entrega'],
                    'egi' => $t['estado_gestion_id'],
                    'te' => $t['tiempo_estimado'],
                    'td' => $t['tiempo_dedicado'],
                    'fgm' => $t['fue_gestionado_manualmente'],
                    'fg' => $t['fecha_gestion'],
                    'ea' => $t['es_activo'],
                    'ca' => $t['created_at'],
                    'upd' => $t['updated_at']
                ]);
            }
        }

        // 5. Registrar rollback en auditoría
        $stmt = $pdo->prepare("INSERT INTO historial_gestion (ticket_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (0, 'ROLLBACK', :arch, :fecha)");
        $stmt->execute(['arch' => $last['archivo_nombre'], 'fecha' => $last['fecha_importacion']]);

        // 6. Eliminar la sesión de importación
        $stmt = $pdo->prepare("DELETE FROM importaciones WHERE id = :id");
        $stmt->execute(['id' => $last['id']]);

        // 7. Reactivar restricciones de integridad
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        $pdo->commit();
        jsonResponse(['success' => true, 'message' => 'Importación deshecha correctamente. Se ha restaurado el estado anterior sin afectar el historial de gestiones.']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Error al deshacer: ' . $e->getMessage()], 500);
    }
}
