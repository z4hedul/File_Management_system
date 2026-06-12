<?php
session_start();
include 'db.php';
include 'header.php';

// Access Control: Ensure user is logged in and authorized
if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'admin') { 
    header("location: index.php"); 
    exit; 
}

$id = $_GET['id'] ?? null;
if (!$id) { 
    header("location: index.php"); 
    exit; 
}

$message = "";

// 1. FETCH ACTIVE BRANCHES MASTER CONFIGURATION FOR SELECTION MATRIX
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

// 2. HANDLE ENTRY UPDATE SUBMISSION VIA POST
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_record'])) {
    // Branch Parsing Logic
    $branch_info = $_POST['branch_info'] ?? '';
    $parts = explode('-', $branch_info);
    $branch_code = isset($parts[0]) ? trim($parts[0]) : '';
    $branch_name = isset($parts[1]) ? trim($parts[1]) : '';

    $division = trim($_POST['division'] ?? '');
    $zone = trim($_POST['zone'] ?? '');
    $client = isset($_POST['client']) ? ucwords(strtolower(trim($_POST['client']))) : '';
    $file_no = trim($_POST['file_no'] ?? '');
    $cabinet_name = trim($_POST['cabinet_name'] ?? '');
    $shelf_name = trim($_POST['shelf_name'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if (empty($branch_code) || empty($division) || empty($client) || empty($file_no)) {
        $message = "<div class='alert alert-danger'>Critical validation mismatch: Please populate all mandatory operational metrics.</div>";
    } else {
        // Update operational entity tracking metrics (including remarks)
        $update_stmt = $conn->prepare("UPDATE office_files SET branch_code = ?, branch_name = ?, division = ?, zone = ?, client = ?, file_no = ?, cabinet_name = ?, shelf_name = ?, remarks = ? WHERE id = ?");
        $update_stmt->bind_param("sssssssssi", $branch_code, $branch_name, $division, $zone, $client, $file_no, $cabinet_name, $shelf_name, $remarks, $id);

        if ($update_stmt->execute()) {
            $message = "<div class='alert alert-success'>Record matrix synchronized successfully! <a href='search.php'>View Table</a></div>";
        } else {
            $message = "<div class='alert alert-danger'>Error: " . $conn->error . "</div>";
        }
        $update_stmt->close();
    }
}

// 3. RETRIEVE CURRENT RECORD CONTEXT VALUES FOR COMPONENT INITIALIZATION
$stmt = $conn->prepare("SELECT * FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    echo "<div class='container mt-5'><div class='alert alert-danger fw-bold text-center shadow-sm'><i class='fas fa-exclamation-triangle me-2'></i>Target sequence context matrix not found or structural link missing.</div></div>";
    include 'footer.php';
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit File Record</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container" style="max-width: 850px;">
    <div class="card shadow border-0">
        <div class="card-header bg-primary text-white text-center p-3">
            <h4 class="mb-0">Edit Record</h4>
        </div>
        <div class="card-body p-4">
            <?php echo $message; ?>
            
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <div class="row mb-3">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Client Name</label>
                        <input type="text" name="client" class="form-control" placeholder="e.g. Jamuna Spinning Mills" value="<?= htmlspecialchars($record['client']) ?>" required>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Select Branch</label>
                        <select name="branch_info" id="branch_info_select" class="form-select" required onchange="autoPopulateZone(this)">
                            <option value="">-- Select Branch --</option>
                            <?php 
                            // Standardized trim to prevent spacing differences from breaking matches
                            $db_branch_code = trim($record['branch_code']);
                            
                            foreach ($branches_list as $branch): 
                                $b_code = trim($branch['branch_code']);
                                $b_name = trim($branch['branch_name']);
                                
                                $value_string = htmlspecialchars($b_code . '-' . $b_name);
                                $display_string = htmlspecialchars($b_code . ' - ' . $b_name);
                                
                                // Explicit check against the stored branch code
                                $selected_flag = ($b_code === $db_branch_code) ? 'selected' : '';
                            ?>
                                <option value="<?= $value_string ?>" data-zone="<?= htmlspecialchars(trim($branch['zone'])) ?>" <?= $selected_flag ?>>
                                    <?= $display_string ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                     </div>
                    
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Division</label>
                        <select name="division" class="form-select" required>
                            <option value="Investment" <?= $record['division'] === 'Investment' ? 'selected' : '' ?>>Investment</option>
                            <option value="SME" <?= $record['division'] === 'SME' ? 'selected' : '' ?>>SME</option>
                            <option value="IMRD" <?= $record['division'] === 'IMRD' ? 'selected' : '' ?>>IMRD</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Select Zone</label>
                        <!-- Note: kept read-only styles intact while ensuring updates flow through via JS -->
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
                        <input type="text" name="cabinet_name" class="form-control" placeholder="e.g 36" value="<?= htmlspecialchars($record['cabinet_name']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Shelf No</label>
                        <input type="text" name="shelf_name" class="form-control" placeholder="e.g 1,2,3,4" value="<?= htmlspecialchars($record['shelf_name']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">File No.</label>
                        <input type="text" name="file_no" class="form-control" placeholder="Serial no of cabinet" value="<?= htmlspecialchars($record['file_no']) ?>" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2" placeholder="Additional notes..."><?= htmlspecialchars($record['remarks'] ?? '') ?></textarea>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" name="update_record" class="btn btn-primary px-5">Save Changes</button>
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
    if (!selectElement) return;
    
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const zoneSelect = document.getElementById('zone_select');
    if (!zoneSelect) return;
    
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

// Automatically trigger auto-population on system layout initialize
document.addEventListener("DOMContentLoaded", function() {
    const branchSelect = document.getElementById('branch_info_select');
    if (branchSelect) {
        // Run immediately to populate saved entry zone
        autoPopulateZone(branchSelect);
    }
});
</script>

<?php
include 'footer.php';
?>
</body>
</html>