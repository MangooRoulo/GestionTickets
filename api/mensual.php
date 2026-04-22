<?php
// API de Resumen Mensual Rediseñado: Insights de productividad profunda
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $mesFiltro = $_GET['mes'] ?? date('Y-m');

    // 1. LISTA DE MESES
    $stmtMeses = $pdo->query("
        SELECT DISTINCT DATE_FORMAT(fecha, '%Y-%m') as mes 
        FROM (SELECT fecha FROM gestion_diaria UNION SELECT fecha FROM tickets_activos_dia) t
        ORDER BY mes DESC LIMIT 12
    ");
    $mesesDisponibles = $stmtMeses->fetchAll(PDO::FETCH_COLUMN);

    // 2. KPI GLOBAL DEL MES
    $stmtKpi = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT gd.ticket_id) as trabajados,
            SUM(gd.tiempo_dedicado) as horas,
            COUNT(DISTINCT CASE WHEN t.tipo_solicitud LIKE '%Defecto%' THEN gd.ticket_id END) as defectos,
            COUNT(DISTINCT CASE WHEN t.tipo_solicitud LIKE '%Requerimiento%' OR t.tipo_solicitud LIKE '%Mejora%' THEN gd.ticket_id END) as requerimientos,
            AVG(tad.dias_ultima_derivacion) as avg_derivacion
        FROM gestion_diaria gd
        JOIN tickets t ON gd.ticket_id = t.id
        LEFT JOIN (
            SELECT ticket_id, fecha, dias_ultima_derivacion 
            FROM tickets_activos_dia 
            WHERE DATE_FORMAT(fecha, '%Y-%m') = ?
        ) tad ON gd.ticket_id = tad.ticket_id AND gd.fecha = tad.fecha
        WHERE DATE_FORMAT(gd.fecha, '%Y-%m') = ?
          AND (gd.fue_gestionado = 1 OR gd.tiempo_dedicado > 0 OR gd.tiempo_estimado > 0 OR gd.en_proceso = 1)
    ");
    $stmtKpi->execute([$mesFiltro, $mesFiltro]);
    $kpis = $stmtKpi->fetch();

    $stmtImp = $pdo->prepare("SELECT COUNT(DISTINCT ticket_id) FROM tickets_activos_dia WHERE DATE_FORMAT(fecha, '%Y-%m') = ?");
    $stmtImp->execute([$mesFiltro]);
    $totalImportados = (int)$stmtImp->fetchColumn();

    // 3. TENDENCIA DIARIA
    $stmtTrend = $pdo->prepare("
        SELECT 
            fecha,
            COUNT(DISTINCT ticket_id) as trabajados,
            SUM(tiempo_dedicado) as horas
        FROM gestion_diaria
        WHERE DATE_FORMAT(fecha, '%Y-%m') = ?
          AND (fue_gestionado = 1 OR tiempo_dedicado > 0 OR tiempo_estimado > 0 OR en_proceso = 1)
        GROUP BY fecha
        ORDER BY fecha ASC
    ");
    $stmtTrend->execute([$mesFiltro]);
    $trendDiario = $stmtTrend->fetchAll();

    // 4. DESGLOSE SEMANAL CONSOLIDADO
    // Primero obtenemos todas las semanas que tienen o importados o trabajados en ese mes
    $stmtSemanas = $pdo->prepare("
        SELECT DISTINCT YEARWEEK(fecha, 1) as id
        FROM (
            SELECT fecha FROM gestion_diaria WHERE DATE_FORMAT(fecha, '%Y-%m') = ?
            UNION
            SELECT fecha FROM tickets_activos_dia WHERE DATE_FORMAT(fecha, '%Y-%m') = ?
        ) t ORDER BY id ASC
    ");
    $stmtSemanas->execute([$mesFiltro, $mesFiltro]);
    $idsSemanas = $stmtSemanas->fetchAll(PDO::FETCH_COLUMN);

    $dataSemanal = [];
    foreach ($idsSemanas as $swId) {
        // Trabajados en esta semana para este mes
        $stmtTrab = $pdo->prepare("
            SELECT 
                MIN(gd.fecha) as inicio,
                MAX(gd.fecha) as fin,
                COUNT(DISTINCT gd.ticket_id) as trabajados,
                COUNT(DISTINCT CASE WHEN t.tipo_solicitud LIKE '%Defecto%' THEN gd.ticket_id END) as defectos,
                COUNT(DISTINCT CASE WHEN t.tipo_solicitud LIKE '%Requerimiento%' OR t.tipo_solicitud LIKE '%Mejora%' THEN gd.ticket_id END) as requerimientos
            FROM gestion_diaria gd
            JOIN tickets t ON gd.ticket_id = t.id
            WHERE YEARWEEK(gd.fecha, 1) = ? AND DATE_FORMAT(gd.fecha, '%Y-%m') = ?
              AND (gd.fue_gestionado = 1 OR gd.tiempo_dedicado > 0 OR gd.tiempo_estimado > 0 OR gd.en_proceso = 1)
        ");
        $stmtTrab->execute([$swId, $mesFiltro]);
        $trab = $stmtTrab->fetch();

        // Importados en esta semana (pueden ser de fuera del mes pero la semana cae en el mes)
        $stmtImpW = $pdo->prepare("
            SELECT COUNT(DISTINCT ticket_id) 
            FROM tickets_activos_dia 
            WHERE YEARWEEK(fecha, 1) = ?
        ");
        $stmtImpW->execute([$swId]);
        $importados = (int)$stmtImpW->fetchColumn();

        if ($trab['inicio'] || $importados > 0) {
            $dataSemanal[] = [
                'semana_id' => $swId,
                'fecha_inicio' => $trab['inicio'] ?? date('Y-m-d', strtotime($swId.'1')), // Fallback simple
                'fecha_fin' => $trab['fin'] ?? date('Y-m-d', strtotime($swId.'7')),
                'trabajados' => (int)$trab['trabajados'],
                'importados' => $importados,
                'defectos' => (int)$trab['defectos'],
                'requerimientos' => (int)$trab['requerimientos']
            ];
        }
    }

    // 5. PATRONES
    $stmtPattern = $pdo->prepare("
        SELECT 
            DAYOFWEEK(fecha) as dia_id,
            COUNT(DISTINCT fecha) as ocurrencias,
            COUNT(DISTINCT ticket_id) as trabajados
        FROM gestion_diaria
        WHERE DATE_FORMAT(fecha, '%Y-%m') = ?
          AND (fue_gestionado = 1 OR tiempo_dedicado > 0 OR tiempo_estimado > 0 OR en_proceso = 1)
        GROUP BY DAYOFWEEK(fecha)
    ");
    $stmtPattern->execute([$mesFiltro]);
    $patrones = $stmtPattern->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $nombresDias = [2 => 'Lun', 3 => 'Mar', 4 => 'Mié', 5 => 'Jue', 6 => 'Vie', 7 => 'Sáb', 1 => 'Dom'];
    $distribucionDias = [];
    foreach ([2,3,4,5,6,7,1] as $id) {
        $d = $patrones[$id] ?? ['ocurrencias' => 0, 'trabajados' => 0];
        $distribucionDias[] = [
            'nombre' => $nombresDias[$id],
            'trabajados' => (int)$d['trabajados'],
            'promedio' => $d['ocurrencias'] > 0 ? round($d['trabajados'] / $d['ocurrencias'], 1) : 0
        ];
    }

    jsonResponse([
        'meses' => $mesesDisponibles,
        'seleccion' => $mesFiltro,
        'resumen' => [
            'importados' => $totalImportados,
            'trabajados' => (int)$kpis['trabajados'],
            'horas' => (float)$kpis['horas'],
            'defectos' => (int)$kpis['defectos'],
            'requerimientos' => (int)$kpis['requerimientos'],
            'eficiencia' => $totalImportados > 0 ? round(($kpis['trabajados'] / $totalImportados) * 100, 1) : 0,
            'avg_stale' => round((float)$kpis['avg_derivacion'], 1)
        ],
        'trend' => $trendDiario,
        'semanal' => $dataSemanal,
        'patrones' => $distribucionDias
    ]);
}
