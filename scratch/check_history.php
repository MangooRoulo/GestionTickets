<?php
require_once 'api/db.php';
$id = 457291;
$stmt = $pdo->prepare("SELECT * FROM historial_gestion WHERE ticket_id = :id ORDER BY id DESC");
$stmt->execute(['id' => $id]);
print_r($stmt->fetchAll());
?>
