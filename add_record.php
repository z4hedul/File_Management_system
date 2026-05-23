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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
// --- NEW BRANCH SPLIT LOGIC START ---
    $branch_info = $_POST['branch_info'] ?? '';
    $parts = explode('-', $branch_info);
    
    // $parts[0] = "0101", $parts[1] = "Dilkusha"
    $branch_code = isset($parts[0]) ? trim($parts[0]) : '';
    $branch_name = isset($parts[1]) ? trim($parts[1]) : '';
    // --- NEW BRANCH SPLIT LOGIC END ---

    $division = $_POST['division'] ?? '';
    $client   = $_POST['client'] ?? '';
    $cabinet  = $_POST['cabinet_name'] ?? '';
    $shelf    = $_POST['shelf_name'] ?? '';
    $file_no  = $_POST['file_no'] ?? '';
    $remarks   = $_POST['remarks'] ?? '';

    // 1. Insert the main record (Updated to include branch_code and branch_name)
    $stmt = $conn->prepare("INSERT INTO office_files (branch_code, branch_name, division, client, cabinet_name, shelf_name, file_no, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Notice: 9 "s" characters for 9 variables
    $stmt->bind_param("ssssssss", $branch_code, $branch_name, $division, $client, $cabinet, $shelf, $file_no, $remarks);

    if ($stmt->execute()) {
        $last_id = $conn->insert_id; 

        
        $message = "<div class='alert alert-success'>Record and PDF attachments saved successfully! <a href='index.php'>View Table</a></div>";
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
                    <div class="row mb-3">
    <div class="col-md-6">
        <label class="form-label fw-bold">Select Branch</label>
        <select name="branch_info" class="form-select" required>
            <option value="">-- Select Branch --</option>
            <option value="0101-Dilkusha">0101-Dilkusha</option>
            <option value="0102-Khatungonj">0102-Khatungonj</option>
            <option value="0103-Mohakhali">0103-Mohakhali</option>
            <option value="0104-Motijheel">0104-Motijheel</option>
        </select>
    </div>
    
    <div class="col-md-6">
        <label class="form-label fw-bold">Division</label>
        <select name="division" class="form-select" required>
            <option value="Investment">Investment</option>
            <option value="SME">SME</option>
            <option value="IMRD">IMRD</option>
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
// Required Description Validation
document.getElementById('addForm').onsubmit = function(e) {
    let rows = document.querySelectorAll('.attachment-row');
    for (let row of rows) {
        let fileInput = row.querySelector('.file-input');
        let descInput = row.querySelector('.desc-input');
        
        // If a file is chosen, the description MUST be filled
        if (fileInput.files.length > 0 && descInput.value.trim() === "") {
            alert("Please provide a description for all selected attachments.");
            descInput.focus();
            return false; 
        }
    }
    return true;
};

// Add dynamic rows
document.getElementById('add-more').addEventListener('click', function() {
    const container = document.getElementById('attachment-container');
    const newRow = document.createElement('div');
    newRow.className = 'row g-2 mb-2 attachment-row';
    newRow.innerHTML = `
        <div class="col-md-5">
            <input type="file" name="attachments[]" class="form-control file-input" accept=".pdf">
        </div>
        <div class="col-md-6">
            <input type="text" name="attachment_descriptions[]" class="form-control desc-input" placeholder="Description (e.g. Office Note, Board Memo etc))">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger w-100 remove-row"><i class="fas fa-minus"></i></button>
        </div>
    `;
    container.appendChild(newRow);
});

// Remove dynamic rows
document.getElementById('attachment-container').addEventListener('click', function(e) {
    if (e.target.classList.contains('remove-row') || e.target.closest('.remove-row')) {
        e.target.closest('.attachment-row').remove();
    }
});

// Front-end file type check
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('file-input')) {
        const file = e.target.files[0];
        if (file && file.type !== "application/pdf") {
            alert("Only PDF files are allowed!");
            e.target.value = ""; 
        }
    }
});
</script>
<?php
include 'footer.php';
?>
</body>
</html>