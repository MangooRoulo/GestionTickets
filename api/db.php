<?php
// Conexión PDO a MySQL
$host = 'localhost';
$dbname = 'tickmetrics';
$username = 'root';
$password = 'Hermanitos3#';

try {
    // Crear conexión con charset UTF-8
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Activar excepciones para errores SQL
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Retornar arrays asociativos por defecto
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Zona horaria Colombia (UTC-5)
    date_default_timezone_set('America/Bogota');
    $pdo->exec("SET time_zone = '-05:00'");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]);
    exit;
}

// Headers CORS para acceso desde cualquier dispositivo
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Responder preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Leer body JSON de la petición
function getJsonInput() {
    return json_decode(file_get_contents('php://input'), true);
}

// Responder con JSON y código HTTP
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
