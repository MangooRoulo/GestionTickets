<?php
// API de Tickets: GET (listar por fecha), POST (importar), PUT (actualizar)
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

// Estados que indican re-apertura del ticket
$reOpenStatuses = ['abierto','re-abierto','reabierto','devuelto','pendiente',
                   'en proceso','en producción','en produccion'];

function isReOpenStatus($status) {
    global $reOpenStatuses;
    $lower = strtolower($status);
    foreach ($reOpenStatuses as $kw) {
        if (strpos($lower, $kw) !== false) return true;
    }
    return false;
}

// ══════════════════════════════════════════════════════════════════════════════
// GET: Retornar tickets con datos de gestión del día solicitado
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'GET') {
    // Fecha solicitada: parámetro opcional, por defecto hoy
    $fecha = $_GET['fecha'] ?? date('Y-m-d');

    // JOIN con gestion_diaria para la fecha: devuelve 0/null si no hay registro ese día
    $sql = "
        SELECT
            t.*,
            eg.nombre AS estado_gestion_nombre,
            eg.color  AS estado_gestion_color,
            COALESCE(gd.tiempo_estimado,  0) AS gd_tiempo_estimado,
            COALESCE(gd.tiempo_dedicado,  0) AS gd_tiempo_dedicado,
            COALESCE(gd.estado_gestion_id, t.estado_gestion_id) AS gd_estado_gestion_id,
            COALESCE(gd.fue_gestionado,   0) AS gd_fue_gestionado,
            COALESCE(gd.en_proceso,       0) AS gd_en_proceso,
            COALESCE(tad.dias_ultima_derivacion, t.dias_ultima_derivacion) AS historic_dias_ultima_derivacion,
            tad.ticket_id AS tad_id
        FROM tickets t
        LEFT JOIN estados_gestion eg  ON eg.id = t.estado_gestion_id
        LEFT JOIN gestion_diaria  gd  ON gd.ticket_id = t.id AND gd.fecha = :fecha
        LEFT JOIN tickets_activos_dia tad ON tad.ticket_id = t.id AND tad.fecha = :fecha
        ORDER BY t.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['fecha' => $fecha]);
    $ticketsList = $stmt->fetchAll();

    // Total de tickets que estuvieron activos ese día
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM tickets_activos_dia WHERE fecha = :fecha");
    $stmtTotal->execute(['fecha' => $fecha]);
    $totalActivos = intval($stmtTotal->fetchColumn());

    jsonResponse(['tickets' => $ticketsList, 'total_activos' => $totalActivos]);
}

