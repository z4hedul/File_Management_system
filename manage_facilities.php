<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$status_message = '';

// ================= PROCESS REFERENCE CREATION / EDITS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_facility'])) {
    $facility_name  = trim($_POST['facility_name']);
    $facility_group = trim($_POST['facility_group']);
    $facility_id    = isset($_POST['facility_id']) ? intval($_POST['facility_id']) : 0;

    if (empty($facility_name) || empty($facility_group)) {
        $status_message = '<div class="alert alert-danger alert-dismissible fade show shadow-sm border-start border-danger border-3" role="alert">
                            <strong>Error:</strong> All configuration fields are required.
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                           </div>';
    } else {
        if ($facility_id > 0) {
            // Processing Update/Edit Mode
            $stmt = $conn->prepare("UPDATE facilities_type SET facility_name = ?, facility_group = ? WHERE id = ?");
            $stmt->bind_param("ssi", $facility_name, $facility_group, $facility_id);
            $success_text = "Facility profile configuration modified successfully.";
        } else {
            // Processing Fresh Record Mode
            $stmt = $conn->prepare("INSERT INTO facilities_type (facility_name, facility_group) VALUES (?, ?) ON DUPLICATE KEY UPDATE facility_group = ?");
            $stmt->bind_param("sss", $facility_name, $facility_group, $facility_group);
            $success_text = "Facility profile reference updated accurately.";
        }
        
        if ($stmt->execute()) {
            $status_message = '<div class="alert alert-success alert-dismissible fade show shadow-sm border-start border-success border-3" role="alert">
                                <i class="fas fa-check-circle me-1"></i> <strong>Success:</strong> '.$success_text.'
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                               </div>';
        } else {
            $status_message = '<div class="alert alert-danger alert-dismissible fade show shadow-sm border-start border-danger border-3" role="alert">
                                <strong>Database Error:</strong> Failed to save parameter variant.
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                               </div>';
        }
        $stmt->close();
    }
}

// ================= PROCESS REFERENCE REMOVALS =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_facility'])) {
    $facility_id = intval($_POST['target_facility_id'] ?? 0);

    if ($facility_id > 0) {
        $stmt = $conn->prepare("DELETE FROM facilities_type WHERE id = ?");
        $stmt->bind_param("i", $facility_id);
        
        if ($stmt->execute()) {
            $status_message = '<div class="alert alert-warning alert-dismissible fade show shadow-sm border-start border-warning border-3" role="alert">
                                <i class="fas fa-trash-alt me-1"></i> <strong>Removed:</strong> Parameter metric entry wiped from system schema.
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                               </div>';
        } else {
            $status_message = '<div class="alert alert-danger alert-dismissible fade show shadow-sm border-start border-danger border-3" role="alert">
                                <strong>Database Error:</strong> Could not remove reference item. It might be linked to operational proposals.
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                               </div>';
        }
        $stmt->close();
    }
}

