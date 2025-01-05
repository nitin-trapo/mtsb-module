<?php
// Create necessary upload directories
$dirs = [
    '../../assets/uploads',
    '../../assets/uploads/receipts'
];

foreach ($dirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

echo "Upload directories created successfully.\n";
