<?php
// Prevent unauthorized web access to the background task runner
if (php_sapi_name() !== 'cli' && (!isset($_GET['token']) || $_GET['token'] !== 'MySuperSecureToken123!')) {
    header('HTTP/1.1 403 Forbidden');
    die('Access Denied: Background system operation boundaries strictly enforced.');
}

// 1. Establish System Configuration & Storage Boundaries
include 'db.php'; // Pulls $db_host, $db_user, $db_pass, etc.

// Adjust database parameters dynamically from your included connection configuration variables
$host = "localhost";
$user = "root";  
$pass = "";      
$name = "fms"; // Change this to your exact database name

$backup_dir = __DIR__ . '/backups/';
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0755, true);
}

$filename = $name . "_backup_" . date('Y-m-d_H-i-s') . ".sql";
$backup_file_path = $backup_dir . $filename;

echo "Initializing automated snapshot engine for target schema: [{$name}]...\n";

// 2. Generate the Database Dump using standard PHP logic
$fp = fopen($backup_file_path, 'w');
if (!$fp) {
    die("Error: Unable to initialize storage dump stream matrix container.");
}

// Write file header meta strings
fwrite($fp, "-- Database Snapshot Export Automation Engine\n");
fwrite($fp, "-- Generation Timestamp: " . date('Y-m-d H:i:s') . "\n\n");
fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

// Read table schemas sequentially
$tables = [];
$res = $conn->query("SHOW TABLES");
while ($row = $res->fetch_row()) {
    $tables[] = $row[0];
}

foreach ($tables as $table) {
    // A. Output Table Dropper & Structure Definitions
    fwrite($fp, "DROP TABLE IF EXISTS `" . $table . "`;\n");
    $create_res = $conn->query("SHOW CREATE TABLE `" . $table . "`");
    $create_row = $create_res->fetch_row();
    fwrite($fp, $create_row[1] . ";\n\n");
    
    // B. Output Sequential Row Registries
    $data_res = $conn->query("SELECT * FROM `" . $table . "`");
    while ($data_row = $data_res->fetch_assoc()) {
        $fields = array_keys($data_row);
        $escaped_values = array_map(function($val) use ($conn) {
            if ($val === null) return 'NULL';
            return "'" . $conn->real_escape_string($val) . "'";
        }, array_values($data_row));
        
        $sql = "INSERT INTO `" . $table . "` (`" . implode("`, `", $fields) . "`) VALUES (" . implode(", ", $escaped_values) . ");\n";
        fwrite($fp, $sql);
    }
    fwrite($fp, "\n\n");
}

fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fp);

// 3. Compress the File to Save Storage Space (.zip format)
$zip = new ZipArchive();
$zip_filename = $backup_file_path . ".zip";

if ($zip->open($zip_filename, ZipArchive::CREATE) === TRUE) {
    $zip->addFile($backup_file_path, $filename);
    $zip->close();
    unlink($backup_file_path); // Remove the raw uncompressed .sql file
    echo "Success! Compressed backup written to: {$zip_filename}\n";
} else {
    echo "Warning: Database exported but compression execution failed.\n";
}

// =========================================================================
// 4. Clean Up Phase: Purge Legacy Backups (Keep only the last 7 days)
// =========================================================================
$retention_days = 7; // Changed from 30 to 7 days
$seconds_in_day = 86400;
$expiry_timeframe = time() - ($retention_days * $seconds_in_day);

// Target both raw .sql files and compressed .zip backup files
$backup_patterns = [$backup_dir . '*.sql', $backup_dir . '*.zip'];

echo "Initiating storage retention policy sweep (Retention window: {$retention_days} Days)...\n";

foreach ($backup_patterns as $pattern) {
    $found_files = glob($pattern);
    if (is_array($found_files)) {
        foreach ($found_files as $file_path) {
            if (is_file($file_path)) {
                $file_modified_time = filemtime($file_path);
                
                // If the file modification timestamp is older than 7 days ago, delete it
                if ($file_modified_time < $expiry_timeframe) {
                    if (unlink($file_path)) {
                        echo "Retention Policy Match: Successfully purged legacy file [" . basename($file_path) . "]\n";
                    } else {
                        echo "Warning: Failed to delete expired file [" . basename($file_path) . "] due to system permissions.\n";
                    }
                }
            }
        }
    }
}

echo "Backup engine cycle executed successfully. Disk space optimized.\n";
?>
