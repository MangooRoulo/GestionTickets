<?php
// API de Inspección Singular de Tickets (Insight Center)
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $ticketId = $_GET['ticket_id'] ?? null;
    if (!$ticketId) jsonResponse(['error' => 'ID de ticket requerido'], 400);

    // 1. Datos del Ticket
    $stmt = $pdo->prepare("SELECT t.*, eg.nombre as estado_gestion_nombre FROM tickets t LEFT JOIN estados_gestion eg ON t.estado_gestion_id = eg.id WHERE t.id = ?");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();
    
    if (!$ticket) jsonResponse(['error' => 'Ticket no encontrado'], 404);

    $limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    // 2. Historial de Días Intervenidos (gestion_diaria)
    // Para ver qué días se le sumó tiempo o se le cambió estado
    $stmtGD = $pdo->prepare("SELECT * FROM gestion_diaria WHERE ticket_id = ? ORDER BY fecha ASC");
    $stmtGD->execute([$ticketId]);
    $dias = $stmtGD->fetchAll();

    // 3. Auditoría Pura del Ticket (Paginada)
    // Primero el conteo total para el paginador
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM historial_gestion WHERE ticket_id = ?");
    $stmtCount->execute([$ticketId]);
    $totalAuditoria = $stmtCount->fetchColumn();

    $stmtAud = $pdo->prepare("SELECT * FROM historial_gestion WHERE ticket_id = ? ORDER BY fecha_cambio DESC LIMIT ? OFFSET ?");
    $stmtAud->bindValue(1, $ticketId, PDO::PARAM_INT);
    $stmtAud->bindValue(2, $limit, PDO::PARAM_INT);
    $stmtAud->bindValue(3, $offset, PDO::PARAM_INT);
    $stmtAud->execute();
    $auditoria = $stmtAud->fetchAll();

    // 4. Cálculos Absolutos
    $tiempoDedicadoTotal = 0;
    foreach($dias as $d) {
        $tiempoDedicadoTotal += floatval($d['tiempo_dedicado']);
    }

    jsonResponse([
        'ticket' => $ticket,
        'dias_trabajados' => $dias,
        'auditoria' => $auditoria,
        'total_auditoria' => $totalAuditoria,
        'totales' => [
            'tiempo_estimado_actual' => $ticket['tiempo_estimado'],
            'tiempo_dedicado_total' => $tiempoDedicadoTotal,
            'dias_distintos' => count($dias)
        ]
    ]);
}
