<?php
// test_security_api.php - Test the get_security API

echo "<h2>Testing Get Security API</h2>";

// Test data - replace with actual client_id from your database
$test_data = [
    'client_id' => 1,  // Change this to an actual client ID
    'facility_type' => 'IDBP'  // Change this to an actual facility type
];

echo "<pre>";
echo "Sending data: ";
print_r($test_data);
echo "</pre>";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/file_management_system/api/get_security.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($test_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen(json_encode($test_data))
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

echo "<p>HTTP Status: " . $http_code . "</p>";
if ($curl_error) {
    echo "<p style='color:red'>CURL Error: " . $curl_error . "</p>";
}
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
        echo "<p style='color:green'>✅ Success! Found " . count($json_response['data'] ?? []) . " security records.</p>";
    } else {
        echo "<p style='color:red'>❌ Error: " . ($json_response['error'] ?? 'Unknown error') . "</p>";
    }
} else {
    echo "<p style='color:red'>❌ Invalid JSON response</p>";
    echo "<p>Raw response: " . htmlspecialchars($response) . "</p>";
}
?>