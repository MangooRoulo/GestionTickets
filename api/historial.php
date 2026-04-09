<?php
// API de Historial de Auditoría: GET con filtros opcionales
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $ticketId = $_GET['ticket_id'] ?? null;
    $desde = $_GET['desde'] ?? null;
    $hasta = $_GET['hasta'] ?? null;
    $limit = intval($_GET['limit'] ?? 20);
    $offset = intval($_GET['offset'] ?? 0);

    // Condicionales base
    $where = "WHERE 1=1";
    $params = [];

    // Filtrar por ticket específico
    if ($ticketId) {
        $where .= " AND h.ticket_id = :ticket_id";
        $params['ticket_id'] = $ticketId;
    }

    // Filtrar desde fecha
    if ($desde) {
        $where .= " AND h.fecha_cambio >= :desde";
        $params['desde'] = $desde;
    }

    // Filtrar hasta fecha
    if ($hasta) {
        $where .= " AND h.fecha_cambio <= :hasta";
        $params['hasta'] = $hasta . ' 23:59:59';
    }

    // Calcular Total
    $sqlCount = "SELECT COUNT(*) FROM historial_gestion h " . $where;
    $stmtC = $pdo->prepare($sqlCount);
    $stmtC->execute($params);
    $total = intval($stmtC->fetchColumn());

    // Query de Data
    $sqlData = "SELECT h.*, t.resumen as ticket_resumen, t.modulo as ticket_modulo ";
    $sqlData .= "FROM historial_gestion h LEFT JOIN tickets t ON h.ticket_id = t.id ";
    $sqlData .= $where . " ORDER BY h.fecha_cambio DESC LIMIT " . $limit . " OFFSET " . $offset;
    
    $stmtD = $pdo->prepare($sqlData);
    $stmtD->execute($params);
    $data = $stmtD->fetchAll();

    jsonResponse([
        'total' => $total,
        'data'  => $data
    ]);
}
