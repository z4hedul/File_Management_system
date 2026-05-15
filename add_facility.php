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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Shared Data for this specific entry session
    $f_date = $_POST['sanction_date']; 
    $c_no   = $_POST['comm_meet_no'];
    $c_date = $_POST['comm_meet_date'];
    $b_no   = $_POST['board_meet_no'];
    $b_date = $_POST['board_meet_date'];

    if (isset($_POST['facility_types'])) {
        foreach ($_POST['facility_types'] as $key => $type) {
            if (!empty($type)) {
                $amt = $_POST['sanction_amounts'][$key];
                
                // Inserting facility + specific meeting info
                $sql = "INSERT INTO file_facilities 
                        (file_record_id, facility_type, amount, sanction_date, comm_meet_no, comm_meet_date, board_meet_no, board_meet_date) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $f_stmt = $conn->prepare($sql);
                $f_stmt->bind_param("isdsssss", $id, $type, $amt, $f_date, $c_no, $c_date, $b_no, $b_date);
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
                        <label class="form-label fw-bold">Sanction Date</label>
                        <input type="date" name="sanction_date" class="form-control border-primary" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Comm. Meet No & Date</label>
                        <input type="text" name="comm_meet_no" class="form-control mb-1" placeholder="Meet No">
                        <input type="date" name="comm_meet_date" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Board Meet No & Date</label>
                        <input type="text" name="board_meet_no" class="form-control mb-1" placeholder="Meet No">
                        <input type="date" name="board_meet_date" class="form-control">
                    </div>
                </div>
            </div>
        </div>

        <h6 class="fw-bold mb-3"><i class="fas fa-list"></i> Approved Facilities</h6>
        <div id="facility-container">
            <div class="row g-2 mb-3 facility-row border-bottom pb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Facility Type</label>
                    <input type="text" name="facility_types[]" class="form-control" placeholder="e.g. BG, LC, PIF etc" required>
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
    document.getElementById('add-more').onclick = function() {
        let container = document.getElementById('facility-container');
        let newRow = document.createElement('div');
        newRow.className = 'row g-2 mb-3 facility-row border-bottom pb-3';
        newRow.innerHTML = `
            <div class="col-md-6"><input type="text" name="facility_types[]" class="form-control" placeholder="Facility Type" required></div>
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