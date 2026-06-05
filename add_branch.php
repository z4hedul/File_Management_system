<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header("Location: login.php");
    exit;
}

$status_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $branch_code = trim($_POST['branch_code'] ?? '');
    $branch_name = trim($_POST['branch_name'] ?? '');
    $zone        = trim($_POST['zone'] ?? '');

    if (!empty($branch_code) && !empty($branch_name) && !empty($zone)) {
        try {
            $stmt = $conn->prepare("INSERT INTO branches (branch_code, branch_name, zone) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $branch_code, $branch_name, $zone);
            if ($stmt->execute()) {
                $status_msg = '<div class="alert alert-success shadow-sm"><strong>Success!</strong> Branch entry created successfully.</div>';
            } else {
                $status_msg = '<div class="alert alert-danger shadow-sm"><strong>Error:</strong> Duplicate branch code entry detected.</div>';
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $status_msg = '<div class="alert alert-danger shadow-sm"><strong>Database Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        $status_msg = '<div class="alert alert-warning shadow-sm"><strong>Warning:</strong> All input parameters are strictly mandatory.</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add System Branch Configuration</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light p-4">
<div class="container" style="max-width: 600px;">
    <div class="card shadow border-0 mt-5 bg-white">
        <div class="card-header bg-primary text-white py-3">
            <h5 class="mb-0 fw-bold"><i class="fas fa-code-branch me-2"></i>Register New Corporate Branch</h5>
        </div>
        <div class="card-body p-4">
            <?= $status_msg ?>
            <form method="POST" action="add_branch.php" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted text-uppercase">Branch Code</label>
                    <input type="text" name="branch_code" class="form-control form-control-lg border-primary" placeholder="e.g., BR-0041" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted text-uppercase">Branch Name</label>
                    <input type="text" name="branch_name" class="form-control form-control-lg border-primary" placeholder="e.g., Downtown Plaza Branch" required>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold small text-muted text-uppercase">Operational Zone Region</label>
                    <input type="text" name="zone" class="form-control form-control-lg border-primary" placeholder="e.g., North Region Zone" required>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm"><i class="fas fa-save me-1"></i> Save Branch</button>
                    <button type="button" class="btn btn-secondary btn-lg" onclick="window.close();">Close Window</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>