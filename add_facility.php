<?php
session_start();
include 'db.php';

if (!isset($_SESSION['loggedin'])) { header("location: login.php"); exit; }

$id = $_GET['id'] ?? null;
if (!$id) { header("location: index.php"); exit; }

$stmt = $conn->prepare("SELECT client, file_no FROM office_files WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$main_data = $stmt->get_result()->fetch_assoc();

$sanction_ref_prefix = 'FSIB/HO/INVT/';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Shared Data for this specific entry session
  // Shared Data for this specific entry session
    $f_date = $_POST['sanction_date'];
    $ref_suffix = trim($_POST['sanction_letter_ref_no_suffix'] ?? '');
    $s_ref  = $sanction_ref_prefix . $ref_suffix;

    // Use NULL if the field is empty so the database doesn't insert 0000-00-00
    $c_no   = !empty($_POST['comm_meet_no']) ? $_POST['comm_meet_no'] : null;
    $c_date = !empty($_POST['comm_meet_date']) ? $_POST['comm_meet_date'] : null;
    $b_no   = !empty($_POST['board_meet_no']) ? $_POST['board_meet_no'] : null;
    $b_date = !empty($_POST['board_meet_date']) ? $_POST['board_meet_date'] : null;

    if (isset($_POST['facility_types'])) {
        foreach ($_POST['facility_types'] as $key => $type) {
            // Check if either a valid type is selected OR "Others" is picked with a custom type filled out
            if (!empty($type)) {
                $amt = $_POST['sanction_amounts'][$key];
                
                // Read the matching hidden text input value using the unique array pointer index ($key)
                $custom_type = trim($_POST['facility_types_other'][$key] ?? '');
                
                // If "Others" option was chosen and the input isn't blank, overwrite $type with the custom text
                if ($type === 'Others' && !empty($custom_type)) {
                    $type = $custom_type;
                }
                
                $sql = "INSERT INTO file_facilities 
                        (file_record_id, facility_type, amount, sanction_date, sanction_letter_ref_no, comm_meet_no, comm_meet_date, board_meet_no, board_meet_date) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $f_stmt = $conn->prepare($sql);
                
                // Note: changed bind_param types to "isdssssss" 
                // and used variables that could be null
                $f_stmt->bind_param("isdssssss", $id, $type, $amt, $f_date, $s_ref, $c_no, $c_date, $b_no, $b_date);
                $f_stmt->execute();
            }
        }
        header("Location: more_details.php?id=$id&status=added");
        exit;
    }
}
?>
<style>
    .btn-hover-custom {
        transition: all 0.3s ease; /* Makes the transition smooth */
        border: none;
        border-radius: 5px;
    }

    .btn-hover-custom:hover {
        background-color: #ffca2c; /* A slightly brighter yellow */
        color: #000;
        transform: translateY(-2px); /* Lifts the button up slightly */
        box-shadow: 0 5px 15px rgba(255, 193, 7, 0.4); /* Adds a golden glow */
    }

    .ref-prefix-text,
    .input-group-text {
        background: #e7f1ff;
        border-color: #b8daff;
        color: #094b96;
        font-weight: 700;
    }

    .ref-prefix-text {
        display: inline-block;
        margin-bottom: 0.25rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.5rem;
    }

    .ref-suffix-input {
        min-width: 180px;
    }

    /* Optional: Pulse effect for the icon on hover */
    .btn-hover-custom:hover i {
        animation: pulse 1s infinite;
    }

    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.2); }
        100% { transform: scale(1); }
    }
</style>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Add Sanction & Meetings</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-5">

<div class="container bg-white p-4 shadow rounded" style="max-width: 950px;">
    <h4 class="text-primary mb-4"><i class="fas fa-file-signature"></i> Add New Sanction & Approval</h4>
    
    <div class="alert alert-secondary py-2">
        <strong>Client:</strong> <?php echo htmlspecialchars($main_data['client']); ?> | <strong>File:</strong> <?php echo htmlspecialchars($main_data['file_no']); ?>
    </div>

    <form method="POST">
        <div class="card mb-4 border-primary shadow-sm">
            <div class="card-header bg-primary text-white fw-bold small">APPROVAL & DATE DETAILS</div>
            <div class="card-body bg-light">
                <div class="row g-3">
                        <div class="col-md-4">
                        <label class="form-label fw-bold">Sanction Letter Ref No</label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo $sanction_ref_prefix; ?></span>
                            <input type="text" name="sanction_letter_ref_no_suffix" class="form-control border-primary ref-suffix-input" placeholder="Enter reference suffix" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Sanction Date</label>
                        <input type="date" name="sanction_date" class="form-control border-primary" required>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center">
                            <label class="form-label fw-bold mb-0">Comm. Meet No & Date</label>
                            <button type="button" id="comm-toggle" class="btn btn-sm btn-success ms-2" title="Enable/Disable Comm. Meeting"><i class="fas fa-plus"></i></button>
                        </div>
                        <div id="comm-meet-group" style="display:none;" class="mt-2">
                            <input type="text" id="comm_meet_no" name="comm_meet_no" class="form-control mb-1" placeholder="Meet No">
                            <input type="date" id="comm_meet_date" name="comm_meet_date" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center">
                            <label class="form-label fw-bold mb-0">Board Meet No & Date</label>
                            <button type="button" id="board-toggle" class="btn btn-sm btn-success ms-2" title="Enable/Disable Board Meeting"><i class="fas fa-plus"></i></button>
                        </div>
                        <div id="board-meet-group" style="display:none;" class="mt-2">
                            <input type="text" id="board_meet_no" name="board_meet_no" class="form-control mb-1" placeholder="Meet No">
                            <input type="date" id="board_meet_date" name="board_meet_date" class="form-control">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <h6 class="fw-bold mb-3"><i class="fas fa-list"></i> Approved Facilities</h6>
        <div id="facility-container">
            <div class="row g-2 mb-3 facility-row border-bottom pb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Facility Type</label>
                    <select name="facility_types[]" class="form-control facility-type-select" required>
                        <option value="">-- Select Facility Type --</option>
                        <option value="L/C (C2C)">L/C (C2C)</option>
                        <option value="L/C Limit">L/C Limit</option>
                        <option value="BG (C2C)">BG (C2C)</option>
                        <option value="BG (Limit)">BG (Limit)</option>
                        <option value="BG(PG)">BG(PG)</option>
                        <option value="BG(BB)">BG(BB)</option>
                        <option value="BM(Hypo)">BM(Hypo)</option>
                        <option value="BS(PSI)">BS(PSI)</option>
                        <option value="BM(PIF)">BM(PIF)</option>
                        <option value="Credit Card">Credit Card</option>
                        <option value="Others">Others</option>
                    </select>
                    <input type="text" name="facility_types_other[]" class="form-control facility-type-other mt-2" placeholder="Enter custom facility type" style="display:none;">
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-bold">Amount</label>
                    <input type="number" step="0.01" name="sanction_amounts[]" class="form-control" placeholder="0.00" required>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-success w-100" id="add-more"><i class="fas fa-plus"></i></button>
                </div>
            </div>
        </div>

        <div class="mt-4 pt-3 border-top">
            <button type="submit" class="btn btn-primary px-5 shadow">Save All Entry</button>
            <a href="more_details.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">Details</a>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
    <a href="edit_sanction.php?id=<?php echo $id; ?>" class="btn btn-warning btn-m btn-hover-custom shadow-sm fw-bold px-3">
        <i class="fas fa-pen-nib me-1"></i> Update Sanction/Meeting</a>
