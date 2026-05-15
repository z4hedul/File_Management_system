<?php
session_start();
include 'db.php';
if (!isset($_SESSION['loggedin']) || ($_SESSION['role'] ?? '') !== 'admin') { 
    header("location: index.php"); 
    exit; 
}

$id = $_GET['id'] ?? null;
if (!$id) { header("location: index.php"); exit; }

// --- 1. HANDLE ATTACHMENT DELETION ---
if (isset($_GET['delete_file'])) {
    $file_id = $_GET['delete_file'];
    $path_stmt = $conn->prepare("SELECT file_path FROM file_attachments WHERE id = ?");
    $path_stmt->bind_param("i", $file_id);
    $path_stmt->execute();
    $file_data = $path_stmt->get_result()->fetch_assoc();

    if ($file_data) {
        if (file_exists($file_data['file_path'])) { unlink($file_data['file_path']); }
        $del = $conn->prepare("DELETE FROM file_attachments WHERE id = ?");
        $del->bind_param("i", $file_id);
        $del->execute();
    }
    header("Location: edit.php?id=$id");
    exit;
}

// --- 2. FETCH MAIN DATA ---
$stmt = $conn->prepare("SELECT * FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

// --- 3. FETCH EXISTING ATTACHMENTS ---
$attach_stmt = $conn->prepare("SELECT * FROM file_attachments WHERE file_record_id = ?");
$attach_stmt->bind_param("i", $id);
$attach_stmt->execute();
$existing_attachments = $attach_stmt->get_result();

// --- 4. HANDLE UPDATE ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Use the session username to track who is making the change
    $updated_by = $_SESSION['username']; 
// UPDATED LOGIC: Split branch_info into code and name
    $branch_info = $_POST['branch_info'] ?? '';
    $parts = explode('-', $branch_info);
    $branch_code = isset($parts[0]) ? trim($parts[0]) : '';
    $branch_name = isset($parts[1]) ? trim($parts[1]) : '';

$update = $conn->prepare("UPDATE office_files SET branch_name=?, division=?, client=?, cabinet_name=?, shelf_name=?, file_no=?, remarks=?, updated_by=? WHERE id=?");

// Note the extra "s" for updated_by
$update->bind_param("ssssssssi", $branch, $division, $client, $cabinet, $shelf, $file_no, $remarks, $updated_by, $id);
    $client = $_POST['client'];    
    $division = $_POST['division'];
    $cabinet = $_POST['cabinet_name'];
    $shelf = $_POST['shelf_name'];
    $file_no = $_POST['file_no'];
    $remarks = $_POST['remarks'];
    $update = $conn->prepare("UPDATE office_files SET branch_code=?, branch_name=?, division=?, client=?, cabinet_name=?, shelf_name=?, file_no=?, remarks=?, updated_by=? WHERE id=?");
   $update->bind_param("sssssssssi", $branch_code, $branch_name, $division, $client, $cabinet, $shelf, $file_no, $remarks, $updated_by, $id);
    
    if ($update->execute()) {

        // --- ADDED: UPDATE EXISTING DESCRIPTIONS ---
        if (isset($_POST['existing_descriptions'])) {
            foreach ($_POST['existing_descriptions'] as $att_id => $new_desc) {
                $upd_desc = $conn->prepare("UPDATE file_attachments SET description = ? WHERE id = ?");
                $upd_desc->bind_param("si", $new_desc, $att_id);
                $upd_desc->execute();
            }
        }

        // Handle new PDF attachments
        if (!empty($_FILES['attachments']['name'][0])) {
            foreach ($_FILES['attachments']['name'] as $key => $name) {
                if ($_FILES['attachments']['error'][$key] == 0) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if($ext === 'pdf') {
                        $desc = $_POST['attachment_descriptions'][$key];
                        
                        // --- UPDATED: NAMING LOGIC ---
                        $clean_client = preg_replace('/[^A-Za-z0-9]/', '', $client);
                        $clean_desc = preg_replace('/[^A-Za-z0-9]/', '', $desc);
                        $new_name = $clean_client . "_" . $clean_desc . "_" . uniqid() . ".pdf";
                        
                        $new_path = "uploads/" . $new_name;
                        
                        move_uploaded_file($_FILES['attachments']['tmp_name'][$key], $new_path);
                        $ins = $conn->prepare("INSERT INTO file_attachments (file_record_id, file_path, description) VALUES (?, ?, ?)");
                        $ins->bind_param("iss", $id, $new_path, $desc);
                        $ins->execute();
                    }
                }
            }
        }
        // This keeps the user on the edit page and passes a success flag in the URL
header("Location: edit.php?id=$id&status=success");
exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Edit Record</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-5">
<div class="container bg-white p-4 shadow rounded" style="max-width: 800px;">
<?php if (isset($_GET['status']) && $_GET['status'] == 'success'): ?>
    <div class="alert alert-success d-flex align-items-center justify-content-between shadow-sm" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-check-circle me-2"></i>
            <div>
                <strong>Update Successful!</strong> The record has been saved.
            </div>
        </div>
        <!-- This button helps the user navigate back easily -->
        <a href="index.php" class="btn btn-success btn-sm shadow-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to Home
        </a>
    </div>
<?php endif; ?>  
<h3>Update File & Attachments</h3>
    <hr>
    <form method="POST" enctype="multipart/form-data" id="editForm">
        <div class="row mb-3">
         <div class="mb-3">
            <label class="fw-bold">Client Name</label>
            <input type="text" name="client" class="form-control" value="<?=htmlspecialchars($data['client'] ?? '')?>" required>
        </div>
            <div class="col-md-6">
                <label class="fw-bold">Branch Name</label>
                <select name="branch_info" class="form-select" required>
                    <?php 
                    $branches = ["0101-Dilkusha", "0102-Khatungonj", "0103-Mohakhali", "0104-Motijheel"];
                    // Reconstruct current value to match dropdown (Code-Name)
                    $current_val = $data['branch_code'] . "-" . $data['branch_name'];
                    foreach($branches as $b) {
                        $sel = ($current_val == $b) ? "selected" : "";
                        echo "<option value='$b' $sel>$b</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="fw-bold">Division</label>
                <select name="division" class="form-select" required>
                    <?php 
                    $options = ["Investment", "SME", "IMRD"];
                    foreach($options as $opt) {
                        $sel = ($data['division'] == $opt) ? "selected" : "";
                        echo "<option value='$opt' $sel>$opt</option>";
                    }
                    ?>
                </select>
            </div>
        </div>

       <div class="row mb-3">
            <div class="col-md-4"><label class="fw-bold">Cabinet</label><input type="text" name="cabinet_name" class="form-control" value="<?=htmlspecialchars($data['cabinet_name'] ?? '')?>" required></div>
            <div class="col-md-4"><label class="fw-bold">Shelf</label><input type="text" name="shelf_name" class="form-control" value="<?=htmlspecialchars($data['shelf_name'] ?? '')?>" required></div>
            <div class="col-md-4"><label class="fw-bold">File No</label><input type="text" name="file_no" class="form-control" value="<?=htmlspecialchars($data['file_no'] ?? '')?>" required></div>
        </div>

        <div class="mb-3">
            <label class="fw-bold">Remarks</label>
            <textarea name="remarks" class="form-control"><?=htmlspecialchars($data['remarks'] ?? '')?></textarea>
        </div>

        <div class="mb-4">
            <h5 class="text-primary">Current Attachments</h5>
            <div class="list-group">
                <?php if ($existing_attachments->num_rows > 0): ?>
                    <?php while($file = $existing_attachments->fetch_assoc()): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1 me-3">
                                <i class="fas fa-file-pdf text-danger me-2"></i>
                                <input type="text" name="existing_descriptions[<?=$file['id']?>]" class="form-control form-control-sm d-inline-block w-75" value="<?=htmlspecialchars($file['description'] ?? '')?>" placeholder="Update description">
                            </div>
                            <div class="text-nowrap">
                                <a href="<?=$file['file_path']?>" target="_blank" class="btn btn-sm btn-outline-primary">View</a>
                                <a href="edit.php?id=<?=$id?>&delete_file=<?=$file['id']?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this attachment permanently?')">Delete</a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="text-muted small">No attachments found for this record.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="p-3 bg-light border rounded">
            <h5 class="text-success">Add New Attachments (PDF Only)</h5>
            <div id="attachment-container">
                <div class="row g-2 mb-2 attachment-row">
                    <div class="col-md-5"><input type="file" name="attachments[]" class="form-control file-input" accept=".pdf"></div>
                    <div class="col-md-6"><input type="text" name="attachment_descriptions[]" class="form-control desc-input" placeholder="Description (Required)"></div>
                    <div class="col-md-1"><button type="button" class="btn btn-success w-100" id="add-more">+</button></div>
                </div>
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary px-5">Update Record</button>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script>
    // --- ADDED: VALIDATION LOGIC ---
    document.getElementById('editForm').onsubmit = function(e) {
        let rows = document.querySelectorAll('.attachment-row');
        for (let row of rows) {
            let fileInput = row.querySelector('.file-input');
            let descInput = row.querySelector('.desc-input');
            
            // If file is selected but description is empty
            if (fileInput.files.length > 0 && descInput.value.trim() === "") {
                alert("Please fill in the description for all new attachments.");
                descInput.focus();
                e.preventDefault();
                return false;
            }
        }
    };

    document.getElementById('add-more').onclick = function() {
        let div = document.createElement('div');
        div.className = 'row g-2 mb-2 attachment-row';
        div.innerHTML = '<div class="col-md-5"><input type="file" name="attachments[]" class="form-control file-input" accept=".pdf"></div>' +
                        '<div class="col-md-6"><input type="text" name="attachment_descriptions[]" class="form-control desc-input" placeholder="Description (Required)"></div>' +
                        '<div class="col-md-1"><button type="button" class="btn btn-danger w-100 remove-row">-</button></div>';
        document.getElementById('attachment-container').appendChild(div);
    };
    
    document.addEventListener('click', function(e){
        if(e.target.classList.contains('remove-row')) {
            e.target.closest('.row').remove();
        }
    });
</script>
</body>
</html>