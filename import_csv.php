<?php
session_start();
include 'db.php'; // Ensure your db.php establishes a $conn MySQLi connection
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$status_message = '';
$alert_class = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    // Validate if a file was uploaded without errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $status_message = "Error uploading file. Please try again.";
        $alert_class = "alert-danger";
    } else {
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);

        // Basic verification to ensure it's a CSV file
        if (strtolower($file_extension) !== 'csv') {
            $status_message = "Invalid file format. Please upload a valid .csv file.";
            $alert_class = "alert-danger";
        } else {
            // Open the uploaded file for reading
            if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
                
                // SQL query updated with your new sequence structure 
                // (Assuming columns in database are named branch_name, branch_code, division, zone, client, cabinet_name, shelf_name, file_no)
                $sql = "INSERT INTO office_files (branch_name, branch_code, division, zone, client, cabinet_name, shelf_name, file_no) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);

                if ($stmt === FALSE) {
                    $status_message = "Database error: Unable to prepare SQL statements.";
                    $alert_class = "alert-danger";
                } else {
                    $success_count = 0;
                    $row_count = 0;
                    
                    // Check if user checked "Skip first row header"
                    $skip_header = isset($_POST['skip_header']);

                    // Loop through each line of the CSV file
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $row_count++;

                        // Skip the first row if it contains text headers
                        if ($skip_header && $row_count === 1) {
                            continue;
                        }

                        // Ensure there are at least 8 columns in this row to match your sequence
                        if (count($data) >= 8) {
                            // Extract and clean values based on your sequence map
                            $branch_name  = trim($data[0]);
                            $branch_code  = trim($data[1]);
                            $division     = trim($data[2]);
                            $zone         = trim($data[3]);
                            
                            // Format Client Name so the first letter of every word is uppercase
                            $raw_client   = trim($data[4]);
                            $client       = ucwords(strtolower($raw_client)); 
                            
                            $cabinet_name = trim($data[5]);
                            $shelf_name   = trim($data[6]);
                            $file_no      = trim($data[7]);

                            // Bind parameters (8 's' tokens for 8 string values)
                            $stmt->bind_param("ssssssss", $branch_name, $branch_code, $division, $zone, $client, $cabinet_name, $shelf_name, $file_no);
                            
                            if ($stmt->execute()) {
                                $success_count++;
                            }
                        }
                    }
                    fclose($handle);
                    $stmt->close();

                    $status_message = "Successfully imported <strong>$success_count</strong> out of " . ($skip_header ? $row_count - 1 : $row_count) . " records.";
                    $alert_class = "alert-success";
                }
            } else {
                $status_message = "Failed to open the uploaded file.";
                $alert_class = "alert-danger";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bulk Import File Records</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container bg-white p-4 shadow rounded" style="max-width: 700px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="text-primary mb-0"><i class="fas fa-file-upload me-2"></i> CSV Batch File Importer</h4>
        <a href="index.php" class="btn btn-sm btn-secondary"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>

    <?php if (!empty($status_message)): ?>
        <div class="alert <?php echo $alert_class; ?> alert-dismissible fade show" role="alert">
            <?php echo $status_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 bg-light p-3 mb-4 rounded-3">
        <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-info-circle me-1"></i> Required CSV Column Sequence Layout:</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered bg-white small text-center mb-2">
                <thead class="table-dark">
                    <tr>
                        <th>1</th>
                        <th>2</th>
                        <th>3</th>
                        <th>4</th>
                        <th>5</th>
                        <th>6</th>
                        <th>7</th>
                        <th>8</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="font-monospace text-muted" style="font-size: 11px;">
                        <td>Branch Name</td>
                        <td>Branch Code</td>
                        <td>Division</td>
                        <td>Zone</td>
                        <td>Client</td>
                        <td>Cabinet Name</td>
                        <td>Shelf Name</td>
                        <td>File No</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ul class="small text-muted mb-0 ps-3">
            <li>Ensure your file columns perfectly align with the mapping sequence shown above.</li>
            <li>Client names are automatically fixed to standard Title Case during processing.</li>
        </ul>
    </div>

    <form action="import_csv.php" method="POST" enctype="multipart/form-data">
        <div class="mb-4">
            <label for="csv_file" class="form-label fw-semibold text-dark">Select Target CSV File</label>
            <div class="input-group">
                <span class="input-group-text bg-primary text-white border-primary"><i class="fas fa-file-csv fs-5"></i></span>
                <input type="file" name="csv_file" id="csv_file" class="form-control border-primary" accept=".csv" required>
            </div>
        </div>

        <div class="mb-4 form-check form-switch">
            <input class="form-check-input" type="checkbox" name="skip_header" id="skip_header" checked>
            <label class="form-check-label small fw-medium text-secondary" for="skip_header">
                My CSV file contains a header row (Skip the first row during processing)
            </label>
        </div>

        <div class="pt-2 border-top">
            <button type="submit" class="btn btn-primary px-4 shadow rounded-3">
                <i class="fas fa-cloud-upload-alt me-1"></i> Execute File Import
            </button>
            <a href="index.php" class="btn btn-outline-secondary px-3 rounded-3 ms-2">Cancel</a>
        </div>
    </form>
</div>

<?php include 'footer.php'; ?>
</body>
</html>