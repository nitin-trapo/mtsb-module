<?php

function multipass_log($message, $type = 'INFO') {
    $log_dir = __DIR__ . '/../logs';
    
    // Create logs directory if it doesn't exist
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0777, true);
    }

    $log_file = $log_dir . '/multipass.log';
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[{$timestamp}] [{$type}] {$message}" . PHP_EOL;
    
    // Append to log file
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}