// Fetch current configurations
$facilities_query = $conn->query("SELECT * FROM facilities_type ORDER BY facility_group ASC, facility_name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Facility System Configurations</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light">
<div class="container app-page-wrapper py-4">
    <div class="row g-4">
        
        <div class="col-md-5">
            <?= $status_message; ?>
            <div class="card shadow border-0 rounded-3 bg-white">
                <div class="card-header bg-dark text-white p-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-sliders-h me-2 text-warning"></i>Define Facility</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="facility_name" class="form-label fw-bold small text-secondary">Facility Name</label>
                            <input type="text" name="facility_name" id="facility_name" class="form-control border-primary" placeholder="e.g. Continuous Loan (CL)" required autocomplete="off">
                        </div>
                        
                        <div class="mb-3">
                            <label for="facility_group" class="form-label fw-bold small text-secondary">Reporting Classification Group</label>
                            <select name="facility_group" id="facility_group" class="form-select border-primary" required>
                                <option value="" disabled selected>-- Select Facility Group --</option>
                                <option value="Funded">Funded Facility</option>
                                <option value="Non-Funded">Non-Funded Facility</option>
                            </select>
                        </div>

                        <div class="d-grid pt-2">
                            <button type="submit" name="save_facility" class="btn btn-primary fw-bold text-uppercase shadow-sm">
                                <i class="fas fa-plus-circle me-2"></i>Save Dynamic Parameter Option
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="card shadow border-0 rounded-3 bg-white">
                <div class="card-header bg-white p-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-list text-secondary me-2"></i>Active Dynamic System Parameters Lookup</h5>
                </div>
                <div class="table-responsive" style="max-height: 550px; overflow-y: auto;">
                    <table class="table table-hover table-striped align-middle mb-0 small">
                        <span class="d-none">Active Reference List Grid</span>
                        <thead class="table-light sticky-top">
                            <tr>
                                <th style="width: 8%;">#</th>
                                <th style="width: 47%;">Facility Name</th>
                                <th style="width: 25%;">Facility Group</th>
                                <th style="width: 20%;" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($facilities_query && $facilities_query->num_rows > 0): $index = 1; ?>
                                <?php while ($f = $facilities_query->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= $index++; ?></td>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($f['facility_name']) ?></td>
                                        <td>
                                            <?php if(trim($f['facility_group']) === 'Funded'): ?>
                                                <span class="badge bg-success px-2 py-1 text-uppercase" style="font-size:0.65rem; letter-spacing: 0.3px;">Funded</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary px-2 py-1 text-uppercase" style="font-size:0.65rem; letter-spacing: 0.3px;">Non-Funded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-inline-flex gap-1">
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-primary px-2 py-1" 
                                                        style="font-size: 11px;"
                                                        onclick="openEditModal(<?= $f['id'] ?>, '<?= htmlspecialchars(addslashes($f['facility_name'])) ?>', '<?= htmlspecialchars(addslashes($f['facility_group'])) ?>')">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-danger px-2 py-1" 
                                                        style="font-size: 11px;"
                                                        onclick="openDeleteModal(<?= $f['id'] ?>, '<?= htmlspecialchars(addslashes($f['facility_name'])) ?>')">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        <i class="fas fa-folder-open fa-2x mb-2 opacity-50"></i><br>
                                        No custom facilities configured yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="editFacilityModal" data-bs-backdrop="static" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="editModalLabel"><i class="fas fa-edit me-2"></i>Modify Facility Parameters</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body p-4">
                    <input type="hidden" name="facility_id" id="edit_facility_id">
                    
                    <div class="mb-3">
                        <label for="edit_facility_name" class="form-label fw-bold small text-secondary">Facility Name</label>
                        <input type="text" name="facility_name" id="edit_facility_name" class="form-control" required autocomplete="off">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_facility_group" class="form-label fw-bold small text-secondary">Reporting Classification Group</label>
                        <select name="facility_group" id="edit_facility_group" class="form-select" required>
                            <option value="Funded">Funded Facility</option>
                            <option value="Non-Funded">Non-Funded Facility</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_facility" class="btn btn-primary fw-bold"><i class="fas fa-save me-1"></i> Update Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteFacilityModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title fw-bold" id="deleteModalLabel"><i class="fas fa-exclamation-triangle me-1"></i> Confirm Drop</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body text-center p-4">
                    <input type="hidden" name="target_facility_id" id="delete_facility_id">
                    <p class="mb-1 small text-muted">Are you sure you want to permanently delete:</p>
                    <h6 class="fw-bold text-danger mb-0" id="delete_facility_label">Facility</h6>
                </div>
                <div class="modal-footer justify-content-center bg-light border-top py-2">
                    <button type="button" class="btn btn-sm btn-secondary fw-bold" data-bs-dismiss="modal">No, Keep</button>
                    <button type="submit" name="delete_facility" class="btn btn-sm btn-danger fw-bold"><i class="fas fa-trash-alt me-1"></i> Yes, Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
// Instantiate Bootstrap Modals programmatically
let editModalObj = null;
let deleteModalObj = null;

document.addEventListener("DOMContentLoaded", function() {
    editModalObj = new bootstrap.Modal(document.getElementById('editFacilityModal'));
    deleteModalObj = new bootstrap.Modal(document.getElementById('deleteFacilityModal'));
});

function openEditModal(id, name, group) {
    document.getElementById('edit_facility_id').value = id;
    document.getElementById('edit_facility_name').value = name;
    document.getElementById('edit_facility_group').value = group;
    if(editModalObj) editModalObj.show();
}

function openDeleteModal(id, name) {
    document.getElementById('delete_facility_id').value = id;
    document.getElementById('delete_facility_label').textContent = name;
    if(deleteModalObj) deleteModalObj.show();
}
</script>
</body>
</html>