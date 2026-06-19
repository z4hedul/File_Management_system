<?php
// test_assign_api.php - Test the assign file API

echo "<h2>Testing Assign File API</h2>";

// Test with actual data from your database
$test_data = [
    'file_id' => 1,  // Change this to an actual file ID from your office_files table
    'client_id' => 1 // Change this to an actual client ID from your client_profiles table
];

echo "<p>Testing with File ID: " . $test_data['file_id'] . " and Client ID: " . $test_data['client_id'] . "</p>";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/file_management_system/api/assign_file.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen(json_encode($test_data))
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p>HTTP Status: " . $http_code . "</p>";
echo "<p>Response: </p>";
echo "<pre>" . htmlspecialchars($response) . "</pre>";

// If response is JSON, parse and display
$json_response = json_decode($response, true);
if ($json_response) {
    echo "<p>Parsed Response:</p>";
    echo "<pre>";
    print_r($json_response);
    echo "</pre>";
    
    if (isset($json_response['success']) && $json_response['success']) {
        echo "<p style='color:green'>✅ Success! File assigned.</p>";
    } else {
        echo "<p style='color:red'>❌ Error: " . ($json_response['error'] ?? 'Unknown error') . "</p>";
    }
}
?>