<?php endif; ?>
<a href="index.php" class="btn btn-secondary btn-m">Home</a>
        </div>
    </form>
</div>
<script>
    // Handle facility type selection change
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('facility-type-select')) {
            const otherInput = e.target.closest('.facility-row').querySelector('.facility-type-other');
            if (e.target.value === 'Others') {
                otherInput.style.display = 'block';
                otherInput.required = true;
            } else {
                otherInput.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
            }
        }
    });

    // Toggle for Comm. Meeting (top level)
    const commToggle = document.getElementById('comm-toggle');
    const commGroup = document.getElementById('comm-meet-group');
    commToggle && commToggle.addEventListener('click', function() {
        const isVisible = commGroup.style.display !== 'none';
        if (!isVisible) {
            commGroup.style.display = 'block';
            commToggle.classList.remove('btn-success');
            commToggle.classList.add('btn-danger');
            commToggle.innerHTML = '<i class="fas fa-minus"></i>';
        } else {
            commGroup.style.display = 'none';
            // clear values
            document.getElementById('comm_meet_no').value = '';
            document.getElementById('comm_meet_date').value = '';
            commToggle.classList.remove('btn-danger');
            commToggle.classList.add('btn-success');
            commToggle.innerHTML = '<i class="fas fa-plus"></i>';
        }
    });

    // Toggle for Board Meeting (top level)
    const boardToggle = document.getElementById('board-toggle');
    const boardGroup = document.getElementById('board-meet-group');
    boardToggle && boardToggle.addEventListener('click', function() {
        const isVisible = boardGroup.style.display !== 'none';
        if (!isVisible) {
            boardGroup.style.display = 'block';
            boardToggle.classList.remove('btn-success');
            boardToggle.classList.add('btn-danger');
            boardToggle.innerHTML = '<i class="fas fa-minus"></i>';
        } else {
            boardGroup.style.display = 'none';
            // clear values
            document.getElementById('board_meet_no').value = '';
            document.getElementById('board_meet_date').value = '';
            boardToggle.classList.remove('btn-danger');
            boardToggle.classList.add('btn-success');
            boardToggle.innerHTML = '<i class="fas fa-plus"></i>';
        }
    });

    
    document.getElementById('add-more').onclick = function() {
        let container = document.getElementById('facility-container');
        let newRow = document.createElement('div');
        newRow.className = 'row g-2 mb-3 facility-row border-bottom pb-3';
        newRow.innerHTML = `
            <div class="col-md-6">
                <select name="facility_types[]" class="form-control facility-type-select" required>
                    <option value="">-- Select Facility Type --</option>
                    <option value="L/C (C2C)">L/C (C2C)</option>
                    <option value="L/C Limit">L/C Limit</option>
                    <option value="BG (C2C)">BG (C2C)</option>
                    <option value="BG (Limit)">BG (Limit)</option>
                    <option value="BG(PG)">BG(PG)</option>
                    <option value="BG(BB)">BG(BB)</option>
                    <option value="BM(Hypo)">BM(Hypo)</option>
                    <option value="BS(PSI)">BS(PSI)</option>
                    <option value="BM(PIF)">BM(PIF)</option>
                    <option value="Credit Card">Credit Card</option>
                    <option value="Others">Others</option>
                </select>
                <input type="text" name="facility_types_other[]" class="form-control facility-type-other mt-2" placeholder="Enter custom facility type" style="display:none;">
            </div>
            <div class="col-md-5"><input type="number" step="0.01" name="sanction_amounts[]" class="form-control" placeholder="0.00" required></div>
            <div class="col-md-1"><button type="button" class="btn btn-danger w-100 remove-row"><i class="fas fa-minus"></i></button></div>`;
        container.appendChild(newRow);
    };

    document.addEventListener('click', function(e){
        if(e.target.closest('.remove-row')) {
            e.target.closest('.facility-row').remove();
        }
    });
</script>
</body>
</html>