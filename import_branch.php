<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
include 'header.php';

// Security layer check
if (!isset($_SESSION['loggedin'])) {
    header("location: login.php");
    exit;
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES["csv_file"])) {
    $file = $_FILES["csv_file"];

    // Basic Validation: Ensure a file was uploaded without errors
    if ($file["error"] !== UPLOAD_ERR_OK) {
        $message = "<div class='alert alert-danger'>Error uploading file. Please try again.</div>";
    } else {
        $file_extension = pathinfo($file["name"], PATHINFO_EXTENSION);
        
        // Ensure the file is actually a CSV
        if (strtolower($file_extension) !== 'csv') {
            $message = "<div class='alert alert-danger'>Invalid file format. Please upload a standard <strong>.csv</strong> file.</div>";
        } else {
            // Open the file for reading
            if (($handle = fopen($file["tmp_name"], "r")) !== FALSE) {
                
                // Read the first row to skip headers (e.g., branch_code, branch_name, zone)
                $headers = fgetcsv($handle, 1000, ",");
                
                $success_count = 0;
                $error_count = 0;

                // Prepare the SQL statement to check and insert records securely
                // Using an "ON DUPLICATE KEY UPDATE" clause so existing branch codes get updated instead of crashing
                $stmt = $conn->prepare("INSERT INTO branches (branch_code, branch_name, zone) VALUES (?, ?, ?) 
                                        ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name), zone = VALUES(zone)");

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    // Check if row has the required 3 columns
                    if (count($data) >= 3) {
                        $branch_code = trim($data[0]);
                        $branch_name = trim($data[1]);
                        $zone        = trim($data[2]);

                        // Skip empty rows
                        if (empty($branch_code) || empty($branch_name) || empty($zone)) {
                            $error_count++;
                            continue;
                        }

                        $stmt->bind_param("sss", $branch_code, $branch_name, $zone);
                        
                        if ($stmt->execute()) {
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    } else {
                        $error_count++;
                    }
                }
                
                fclose($handle);
                $stmt->close();

                $message = "<div class='alert alert-success'>
                                <strong>Import Complete!</strong><br>
                                <i class='fas fa-check-circle me-1'></i> Successfully imported/updated: <strong>$success_count</strong> rows.<br>
                                " . ($error_count > 0 ? "<i class='fas fa-exclamation-triangle me-1'></i> Skipped/Failed rows: <strong>$error_count</strong> (Check data format)." : "") . "
                                <br><a href='add_record.php' class='btn btn-sm btn-primary mt-2'>Go back to Add Record</a>
                            </div>";
            } else {
                $message = "<div class='alert alert-danger'>Failed to open the uploaded file.</div>";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Import Branches via CSV</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container" style="max-width: 650px;">
    <div class="card shadow border-0">
        <div class="card-header bg-success text-white text-center p-3">
            <h4 class="mb-0"><i class="fas fa-file-csv me-2"></i>Bulk Import Branches</h4>
        </div>
        <div class="card-body p-4">
            
            <?= $message; ?>

            <div class="alert alert-info small">
                <strong><i class="fas fa-info-circle"></i> Instructions:</strong><br>
                1. Your file must be saved in a comma-separated <strong>.csv</strong> format.<br>
                2. The first row must be the header column labels (it will be automatically skipped).<br>
                3. The format columns must strictly follow this order: 
                <span class="badge bg-dark">branch_code</span>, 
                <span class="badge bg-dark">branch_name</span>, 
                <span class="badge bg-dark">zone</span>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold small text-muted text-uppercase">Expected Data Template Structure:</label>
                <div class="bg-dark text-white font-monospace p-3 rounded small">
                    branch_code,branch_name,zone<br>
                    0101,Dilkusha,Head Office<br>
                    0102,Khatungonj,Chattagram North Zone<br>
                    0103,Mohakhali,Dhaka North Zone
                </div>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-4">
                    <label class="form-label fw-bold">Select CSV File</label>
                    <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-success px-5 fw-bold">
                        <i class="fas fa-upload me-1"></i> Start Import
                    </button>
                    <a href="add_record.php" class="btn btn-outline-secondary px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>