<?php
// API de Estados de Gestión: GET, POST, PUT, DELETE
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET: Listar todos los estados ordenados
if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM estados_gestion ORDER BY orden ASC");
    jsonResponse($stmt->fetchAll());
}

// POST: Crear nuevo estado
if ($method === 'POST') {
    $input = getJsonInput();
    $nombre = trim($input['nombre'] ?? '');
    $color = $input['color'] ?? '#3b82f6';
    if (!$nombre) jsonResponse(['error' => 'Nombre requerido'], 400);

    // Obtener siguiente orden
    $maxOrden = $pdo->query("SELECT COALESCE(MAX(orden),0) FROM estados_gestion")->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO estados_gestion (nombre, color, orden) VALUES (:nombre, :color, :orden)");
    $stmt->execute(['nombre' => $nombre, 'color' => $color, 'orden' => $maxOrden + 1]);
    jsonResponse(['success' => true, 'id' => intval($pdo->lastInsertId())]);
}

// PUT: Actualizar estado existente
if ($method === 'PUT') {
    $id = $_GET['id'] ?? null;
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    $input = getJsonInput();
    $fields = [];
    $params = ['id' => $id];

    // Construir query dinámico solo con campos enviados
    if (isset($input['nombre'])) { $fields[] = "nombre=:nombre"; $params['nombre'] = $input['nombre']; }
    if (isset($input['color'])) { $fields[] = "color=:color"; $params['color'] = $input['color']; }
    if (isset($input['orden'])) { $fields[] = "orden=:orden"; $params['orden'] = $input['orden']; }
    if (isset($input['activo'])) { $fields[] = "activo=:activo"; $params['activo'] = $input['activo']; }
    if (empty($fields)) jsonResponse(['error' => 'Nada que actualizar'], 400);

    $sql = "UPDATE estados_gestion SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success' => true]);
}

// DELETE: Eliminar estado (con validación de integridad)
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) jsonResponse(['error' => 'ID requerido'], 400);

    // No permitir eliminar "Pendiente"
    $stmt = $pdo->prepare("SELECT nombre FROM estados_gestion WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $nombre = $stmt->fetchColumn();
    if ($nombre === 'Pendiente') jsonResponse(['error' => 'No se puede eliminar el estado Pendiente'], 400);

    // Verificar que no esté en uso
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE estado_gestion_id = :id");
    $stmt->execute(['id' => $id]);
    if ($stmt->fetchColumn() > 0) jsonResponse(['error' => 'Estado en uso por tickets existentes'], 400);

    $stmt = $pdo->prepare("DELETE FROM estados_gestion WHERE id = :id");
    $stmt->execute(['id' => $id]);
    jsonResponse(['success' => true]);
}
