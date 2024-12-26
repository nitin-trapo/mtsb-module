<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is admin
if (!is_logged_in() || !is_admin()) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

if (!isset($_GET['sync_id']) || !is_numeric($_GET['sync_id'])) {
    echo json_encode(['error' => 'Valid sync ID is required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT status, items_synced, error_message,
               started_at, completed_at,
               TIMESTAMPDIFF(SECOND, started_at, COALESCE(completed_at, NOW())) as duration
        FROM sync_logs 
        WHERE id = ?
    ");
    $stmt->execute([$_GET['sync_id']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        echo json_encode(['error' => 'Sync log not found']);
        exit;
    }
    
    // Calculate progress based on status
    $progress = 0;
    if ($result['status'] === 'success') {
        $progress = 100;
    } else if ($result['status'] === 'running') {
        // If sync is running for more than 5 minutes, mark as failed
        if ($result['duration'] > 300) {
            $stmt = $conn->prepare("
                UPDATE sync_logs 
                SET status = 'failed',
                    error_message = 'Sync timeout after 5 minutes',
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$_GET['sync_id']]);
            $result['status'] = 'failed';
            $result['error_message'] = 'Sync timeout after 5 minutes';
            $progress = 100;
        } else {
            // For running syncs, calculate progress based on items_synced
            if ($result['items_synced'] > 0) {
                // Assume we'll sync around 250 items
                $progress = min(95, ($result['items_synced'] / 250) * 100);
            } else {
                // If no items synced yet, base it on time
                $progress = min(50, ($result['duration'] / 60) * 100);
            }
        }
    } else if ($result['status'] === 'failed') {
        $progress = 100;
    }
    
    echo json_encode([
        'status' => $result['status'],
        'progress' => round($progress, 1),
        'items_synced' => (int)$result['items_synced'],
        'error_message' => $result['error_message'],
        'started_at' => $result['started_at'],
        'completed_at' => $result['completed_at'],
        'duration' => $result['duration']
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
