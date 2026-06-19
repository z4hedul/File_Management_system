<?php 
session_start();
include 'db.php'; 
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header("Location: login.php");
    exit;
}

$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Fetch branches for dropdown
$branches_list = [];
$branches_query = "SELECT id, branch_code, branch_name, zone FROM branches ORDER BY branch_name ASC";
$branches_result = $conn->query($branches_query);
if ($branches_result && $branches_result->num_rows > 0) {
    while ($row = $branches_result->fetch_assoc()) {
        $branches_list[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Management System</title>
    
    <!-- Use correct paths from header.php -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    
    <style>
        .cabinet-badge { font-size: 0.9rem; font-weight: 700; padding: 6px 12px; border-radius: 6px; }
        .shelf-badge { font-size: 0.85rem; font-weight: 600; padding: 4px 10px; }
        .assign-file-btn {
            background: #ffc72c !important;
            color: #006a4e !important;
            border: none !important;
            padding: 4px 10px !important;
            border-radius: 4px !important;
            font-size: 0.7rem !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
        }
        .assign-file-btn:hover {
            background: #ffd95e !important;
            color: #004d3a !important;
        }
        .assign-file-btn i {
            margin-right: 2px;
        }
        .modal-custom-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .modal-custom-overlay.show {
            display: flex;
        }
        .modal-custom-content {
            background: #fff;
            border-radius: 12px;
            max-width: 700px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .modal-custom-header {
            padding: 18px 24px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-custom-header h5 {
            margin: 0;
            font-weight: 600;
        }
        .modal-custom-body {
            padding: 24px;
        }
        .modal-custom-footer {
            padding: 16px 24px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .modal-custom-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6c757d;
        }
        .modal-custom-close:hover {
            color: #000;
        }
        .add-client-link {
            color: #006a4e;
            font-weight: 600;
            cursor: pointer;
            text-decoration: underline;
            transition: all 0.2s ease;
        }
        .add-client-link:hover {
            color: #ffc72c;
        }
        .client-select-wrapper {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .client-select-wrapper select {
            flex: 1;
        }
        .modal-sm-custom .modal-custom-content {
            max-width: 600px;
        }
        .form-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }
        .form-section h6 {
            color: #006a4e;
            font-weight: 600;
            margin-bottom: 12px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 8px;
        }
        .form-section h6 i {
            margin-right: 8px;
        }
        .zone-auto-fill {
            background-color: #f8f9fa;
        }
        .zone-auto-fill:focus {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>

<div class="container main-container pb-5" style="max-width: 1600px;">
    <div class="card table-card shadow-sm border-0 bg-white rounded-3">
        <div class="card-header bg-white py-3 border-bottom-0 shadow-sm rounded-top-3">
            <div class="d-flex justify-content-between align-items-center w-100 px-2">
                <div class="d-flex align-items-center">
                    <div class="icon-shape bg-primary-subtle text-primary rounded-3 me-3 d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                        <i class="fas fa-folder-open fs-5"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold text-dark">Master File Records</h5>
                        <small class="text-muted d-none d-sm-block">Manage and track active office files</small>
                    </div>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <a href="add_record.php" class="btn btn-primary shadow-sm rounded-3 px-3 py-2 fw-semibold d-flex align-items-center">
                        <i class="fas fa-plus-circle me-2 fs-6"></i> New File Record
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary rounded-3 px-3 py-2 fw-medium d-flex align-items-center">
                        <i class="fas fa-home me-2"></i> Dashboard
                    </a>
                </div>
            </div>
        </div>
        
        <div class="card-body p-3">
            <div class="table-responsive">
                <table id="filesTable" class="table table-striped table-hover align-middle m-0 w-100">
                    <thead class="table-dark small text-uppercase">
                        <tr>
                            <th style="width: 22%;">Client Name</th>
                            <th style="width: 20%;">Branch / Domain Region</th>
                            <th class="text-center" style="width: 10%;">Division</th>
                            <th class="text-center" style="width: 10%;">Cabinet</th>
                            <th class="text-center" style="width: 10%;">Shelf</th>
                            <th class="text-center" style="width: 8%;">File No.</th>
                            <th class="text-center" style="width: 10%;">Last Sanction Date</th>
                            <th>Remarks</th>
                            <th class="text-center" style="width: 15%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Assign File Modal -->
<div class="modal-custom-overlay" id="assignFileModal">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h5><i class="fas fa-link text-warning"></i> Assign File to Client</h5>
            <button type="button" class="modal-custom-close" onclick="closeAssignModal()">&times;</button>
        </div>
        <div class="modal-custom-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> 
                Select a client to assign this file to. The file will be linked to the selected client.
                <br><small class="text-muted">Note: The original file name will be preserved.</small>
            </div>
            
            <form id="assignFileForm">
                <input type="hidden" id="assign_file_id" value="">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">File Name</label>
                            <input type="text" id="assign_file_name" class="form-control" readonly style="background: #f8f9fa; font-weight: 600; color: #006a4e;">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label fw-bold">File No</label>
                            <input type="text" id="assign_file_no" class="form-control" readonly style="background: #f8f9fa; font-weight: 600; color: #006a4e;">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Select Client <span class="text-danger">*</span></label>
                    <div class="client-select-wrapper">
                        <select name="client_id" id="client_select" class="form-control" required>
                            <option value="">-- Select Client --</option>
                            <?php
                            $clients_query = "SELECT id, client_name, client_code FROM client_profiles ORDER BY client_name ASC";
                            $clients_result = $conn->query($clients_query);
                            if ($clients_result && $clients_result->num_rows > 0):
                                while ($client = $clients_result->fetch_assoc()):
                            ?>
                                <option value="<?php echo $client['id']; ?>">
                                    <?php echo htmlspecialchars($client['client_name']); ?>
                                    <?php if (!empty($client['client_code'])): ?>
                                        (<?php echo htmlspecialchars($client['client_code']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endwhile; ?>
                            <?php else: ?>
                                <option value="">No clients found</option>
                            <?php endif; ?>
                        </select>
                        <button type="button" class="btn btn-success btn-sm" onclick="openAddClientModal()" title="Add New Client">
                            <i class="fas fa-plus"></i> New
                        </button>
                    </div>
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i> 
                        <span class="add-client-link" onclick="openAddClientModal()">Click here to add a new client</span>
                    </small>
                </div>
            </form>
        </div>
        <div class="modal-custom-footer">
            <button type="button" class="btn btn-secondary" onclick="closeAssignModal()">Close</button>
            <button type="button" class="btn btn-primary" id="assignFileBtn">
                <i class="fas fa-link"></i> Assign File to Client
            </button>
        </div>
    </div>
</div>

<!-- Add Client Modal -->
<div class="modal-custom-overlay" id="addClientModal">
    <div class="modal-custom-content modal-sm-custom">
        <div class="modal-custom-header">
            <h5><i class="fas fa-user-plus text-success"></i> Add New Client</h5>
            <button type="button" class="modal-custom-close" onclick="closeAddClientModal()">&times;</button>
        </div>
        <div class="modal-custom-body">
            <div id="addClientMessage"></div>
            <form id="addClientForm">
                <div class="form-section">
                    <h6><i class="fas fa-info-circle text-primary"></i> Basic Information</h6>
                    <div class="mb-3">
                        <label class="form-label">Client Name <span class="text-danger">*</span></label>
                        <input type="text" id="new_client_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Client Code</label>
                        <input type="text" id="new_client_code" class="form-control" placeholder="Auto-generated if left blank">
                        <small class="text-muted">Leave blank to auto-generate (C001, C002, etc.)</small>
                    </div>
                </div>
                
                <div class="form-section">
                    <h6><i class="fas fa-phone text-success"></i> Contact Information</h6>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="tel" id="new_client_phone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" id="new_client_email" class="form-control">
                    </div>
                </div>
                
                <div class="form-section">
                    <h6><i class="fas fa-building text-warning"></i> Branch & Location</h6>
                    <div class="mb-3">
                        <label class="form-label">Branch</label>
                        <select id="new_client_branch" class="form-control" onchange="autoPopulateZone(this)">
                            <option value="">-- Select Branch --</option>
                            <?php foreach ($branches_list as $branch): ?>
                                <option value="<?php echo $branch['id']; ?>" data-zone="<?php echo htmlspecialchars(trim($branch['zone'])); ?>">
                                    <?php echo htmlspecialchars($branch['branch_name']); ?> (<?php echo htmlspecialchars($branch['branch_code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="mt-1">
                            <a href="add_branch.php" target="_blank" class="text-decoration-none small text-primary fw-semibold">
                                <i class="fas fa-plus-circle me-1"></i>Add branch manually
                            </a>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Division</label>
                                <select id="new_client_division" class="form-control">
                                    <option value="Investment">Investment</option>
                                    <option value="SME">SME</option>
                                    <option value="IMRD">IMRD</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Zone</label>
                                <input type="text" id="new_client_zone" class="form-control zone-auto-fill" placeholder="Auto-filled from branch" readonly>
                                <small class="text-muted">
                                    <i class="fas fa-magic text-warning"></i> Zone will be auto-filled when you select a branch
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-custom-footer">
            <button type="button" class="btn btn-secondary" onclick="closeAddClientModal()">Cancel</button>
            <button type="button" class="btn btn-success" id="saveClientBtn">
                <i class="fas fa-save"></i> Save Client
            </button>
        </div>
    </div>
</div>

<!-- Use CDN for jQuery and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/jquery.dataTables.min.js"></script>
<script src="assets/js/dataTables.bootstrap5.min.js"></script>

<script>
// ============================================================
// AUTO-POPULATE ZONE FROM BRANCH
// ============================================================
function autoPopulateZone(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const zoneInput = document.getElementById('new_client_zone');
    
    if (selectedOption && selectedOption.value !== "") {
        const zone = selectedOption.getAttribute('data-zone') || "";
        zoneInput.value = zone.trim();
        
        if (zone.trim() !== "") {
            zoneInput.style.borderColor = '#28a745';
            zoneInput.style.backgroundColor = '#f0fff4';
        } else {
            zoneInput.style.borderColor = '#ffc107';
            zoneInput.style.backgroundColor = '#fff3e0';
        }
    } else {
        zoneInput.value = "";
        zoneInput.style.borderColor = '';
        zoneInput.style.backgroundColor = '';
    }
}

// ============================================================
// ASSIGN FILE MODAL FUNCTIONS
// ============================================================

function openAssignModal(fileId, fileName, fileNo) {
    try {
        document.getElementById('assign_file_id').value = fileId;
        document.getElementById('assign_file_name').value = fileName || 'N/A';
        document.getElementById('assign_file_no').value = fileNo || 'N/A';
        document.getElementById('client_select').value = '';
        document.getElementById('assignFileModal').classList.add('show');
        document.body.style.overflow = 'hidden';
    } catch(e) {
        console.error('Error opening modal:', e);
        alert('Error opening modal: ' + e.message);
    }
}

function closeAssignModal() {
    try {
        document.getElementById('assignFileModal').classList.remove('show');
        document.body.style.overflow = '';
    } catch(e) {
        console.error('Error closing modal:', e);
    }
}

// ============================================================
// ADD CLIENT MODAL FUNCTIONS
// ============================================================

function openAddClientModal() {
    try {
        document.getElementById('new_client_name').value = '';
        document.getElementById('new_client_code').value = '';
        document.getElementById('new_client_phone').value = '';
        document.getElementById('new_client_email').value = '';
        document.getElementById('new_client_branch').value = '';
        document.getElementById('new_client_division').value = 'Investment';
        document.getElementById('new_client_zone').value = '';
        document.getElementById('new_client_zone').style.borderColor = '';
        document.getElementById('new_client_zone').style.backgroundColor = '';
        document.getElementById('addClientMessage').innerHTML = '';
        
        document.getElementById('addClientModal').classList.add('show');
        document.body.style.overflow = 'hidden';
    } catch(e) {
        console.error('Error opening add client modal:', e);
        alert('Error opening add client modal: ' + e.message);
    }
}

function closeAddClientModal() {
    try {
        document.getElementById('addClientModal').classList.remove('show');
        document.body.style.overflow = '';
    } catch(e) {
        console.error('Error closing add client modal:', e);
    }
}

// ============================================================
// REFRESH CLIENT DROPDOWN
// ============================================================
function refreshClientDropdown() {
    console.log('Refreshing client dropdown...');
    
    $.ajax({
        url: 'api/clients.php',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            console.log('Dropdown refresh response:', response);
            
            if (response.success && response.data) {
                const select = document.getElementById('client_select');
                
                // Clear existing options
                select.innerHTML = '';
                
                // Add default option
                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.textContent = '-- Select Client --';
                select.appendChild(defaultOption);
                
                // Add all clients
                response.data.forEach(function(client) {
                    const option = document.createElement('option');
                    option.value = client.id;
                    let label = client.client_name;
                    if (client.client_code) {
                        label += ' (' + client.client_code + ')';
                    }
                    option.textContent = label;
                    select.appendChild(option);
                });
                
                console.log('Dropdown refreshed with ' + response.data.length + ' clients');
            } else {
                console.error('Invalid response format:', response);
            }
        },
        error: function(xhr) {
            console.error('Error refreshing dropdown:', xhr);
        }
    });
}

// ============================================================
// SAVE NEW CLIENT
// ============================================================
function saveNewClient() {
    const clientName = document.getElementById('new_client_name').value.trim();
    const clientCode = document.getElementById('new_client_code').value.trim();
    const phone = document.getElementById('new_client_phone').value.trim();
    const email = document.getElementById('new_client_email').value.trim();
    const branchId = document.getElementById('new_client_branch').value;
    const division = document.getElementById('new_client_division').value;
    const zone = document.getElementById('new_client_zone').value.trim();
    
    if (!clientName) {
        document.getElementById('addClientMessage').innerHTML = 
            '<div class="alert alert-danger">Client name is required.</div>';
        return;
    }
    
    const btn = document.getElementById('saveClientBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;
    
    const data = {
        client_name: clientName,
        client_code: clientCode || null,
        phone: phone || null,
        email: email || null,
        branch_id: branchId || null,
        division: division || 'Investment',
        zone: zone || null
    };
    
    console.log('Saving client:', data);
    
    $.ajax({
        url: 'api/clients.php',
        method: 'POST',
        data: JSON.stringify(data),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            btn.innerHTML = '<i class="fas fa-save"></i> Save Client';
            btn.disabled = false;
            
            console.log('Save response:', response);
            
            if (response.success) {
                document.getElementById('addClientMessage').innerHTML = 
                    '<div class="alert alert-success">✅ Client added successfully! Code: ' + (response.client_code || '') + '</div>';
                
                // Refresh the dropdown
                refreshClientDropdown();
                
                // Close modal after delay
                setTimeout(function() {
                    closeAddClientModal();
                }, 1500);
            } else {
                document.getElementById('addClientMessage').innerHTML = 
                    '<div class="alert alert-danger">❌ Error: ' + (response.error || 'Unknown error') + '</div>';
            }
        },
        error: function(xhr) {
            btn.innerHTML = '<i class="fas fa-save"></i> Save Client';
            btn.disabled = false;
            
            let errorMsg = 'An error occurred';
            try {
                const response = JSON.parse(xhr.responseText);
                if (response && response.error) {
                    errorMsg = response.error;
                }
            } catch(e) {
                errorMsg = xhr.status + ': ' + xhr.statusText;
            }
            document.getElementById('addClientMessage').innerHTML = 
                '<div class="alert alert-danger">❌ Error: ' + errorMsg + '</div>';
        }
    });
}

// ============================================================
// EVENT LISTENERS
// ============================================================

document.addEventListener('DOMContentLoaded', function() {
    // Close modals when clicking outside
    document.getElementById('assignFileModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAssignModal();
        }
    });
    
    document.getElementById('addClientModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAddClientModal();
        }
    });
    
    // Save client button
    document.getElementById('saveClientBtn').addEventListener('click', saveNewClient);
    
    // Enter key support
    document.getElementById('addClientForm').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveNewClient();
        }
    });
});

$(document).ready(function() {
    // Initialize DataTable
    var table = $('#filesTable').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            "url": "fetch_data.php",
            "type": "POST"
        },
        "pageLength": 10,
        "responsive": true,
        "order": [], 
        "columns": [
            { "data": "client" },
            { "data": "branch_code" },
            { "data": "division", "className": "text-center" },
            { "data": "cabinet_name", "className": "text-center" },
            { "data": "shelf_name", "className": "text-center" },
            { "data": "file_no", "className": "text-center font-monospace fw-bold text-primary" },
            { "data": "last_sanction_date", "className": "text-center" },
            { "data": "remarks" },
            { "data": "actions", "orderable": false, "className": "text-center" }
        ],
        "language": {
            "search": "Search File:",
            "lengthMenu": "Show _MENU_"
        },
        "drawCallback": function() {
            $('.assign-file-btn').off('click').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const fileId = $(this).data('file-id');
                const fileName = $(this).data('file-name');
                const fileNo = $(this).data('file-no');
                
                openAssignModal(fileId, fileName, fileNo);
            });
        }
    });
    
    // Assign file button
    $('#assignFileBtn').on('click', function() {
        const fileId = $('#assign_file_id').val();
        const clientId = $('#client_select').val();
        
        if (!fileId) {
            alert('File ID not found.');
            return;
        }
        
        if (!clientId) {
            alert('Please select a client.');
            return;
        }
        
        if (!confirm('Are you sure you want to assign this file to the selected client?')) {
            return;
        }
        
        const btn = $(this);
        btn.html('<i class="fas fa-spinner fa-spin"></i> Assigning...').prop('disabled', true);
        
        $.ajax({
            url: 'api/assign_file.php',
            method: 'POST',
            data: JSON.stringify({
                file_id: parseInt(fileId),
                client_id: parseInt(clientId)
            }),
            contentType: 'application/json',
            dataType: 'json',
            success: function(response) {
                btn.html('<i class="fas fa-link"></i> Assign File to Client').prop('disabled', false);
                
                if (response.success) {
                    closeAssignModal();
                    alert('✅ File assigned successfully!');
                    $('#filesTable').DataTable().ajax.reload();
                } else {
                    alert('❌ Error: ' + (response.error || 'Unknown error'));
                }
            },
            error: function(xhr) {
                btn.html('<i class="fas fa-link"></i> Assign File to Client').prop('disabled', false);
                alert('❌ An error occurred. Please try again.');
                console.error('Error:', xhr.responseText);
            }
        });
    });
});
</script>

<?php include 'footer.php'; ?>
</body>
</html>