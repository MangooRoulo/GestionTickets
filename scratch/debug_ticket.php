<?php
require_once 'api/db.php';

$id = 457291;
echo "--- Ticket $id ---\n";
$stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = :id");
$stmt->execute(['id' => $id]);
print_r($stmt->fetch());

echo "\n--- Gestion Diaria (Today) ---\n";
$today = date('Y-m-d');
$stmtGD = $pdo->prepare("SELECT * FROM gestion_diaria WHERE ticket_id = :id AND fecha = :fecha");
$stmtGD->execute(['id' => $id, 'fecha' => $today]);
print_r($stmtGD->fetch());

echo "\n--- Estados de Gestion ---\n";
$stmtE = $pdo->query("SELECT * FROM estados_gestion");
print_r($stmtE->fetchAll());
?>
