<?php
$action = $_GET['action'] ?? '';
$tablero = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['tablero'] ?? 'default');
$file = __DIR__ . "/notes_$tablero.json";

header('Content-Type: application/json');

if ($action === 'load') {
    if (file_exists($file)) {
        echo file_get_contents($file);
    } else {
        echo '[]';
    }
    exit;
}

if ($action === 'save') {
    $json = file_get_contents('php://input');
    file_put_contents($file, $json);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'Acción no válida']);
