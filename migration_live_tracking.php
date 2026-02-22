<?php
require_once __DIR__ . '/includes/config.php';

try {
    $db = getDB();

    // Create live tracking active_sessions table
    $query = "
    CREATE TABLE IF NOT EXISTS active_sessions (
        user_id INT PRIMARY KEY REFERENCES users(id),
        numero_of VARCHAR(50) NOT NULL,
        start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    ";

    $db->exec($query);
    echo "Table active_sessions created or already exists.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
