<?php
session_start();
include 'db.php';


if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Fetch branches for dropdown with zone data
$branches = $conn->query("SELECT id, branch_code, branch_name, zone FROM branches ORDER BY branch_name");

// Generate unique client code
function generateClientCode($conn) {
    // Get the last client code
    $query = "SELECT client_code FROM client_profiles WHERE client_code LIKE 'C%' ORDER BY id DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $last_code = $result->fetch_assoc()['client_code'];
        // Extract the number from C001, C002, etc.
        $num = intval(substr($last_code, 1));
        $new_num = $num + 1;
    } else {
        $new_num = 1;
    }
    
    // Format as C001, C002, C003, etc.
    return 'C' . str_pad($new_num, 3, '0', STR_PAD_LEFT);
}

$auto_generated_code = generateClientCode($conn);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_name = trim($_POST['client_name']);
    $client_code = trim($_POST['client_code']);
    $address = trim($_POST['address']);
    $city = trim($_POST['city']);
    $state = trim($_POST['state']);
    $zip_code = trim($_POST['zip_code']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $contact_person = trim($_POST['contact_person']);
    $branch_id = $_POST['branch_id'] ? intval($_POST['branch_id']) : null;
    $division = trim($_POST['division']);
    $zone = trim($_POST['zone']);
    
    if (empty($client_name)) {
        $message = '<div class="alert alert-danger">Client name is required</div>';
    } else {
        // If client_code is empty, auto-generate one
        if (empty($client_code)) {
            $client_code = generateClientCode($conn);
        }
        
        $stmt = $conn->prepare("INSERT INTO client_profiles 
            (client_name, client_code, address, city, state, zip_code, phone, email, contact_person, branch_id, division, zone) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssssiss", 
            $client_name, $client_code, $address, $city, $state, $zip_code, 
            $phone, $email, $contact_person, $branch_id, $division, $zone
        );
        
        if ($stmt->execute()) {
            $new_id = $conn->insert_id;
            header("Location: client_profile.php?id=" . $new_id);
            exit();
        } else {
            $message = '<div class="alert alert-danger">Error: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
}
include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Client</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: #f0f2f5 !important; font-family: 'Segoe UI', system-ui, sans-serif; }
        .form-section {
            background: #f8f9fa;
            padding: 20px 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        .form-section h6 {
            color: #006a4e;
            font-weight: 600;
            margin-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 8px;
        }
        .form-section h6 i {
            margin-right: 8px;
        }
        .card-custom {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .card-custom .card-header {
            background: linear-gradient(135deg, #006a4e 0%, #004d3a 100%);
            color: #fff;
            padding: 18px 24px;
            font-weight: 600;
        }
        .card-custom .card-header i {
            color: #ffc72c;
            margin-right: 10px;
        }
        .btn-save {
            background: #006a4e;
            color: #fff;
            border: none;
            padding: 10px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-save:hover {
            background: #004d3a;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,106,78,0.3);
            color: #fff;
        }
        .btn-cancel {
            background: #6c757d;
            color: #fff;
            border: none;
            padding: 10px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-cancel:hover {
            background: #5a6268;
            color: #fff;
        }
        .auto-generated {
            background: #e8f5e9;
            color: #2e7d32;
            font-size: 0.75rem;
            padding: 2px 10px;
            border-radius: 12px;
            display: inline-block;
            margin-left: 8px;
        }
        .zone-auto-fill {
            background: #fff3e0;
            border-color: #ffb74d;
        }
        .zone-auto-fill:focus {
            border-color: #ff9800;
            box-shadow: 0 0 0 0.2rem rgba(255, 152, 0, 0.25);
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="card card-custom">
            <div class="card-header">
                <i class="fas fa-user-plus"></i> Add New Client
                <span class="badge bg-light text-dark ms-2">Auto-generates unique code</span>
            </div>
            <div class="card-body p-4">
                <?php echo $message; ?>
                
                <form method="POST">
                    <div class="form-section">
                        <h6><i class="fas fa-info-circle text-primary"></i> Basic Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Client Name <span class="text-danger">*</span></label>
                                    <input type="text" name="client_name" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Client Code</label>
                                    <div class="input-group">
                                        <input type="text" name="client_code" class="form-control" value="<?php echo $auto_generated_code; ?>" placeholder="Auto-generated">
                                        <span class="input-group-text bg-light">
                                            <i class="fas fa-sync-alt text-muted" title="Auto-generated"></i>
                                        </span>
                                    </div>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i> Leave blank to auto-generate or customize
                                    </small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Address</label>
                                    <textarea name="address" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">City</label>
                                    <input type="text" name="city" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">State</label>
                                    <input type="text" name="state" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Zip Code</label>
                                    <input type="text" name="zip_code" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h6><i class="fas fa-phone text-success"></i> Contact Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Phone</label>
                                    <input type="tel" name="phone" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Contact Person</label>
                                    <input type="text" name="contact_person" class="form-control" placeholder="Main point of contact">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h6><i class="fas fa-building text-warning"></i> Branch & Location</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Branch</label>
                                    <select name="branch_id" id="branch_select" class="form-control" onchange="autoPopulateZone(this)">
                                        <option value="">-- Select Branch --</option>
                                        <?php 
                                        // Reset the branches pointer for display
                                        $branches->data_seek(0);
                                        while ($branch = $branches->fetch_assoc()): 
                                        ?>
                                            <option value="<?php echo $branch['id']; ?>" data-zone="<?php echo htmlspecialchars(trim($branch['zone'])); ?>">
                                                <?php echo htmlspecialchars($branch['branch_name']); ?> (<?php echo htmlspecialchars($branch['branch_code']); ?>)
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Division</label>
                                    <input type="text" name="division" class="form-control" placeholder="e.g., Investment, SME, IMRD">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="mb-3">
                                    <label class="form-label">Zone</label>
                                    <input type="text" name="zone" id="zone_input" class="form-control zone-auto-fill" placeholder="Auto-filled from branch selection" readonly style="cursor: default;">
                                    <small class="text-muted">
                                        <i class="fas fa-magic text-warning"></i> Zone will be auto-filled when you select a branch
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn-save">
                            <i class="fas fa-save"></i> Save Client
                        </button>
                        <a href="client_profile.php" class="btn-cancel">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="assets/js/jquery-3.6.0.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    
    <script>
    /**
     * Auto-populate Zone from Branch selection
     * This works exactly like the add_record.php implementation
     */
    function autoPopulateZone(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const zoneInput = document.getElementById('zone_input');
        
        if (selectedOption && selectedOption.value !== "") {
            const zone = selectedOption.getAttribute('data-zone') || "";
            zoneInput.value = zone.trim();
            
            // Add visual feedback
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
    
    // Auto-generate client code if user leaves it blank
    document.querySelector('form').addEventListener('submit', function(e) {
        const codeInput = document.querySelector('input[name="client_code"]');
        if (codeInput && codeInput.value.trim() === '') {
            // The server will generate it, but we can show a message
            console.log('Client code will be auto-generated on server');
        }
    });
    
    // Visual feedback when branch changes
    document.addEventListener('DOMContentLoaded', function() {
        const branchSelect = document.getElementById('branch_select');
        if (branchSelect) {
            // If there's a default selected value, trigger zone fill
            if (branchSelect.value !== '') {
                autoPopulateZone(branchSelect);
            }
        }
    });
    </script>
    
    <?php include 'footer.php'; ?>
</body>
</html>