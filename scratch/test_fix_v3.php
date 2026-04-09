<?php
require_once 'api/db.php';

// Mock ticket
$ticketId = 777777;
$pdo->exec("DELETE FROM tickets WHERE id = $ticketId");
$pdo->exec("INSERT INTO tickets (id, resumen, estado_excel, estado_gestion_id, es_activo) VALUES ($ticketId, 'Test v3', 'En producción', 1, 1)");

$today = date('Y-m-d');
// Simulate managed as Devuelto (ID 2)
$pdo->exec("DELETE FROM gestion_diaria WHERE ticket_id = $ticketId AND fecha = '$today'");
$pdo->exec("INSERT INTO gestion_diaria (ticket_id, fecha, tiempo_estimado, tiempo_dedicado, estado_gestion_id, fue_gestionado, en_proceso) 
            VALUES ($ticketId, '$today', 0.5, 0.5, 2, 1, 0)");

echo "Initial: Ticket Managed (Devuelto, fue_gestionado=1, en_proceso=0)\n";

// Simulate Import v3 (Same Excel status, but it's re-imported)
$ticketsData = [['id' => $ticketId, 'estado_excel' => 'En producción']];
$pendienteId = 1;

foreach ($ticketsData as $t) {
    $newStatus = $t['estado_excel'];
    $stmtEx = $pdo->prepare("SELECT * FROM tickets WHERE id = :id");
    $stmtEx->execute(['id' => $ticketId]);
    $existing = $stmtEx->fetch();
    
    // Status in excel is the same, so statusChanged is false
    $statusChanged = ($existing['estado_excel'] !== $newStatus);
    
    // BUT isReOpenStatus('En producción') is true, and it's not Pending in system
    require_once 'api/tickets.php'; // to get isReOpenStatus and $reOpenStatuses
    
    $forceReopen = (isReOpenStatus($newStatus) && $existing['estado_gestion_id'] != $pendienteId);
    
    echo "statusChanged: " . ($statusChanged ? 'SI' : 'NO') . "\n";
    echo "forceReopen: " . ($forceReopen ? 'SI' : 'NO') . "\n";

    if ($statusChanged || $forceReopen) {
        echo "Applying v3 visibility fix...\n";
        $egId = $statusChanged ? 1 : $existing['estado_gestion_id'];
        $stmtResetGD = $pdo->prepare("UPDATE gestion_diaria SET estado_gestion_id = :egi, en_proceso = 1 WHERE ticket_id = :id AND fecha = :fecha");
        $stmtResetGD->execute(['egi' => $egId, 'id' => $ticketId, 'fecha' => $today]);
    }
}

// Verify
$stmtVerify = $pdo->prepare("SELECT * FROM gestion_diaria WHERE ticket_id = :id AND fecha = :fecha");
$stmtVerify->execute(['id' => $ticketId, 'fecha' => $today]);
$gd = $stmtVerify->fetch();

echo "Final state:\n";
print_r($gd);

if ($gd['en_proceso'] == 1 && $gd['fue_gestionado'] == 1) {
    echo "\nVERIFICATION v3 SUCCESSFUL! (Visible again via en_proceso, metric saved)\n";
} else {
    echo "\nVERIFICATION v3 FAILED!\n";
}

$pdo->exec("DELETE FROM tickets WHERE id = $ticketId");
?>
