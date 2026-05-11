<?php
session_start();
include 'db.php';

// 1. Validate ID
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $conn->prepare("SELECT * FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();

if (!$file) {
    die("<div class='alert alert-danger'>File not found!</div>");
}

// 2. Handle the Transfer Post
// ... existing database fetching code ...

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $to_division = $_POST['to_division'];
    
    // If 'Other' was selected, use the manual input value
    if ($to_division === "Other" && !empty($_POST['other_division_name'])) {
        $to_division = trim($_POST['other_division_name']);
    }
    $remarks = $_POST['remarks'];
    $from_division = $file['division'];
    $user = $_SESSION['username'] ?? 'System';

    $conn->begin_transaction();
    try {
        // Update main table
        $update = $conn->prepare("UPDATE office_files SET division = ? WHERE id = ?");
        $update->bind_param("si", $to_division, $id);
        $update->execute();

        // Log history
        $log = $conn->prepare("INSERT INTO file_transfers (file_id, from_division, to_division, transferred_by, remarks) VALUES (?, ?, ?, ?, ?)");
        $log->bind_param("issss", $id, $from_division, $to_division, $user, $remarks);
        $log->execute();

        $conn->commit();
        
        // REDIRECT TO SELF with status
        header("Location: transfer_file.php?id=$id&status=success");
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Transfer failed: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Transfer File</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f4f7f6; font-family: 'Segoe UI', sans-serif; }
        .transfer-card { border-radius: 15px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .header-icon { background: #1e3a8a; color: white; width: 60px; height: 60px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: -30px auto 10px; font-size: 24px; border: 5px solid #f4f7f6; }
    </style>
</head>
<script>
function toggleOtherInput() {
    var selectBox = document.getElementById("to_division");
    var otherInputContainer = document.getElementById("other_division_container");
    var otherInput = document.getElementById("other_division_name");

    if (selectBox.value === "Other") {
        otherInputContainer.style.display = "block";
        otherInput.setAttribute("required", "required"); // Make it mandatory
        otherInput.focus();
    } else {
        otherInputContainer.style.display = "none";
        otherInput.removeAttribute("required");
        otherInput.value = ""; // Clear it if they change their mind
    }
}
</script>
<body>

<div class="container mt-5">
    <div class="card transfer-card mx-auto" style="max-width: 550px;">
        <div class="header-icon"><i class="fas fa-exchange-alt"></i></div>
        <div class="card-body">
            
            <?php if(isset($_GET['status']) && $_GET['status'] == 'success'): ?>
                <div class="alert alert-success border-0 shadow-sm mb-4">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-check-circle fa-2x me-3"></i>
                        <div>
                            <h6 class="mb-0 fw-bold">Transfer Complete!</h6>
                            <small>The file is now located in the new division.</small>
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <a href="index.php" class="btn btn-sm btn-success">Go to Dashboard</a>
                        <a href="view_details.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-success">View History</a>
                    </div>
                </div>
            <?php endif; ?>

            <h4 class="text-center fw-bold text-primary mb-4">Transfer Division</h4>
            
            <div class="alert alert-info py-2">
                <small class="d-block text-uppercase opacity-75">File Name / No:</small>
                <strong><?php echo htmlspecialchars($file['client']); ?></strong>
            </div>

            <form method="POST" id="transferForm">
    <div class="mb-3">
        <label class="form-label fw-bold small text-muted">Current Division</label>
        <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($file['division']); ?>" readonly>
    </div>

    <div class="mb-3">
        <label class="form-label fw-bold">Select Target Division</label>
        <select name="to_division" id="to_division" class="form-select border-primary" required onchange="toggleOtherInput()">
            <option value="">-- Choose New Division --</option>
            
            <?php if($file['division'] !== 'Investment'): ?>
                <option value="Investment">Investment</option>
            <?php endif; ?>

            <?php if($file['division'] !== 'IMRD'): ?>
                <option value="IMRD">IMRD</option>
            <?php endif; ?>

            <?php if($file['division'] !== 'SME'): ?>
                <option value="SME">SME</option>
            <?php endif; ?>

            <option value="Other">Other (Type manually...)</option>
        </select>
    </div>

    <div class="mb-3" id="other_division_container" style="display: none;">
        <label class="form-label fw-bold text-danger">Type Division Name</label>
        <input type="text" name="other_division_name" id="other_division_name" class="form-control border-danger" placeholder="Enter new division name">
    </div>

    <div class="mb-3">
        <label class="form-label fw-bold">Remarks / Reason</label>
        <textarea name="remarks" class="form-control" rows="3" placeholder="Why is this file being moved?"></textarea>
    </div>

    <div class="d-grid gap-2">
        <button type="submit" class="btn btn-primary py-2 fw-bold">Confirm Transfer</button>
        <a href="index.php" class="btn btn-link text-muted text-decoration-none">Cancel and Go Back</a>
    </div>
</form>
        </div>
    </div>
</div>

</body>
</html>