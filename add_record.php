<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';
include 'header.php';
if (!isset($_SESSION['loggedin'])) {
    header("location: login.php");
    exit;
}

$message = "";

// Fetch active branches configuration from the branches master table
$branches_list = [];
try {
    $branches_query = "SELECT branch_code, branch_name, zone FROM branches ORDER BY branch_code ASC";
    $branches_result = $conn->query($branches_query);
    if ($branches_result && $branches_result->num_rows > 0) {
        while ($row = $branches_result->fetch_assoc()) {
            $branches_list[] = $row;
        }
    }
} catch (mysqli_sql_exception $e) {
    $message = "<div class='alert alert-danger'>Table Configuration Error: Make sure the 'branches' table is created.</div>";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- BRANCH SPLIT LOGIC ---
    $branch_info = $_POST['branch_info'] ?? '';
    $parts = explode('-', $branch_info);
    
    $branch_code = isset($parts[0]) ? trim($parts[0]) : '';
    $branch_name = isset($parts[1]) ? trim($parts[1]) : '';

    $division = $_POST['division'] ?? '';
    $zone     = $_POST['zone'] ?? ''; // Maps directly to your existing zone column
    $client   = isset($_POST['client']) ? ucwords(strtolower(trim($_POST['client']))) : '';
    $cabinet  = $_POST['cabinet_name'] ?? '';
    $shelf    = $_POST['shelf_name'] ?? '';
    $file_no  = $_POST['file_no'] ?? '';
    $remarks  = $_POST['remarks'] ?? '';

    // Insert statement using your existing 'zone' column
    $stmt = $conn->prepare("INSERT INTO office_files (branch_code, branch_name, division, zone, client, cabinet_name, shelf_name, file_no, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param("sssssssss", $branch_code, $branch_name, $division, $zone, $client, $cabinet, $shelf, $file_no, $remarks);

    if ($stmt->execute()) {
        $last_id = $conn->insert_id; 
        $message = "<div class='alert alert-success'>Record saved successfully! <a href='search.php'>View Table</a></div>";
    } else {
        $message = "<div class='alert alert-danger'>Error: " . $conn->error . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New File Record</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container" style="max-width: 850px;">
    <div class="card shadow border-0">
        <div class="card-header bg-primary text-white text-center p-3">
            <h4 class="mb-0">Add Record</h4>
        </div>
        <div class="card-body p-4">
            <?php echo $message; ?>
            
            <form method="POST" enctype="multipart/form-data" id="addForm">
                <div class="row mb-3">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Client Name</label>
                        <input type="text" name="client" class="form-control" placeholder="e.g. Jamuna Spinning Mills" required>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Select Branch</label>
                        <select name="branch_info" id="branch_info_select" class="form-select" required onchange="autoPopulateZone(this)">
                            <option value="">-- Select Branch --</option>
                            <?php foreach ($branches_list as $branch): ?>
                                <?php 
                                    $value_string = htmlspecialchars($branch['branch_code'] . '-' . $branch['branch_name']);
                                    $display_string = htmlspecialchars($branch['branch_code'] . ' - ' . $branch['branch_name']);
                                ?>
                                <option value="<?= $value_string ?>" data-zone="<?= htmlspecialchars(trim($branch['zone'])) ?>">
                                    <?= $display_string ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="mt-1 d-flex flex-column gap-1">
                            <a href="add_branch.php" target="_blank" class="text-decoration-none small text-primary fw-semibold">
                                <i class="fas fa-plus-circle me-1"></i>Add branch manually
                            </a>
                            <!-- <a href="import_branches.php" class="text-decoration-none small text-success fw-semibold">
                                <i class="fas fa-file-excel me-1"></i>Bulk Import branches via CSV file
                            </a> -->
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Division</label>
                        <select name="division" class="form-select" required>
                            <option value="Investment">Investment</option>
                            <option value="SME">SME</option>
                            <option value="IMRD">IMRD</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Select Zone</label>
                        <select name="zone" id="zone_select" class="form-select bg-light text-muted" style="pointer-events: none; touch-action: none;" tabindex="-1" required>
                            <option value="">-- Select Zone --</option>
                            <option value="Head Office">Head Office</option>
                            <option value="Dhaka North Zone">Dhaka North Zone</option>
                            <option value="Dhaka South Zone">Dhaka South Zone</option>
                            <option value="Chattagram North Zone">Chattagram North Zone</option>
                            <option value="Chattagram South Zone">Chattagram South Zone</option>
                            <option value="Rajshahi Zone">Rajshahi Zone</option>
                            <option value="Khulna Zone">Khulna Zone</option>
                            <option value="Barishal Zone">Barishal Zone</option>
                            <option value="Cumilla Zone">Cumilla Zone</option>
                        </select>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Cabinet No</label>
                        <input type="text" name="cabinet_name" class="form-control" placeholder="e.g 36" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Shelf No</label>
                        <input type="text" name="shelf_name" class="form-control" placeholder="e.g 1,2,3,4" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">File No.</label>
                        <input type="text" name="file_no" class="form-control" placeholder="Serial no of cabinet" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-5">Save All</button>
                    <a href="index.php" class="btn btn-outline-secondary px-4">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/**
 * Smart Mapping Engine: Maps the data-zone attribute to the dropdown menu.
 * Cleans up extra spaces, ignores capitalization differences, and generates
 * fallback options dynamically so fields never stay blank.
 */
function autoPopulateZone(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const zoneSelect = document.getElementById('zone_select');
    
    // Clear out any previous temporary custom options added on the fly
    const dynamicOption = document.getElementById('temp_dynamic_zone_opt');
    if (dynamicOption) {
        dynamicOption.remove();
    }

    if (selectedOption && selectedOption.value !== "") {
        // Retrieve and scrub the zone string data representation
        const rawTargetZone = selectedOption.getAttribute('data-zone') || "";
        const targetZone = rawTargetZone.trim();
        
        if (targetZone !== "") {
            let matchFound = false;
            const normalizedTarget = targetZone.toLowerCase();

            // Iterate through hardcoded dropdown items to match casing variations
            for (let i = 0; i < zoneSelect.options.length; i++) {
                if (zoneSelect.options[i].value.toLowerCase().trim() === normalizedTarget) {
                    zoneSelect.selectedIndex = i;
                    matchFound = true;
                    break;
                }
            }

            // Fallback Engine: If it isn't found in your hardcoded list, generate it dynamically 
            // so the user sees the zone data and it correctly POSTs to the database.
            if (!matchFound) {
                const newOpt = document.createElement('option');
                newOpt.id = 'temp_dynamic_zone_opt';
                newOpt.value = targetZone;
                newOpt.textContent = targetZone;
                newOpt.selected = true;
                zoneSelect.appendChild(newOpt);
            }
        } else {
            zoneSelect.value = "";
        }
    } else {
        zoneSelect.value = "";
    }
}
</script>

<?php
include 'footer.php';
?>
</body>
</html>