// ══════════════════════════════════════════════════════════════════════════════
// POST: Importar lote de tickets desde Excel (sync/merge)
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $input      = getJsonInput();
    $ticketsData = $input['tickets'] ?? [];
    $fileName   = $input['archivo'] ?? 'unknown.xlsx';
    if (empty($ticketsData)) jsonResponse(['error' => 'No se recibieron tickets'], 400);

    $stmt = $pdo->prepare("SELECT id FROM estados_gestion WHERE nombre = 'Pendiente' LIMIT 1");
    $stmt->execute();
    $pendienteId = $stmt->fetchColumn() ?: 1;

    // Snapshot ANTES de modificar (para rollback)
    $stmt     = $pdo->query("SELECT * FROM tickets");
    $snapshot = json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);



    $today = date('Y-m-d');
    $importDate = $input['fecha'] ?? $today;

    $pdo->beginTransaction();
    try {
        // Crear sesión de importación con snapshot y fecha objetivo
        $stmt = $pdo->prepare("INSERT INTO importaciones (archivo_nombre, snapshot_completo, fecha_objetivo) VALUES (:archivo, :snapshot, :fecha_obj)");
        $stmt->execute(['archivo' => $fileName, 'snapshot' => $snapshot, 'fecha_obj' => $importDate]);
        $importId = $pdo->lastInsertId();

        // Marcar todos como inactivos antes del merge
        $pdo->exec("UPDATE tickets SET es_activo = 0");

        $inserted = 0;
        $updated  = 0;

        // Prepare statement para tickets_activos_dia
        $stmtActivo = $pdo->prepare("INSERT INTO tickets_activos_dia (ticket_id, fecha, dias_ultima_derivacion) VALUES (:id, :fecha, :dias) ON DUPLICATE KEY UPDATE dias_ultima_derivacion = VALUES(dias_ultima_derivacion)");

        foreach ($ticketsData as $t) {
            $ticketId = intval(explode('.', strval($t['id'] ?? '0'))[0]);
            if ($ticketId <= 0) continue;

            // ... (resto de la lógica de merge se mantiene igual) ...
            // [Nota: Mantengo la lógica de consulta para detectar cambios]
            $stmtEx = $pdo->prepare("SELECT * FROM tickets WHERE id = :id");
            $stmtEx->execute(['id' => $ticketId]);
            $existing = $stmtEx->fetch();

            $newStatus = trim($t['estado_excel'] ?? 'Abierto');
            $statusChanged = false;
            if ($existing && $existing['estado_excel'] !== $newStatus) $statusChanged = true;

            if ($existing) {
                $statusChanged = ($existing['estado_excel'] !== $newStatus);
                $egId = $statusChanged ? $pendienteId : $existing['estado_gestion_id'];
                
                // Si el ticket vuelve en el Excel con un estado activo (como Producción)
                // lo marcamos como 'en_proceso' para que no quede oculto aunque ya se haya gestionado.
                $forceReopen = (isReOpenStatus($newStatus) && $existing['estado_gestion_id'] != $pendienteId);

                $sql  = "UPDATE tickets SET gravedad=:gravedad, fecha_apertura=:fa, tipo_solicitud=:tipo, resumen=:resumen, estado_excel=:ee, cliente=:cliente, modulo=:modulo, componente=:comp, responsable=:resp, ultima_actualizacion=:ua, dias_ultima_derivacion=:dias, puntaje=:punt, iteraciones=:iter, fecha_entrega=:fe, estado_gestion_id=:egi, es_activo=1 WHERE id=:id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'gravedad' => $t['gravedad'] ?? 'No definida', 'fa' => !empty($t['fecha_apertura']) ? $t['fecha_apertura'] : null, 'tipo' => $t['tipo_solicitud'] ?? 'Desconocido', 'resumen' => $t['resumen'] ?? '', 'ee' => $newStatus, 'cliente' => $t['cliente'] ?? '', 'modulo' => $t['modulo'] ?? '', 'comp' => $t['componente'] ?? '', 'resp' => $t['responsable'] ?? 'Sin asignar', 'ua' => !empty($t['ultima_actualizacion']) ? $t['ultima_actualizacion'] : null, 'dias' => intval($t['dias_ultima_derivacion'] ?? 0), 'punt' => intval($t['puntaje'] ?? 0), 'iter' => intval($t['iteraciones'] ?? 0), 'fe' => !empty($t['fecha_entrega']) ? $t['fecha_entrega'] : null, 'egi' => $egId, 'id' => $ticketId
                ]);

                if ($statusChanged || $forceReopen) {
                    if ($statusChanged) {
                        $stmtH = $pdo->prepare("INSERT INTO historial_gestion (ticket_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (:id, 'cambio_estado_excel', :ant, :nue)");
                        $stmtH->execute(['id' => $ticketId, 'ant' => $existing['estado_excel'], 'nue' => $newStatus]);
                    }

                    // Forzar visibilidad: Si vuelve en el Excel, se marca como 'en proceso' si ya tenía gestión previa.
                    // Esto permite que el KPI Rojo lo cuente y sea visible en la tabla.
                    $stmtResetGD = $pdo->prepare("UPDATE gestion_diaria SET estado_gestion_id = :egi, en_proceso = 1 WHERE ticket_id = :id AND fecha = :fecha");
                    $stmtResetGD->execute(['egi' => $egId, 'id' => $ticketId, 'fecha' => $importDate]);
                }
                $updated++;
            } else {
                $sql  = "INSERT INTO tickets (id, gravedad, fecha_apertura, tipo_solicitud, resumen, estado_excel, cliente, modulo, componente, responsable, ultima_actualizacion, dias_ultima_derivacion, puntaje, iteraciones, fecha_entrega, estado_gestion_id, es_activo) VALUES (:id,:gravedad,:fa,:tipo,:resumen,:ee,:cliente,:modulo,:comp,:resp,:ua,:dias,:punt,:iter,:fe,:egi,1)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'id' => $ticketId, 'gravedad' => $t['gravedad'] ?? 'No definida', 'fa' => !empty($t['fecha_apertura']) ? $t['fecha_apertura'] : null, 'tipo' => $t['tipo_solicitud'] ?? 'Desconocido', 'resumen' => $t['resumen'] ?? '', 'ee' => $newStatus, 'cliente' => $t['cliente'] ?? '', 'modulo' => $t['modulo'] ?? '', 'comp' => $t['componente'] ?? '', 'resp' => $t['responsable'] ?? 'Sin asignar', 'ua' => !empty($t['ultima_actualizacion']) ? $t['ultima_actualizacion'] : null, 'dias' => intval($t['dias_ultima_derivacion'] ?? 0), 'punt' => intval($t['puntaje'] ?? 0), 'iter' => intval($t['iteraciones'] ?? 0), 'fe' => !empty($t['fecha_entrega']) ? $t['fecha_entrega'] : null, 'egi' => $pendienteId
                ]);
                $inserted++;
            }

            // Registrar que este ticket estuvo activo en la FECHA SOLICITADA
            $stmtActivo->execute(['id' => $ticketId, 'fecha' => $importDate, 'dias' => intval($t['dias_ultima_derivacion'] ?? 0)]);
        }

        // Guardar totales de la sesión
        $stmt = $pdo->prepare("UPDATE importaciones SET total_insertados=:ins, total_actualizados=:upd WHERE id=:id");
        $stmt->execute(['ins' => $inserted, 'upd' => $updated, 'id' => $importId]);

        $pdo->commit();

        $activos = $pdo->query("SELECT COUNT(*) FROM tickets WHERE es_activo=1")->fetchColumn();
        $total   = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
        // Total ÚNICO importado hoy
        $totalHoy = $pdo->query("SELECT COUNT(*) FROM tickets_activos_dia WHERE fecha='$today'")->fetchColumn();

        jsonResponse([
            'success'     => true,
            'insertados'  => $inserted,
            'actualizados'=> $updated,
            'activos'     => intval($activos),
            'total'       => intval($total),
            'total_hoy'   => intval($totalHoy)
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Error en importación: ' . $e->getMessage()], 500);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// PUT: Actualizar tiempo o estado de gestión de un ticket
//      Escribe en gestion_diaria para HOY (registro inmutable por día)
// ══════════════════════════════════════════════════════════════════════════════
if ($method === 'PUT') {
    $id = $_GET['id'] ?? null;
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $input = getJsonInput();
    $field = $input['field'] ?? null;
    $value = $input['value'] ?? null;

    // Verificar que el ticket existe
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $ticket = $stmt->fetch();
    if (!$ticket) jsonResponse(['error' => 'Ticket no encontrado'], 404);

    $today = date('Y-m-d');
    $fechaAccion = $_GET['fecha'] ?? $today;

    // Obtener registro gestion_diaria de la FECHA SOLICITADA (si existe)
    $stmtGD = $pdo->prepare("SELECT * FROM gestion_diaria WHERE ticket_id = :id AND fecha = :fecha");
    $stmtGD->execute(['id' => $id, 'fecha' => $fechaAccion]);
    $gdHoy = $stmtGD->fetch();

    // Obtener ID estado Pendiente
    $stmtP = $pdo->prepare("SELECT id FROM estados_gestion WHERE nombre = 'Pendiente' LIMIT 1");
    $stmtP->execute();
    $pendienteId = $stmtP->fetchColumn() ?: 1;

    // Valores actuales del registro (o por defecto 0/pendiente)
    $gdEst  = floatval($gdHoy['tiempo_estimado']  ?? 0);
    $gdDed  = floatval($gdHoy['tiempo_dedicado']  ?? 0);
    $gdEgi  = intval($gdHoy['estado_gestion_id']    ?? ($ticket['estado_gestion_id'] ?? $pendienteId));
    $gdProc = intval($gdHoy['en_proceso'] ?? 0);

    // Aplicar el cambio solicitado
    $valorAnterior = '';
    if ($field === 'tiempo_estimado') {
        $valorAnterior = $gdEst;
        $gdEst = floatval($value);
    } elseif ($field === 'tiempo_dedicado') {
        $valorAnterior = $gdDed;
        $gdDed = floatval($value);
    } elseif ($field === 'estado_gestion_id') {
        $valorAnterior = $gdEgi;
        $gdEgi = intval($value);
        // Solo sincronizar el estado principal del ticket si la fecha es HOY
        if ($fechaAccion === $today) {
            $pdo->prepare("UPDATE tickets SET estado_gestion_id = :val WHERE id = :id")
                ->execute(['val' => $gdEgi, 'id' => $id]);
        }
    } elseif ($field === 'en_proceso') {
        $valorAnterior = $gdProc;
        $gdProc = intval($value) ? 1 : 0;
    }

    // Evaluar triple criterio de gestión para la fecha solicitada
    $isManagedStatus = ($gdEgi !== intval($pendienteId));
    $hasEstimate     = $gdEst > 0;
    $hasTime         = $gdDed > 0;
    $fueGestionado   = ($isManagedStatus && $hasEstimate && $hasTime) ? 1 : 0;

    // Si ya estaba marcado como gestionado en esa fecha, es irreversible
    if ($gdHoy && $gdHoy['fue_gestionado'] == 1) {
        $fueGestionado = 1;
    }

    // UPSERT en gestion_diaria — el historial por día queda preservado
    $sql = "
        INSERT INTO gestion_diaria
            (ticket_id, fecha, tiempo_estimado, tiempo_dedicado, estado_gestion_id, fue_gestionado, en_proceso)
        VALUES (:id, :fecha, :te, :td, :egi, :fg, :ep)
        ON DUPLICATE KEY UPDATE
            tiempo_estimado   = VALUES(tiempo_estimado),
            tiempo_dedicado   = VALUES(tiempo_dedicado),
            estado_gestion_id = VALUES(estado_gestion_id),
            fue_gestionado    = VALUES(fue_gestionado),
            en_proceso        = VALUES(en_proceso)
    ";
    $pdo->prepare($sql)->execute([
        'id'    => $id,
        'fecha' => $fechaAccion,
        'te'    => $gdEst,
        'td'    => $gdDed,
        'egi'   => $gdEgi,
        'fg'    => $fueGestionado,
        'ep'    => $gdProc
    ]);

    // Actualizar tickets.fue_gestionado_manualmente y fecha_gestion
    // Solo si el ticket fue efectivamente gestionado en ALGUNA fecha
    $wasManaged  = intval($ticket['fue_gestionado_manualmente']);
    $fechaGestion = $ticket['fecha_gestion'];
    if ($fueGestionado && (!$wasManaged || empty($fechaGestion) || strtotime($fechaAccion) > strtotime($fechaGestion))) {
        $fechaGestion = $fechaAccion;
    }
    $pdo->prepare("UPDATE tickets SET fue_gestionado_manualmente=:m, fecha_gestion=:fg WHERE id=:id")
        ->execute(['m' => ($wasManaged || $fueGestionado) ? 1 : 0, 'fg' => $fechaGestion, 'id' => $id]);

    // Auditoría
    $pdo->prepare("INSERT INTO historial_gestion (ticket_id, campo_modificado, valor_anterior, valor_nuevo) VALUES (:id, :campo, :ant, :nue)")
        ->execute(['id' => $id, 'campo' => $field . " (" . $fechaAccion . ")", 'ant' => strval($valorAnterior), 'nue' => strval($value)]);

    // Devolver ticket actualizado con campos gd_ de la fecha consultada (para feedback UI)
    $sql = "
        SELECT
            t.*,
            eg.nombre AS estado_gestion_nombre,
            eg.color  AS estado_gestion_color,
            COALESCE(gd.tiempo_estimado,  0) AS gd_tiempo_estimado,
            COALESCE(gd.tiempo_dedicado,  0) AS gd_tiempo_dedicado,
            COALESCE(gd.estado_gestion_id, t.estado_gestion_id) AS gd_estado_gestion_id,
            COALESCE(gd.fue_gestionado,   0) AS gd_fue_gestionado,
            COALESCE(gd.en_proceso,       0) AS gd_en_proceso,
            COALESCE(tad.dias_ultima_derivacion, t.dias_ultima_derivacion) AS historic_dias_ultima_derivacion,
            tad.ticket_id AS tad_id
        FROM tickets t
        LEFT JOIN estados_gestion eg ON eg.id = t.estado_gestion_id
        LEFT JOIN gestion_diaria  gd ON gd.ticket_id = t.id AND gd.fecha = :fecha
        LEFT JOIN tickets_activos_dia tad ON tad.ticket_id = t.id AND tad.fecha = :fecha
        WHERE t.id = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['fecha' => $fechaAccion, 'id' => $id]);
    jsonResponse($stmt->fetch());
}
