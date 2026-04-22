<?php
// API de Gestión Diaria: histórico de productividad y totales por fecha
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $tipo  = $_GET['tipo']  ?? 'historico';
    $fecha = $_GET['fecha'] ?? date('Y-m-d');

    if ($tipo === 'historico') {
        // Devuelve count de tickets gestionados agrupados por fecha
        // Usado por el gráfico histórico de productividad
        $stmt = $pdo->query("
            SELECT * FROM (
                SELECT fecha, COUNT(*) AS cantidad,
                       SUM(tiempo_dedicado) AS horas_totales
                FROM gestion_diaria
                WHERE fue_gestionado = 1 OR tiempo_dedicado > 0 OR tiempo_estimado > 0 OR en_proceso = 1
                GROUP BY fecha
                ORDER BY fecha DESC
                LIMIT 30
            ) sub
            ORDER BY fecha ASC
        ");
        jsonResponse($stmt->fetchAll());

    } elseif ($tipo === 'activos') {
        // Devuelve cuántos tickets únicos hubo activos en la fecha solicitada
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets_activos_dia WHERE fecha = :fecha");
        $stmt->execute(['fecha' => $fecha]);
        jsonResponse(['total' => intval($stmt->fetchColumn()), 'fecha' => $fecha]);
    }
}
