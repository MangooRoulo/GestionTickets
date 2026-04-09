<?php
// API de Configuración: GET (leer) y PUT (actualizar)
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET: Obtener toda la configuración como objeto clave:valor
if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM configuracion");
    $config = [];
    while ($row = $stmt->fetch()) {
        $config[$row['clave']] = $row['valor'];
    }
    jsonResponse($config);
}

// PUT: Actualizar un valor de configuración
if ($method === 'PUT') {
    $input = getJsonInput();
    $clave = $input['clave'] ?? null;
    $valor = $input['valor'] ?? null;
    if (!$clave) jsonResponse(['error' => 'Clave requerida'], 400);

    // INSERT si no existe, UPDATE si ya existe
    $stmt = $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (:clave, :valor) ON DUPLICATE KEY UPDATE valor = :valor2");
    $stmt->execute(['clave' => $clave, 'valor' => $valor, 'valor2' => $valor]);
    jsonResponse(['success' => true]);
}
