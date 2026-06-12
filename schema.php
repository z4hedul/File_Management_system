<?php
include 'db.php'; // Ensure this points to your database connection file

$db_name = "fms"; // Replace with your actual database name

$tables = $conn->query("SHOW TABLES");

echo "<h1>Database Schema for: $db_name</h1>";

while ($table = $tables->fetch_array()) {
    $table_name = $table[0];
    echo "<h3>Table: $table_name</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    
    $columns = $conn->query("DESCRIBE $table_name");
    while ($col = $columns->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "</tr>";
    }
    echo "</table><br>";
}
?>