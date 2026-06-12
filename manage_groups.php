<?php
session_start();
include 'db.php';
include 'header.php';

// Security Check (Admin-only restriction)
if (!isset($_SESSION['loggedin'])) { header("location: login.php"); exit; }
if ($_SESSION['role'] !== 'admin') {
    echo "<script>alert('Access Denied'); window.location.href='index.php';</script>";
    exit;
}

$msg = "";
$msg_type = "";

// --- MODE SWITCHER CONTROLLER ---
$edit_mode = false;
$edit_id = 0;
$edit_group_name = "";
$edit_leader_id = 0;

if (isset($_GET['edit_id'])) {
    $edit_mode = true;
    $edit_id = intval($_GET['edit_id']);
    $fetch_edit_stmt = $conn->prepare("SELECT group_name, leader_id FROM user_groups WHERE id = ?");
    $fetch_edit_stmt->bind_param("i", $edit_id);
    $fetch_edit_stmt->execute();
    $edit_res = $fetch_edit_stmt->get_result()->fetch_assoc();
    if ($edit_res) {
        $edit_group_name = $edit_res['group_name'];
        $edit_leader_id = $edit_res['leader_id'];
    }
    $fetch_edit_stmt->close();
}

// --- HANDLE ACTION: DELETE GROUP ENTRY ---
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    $conn->begin_transaction();
    try {
        // 1. Decouple members assigned to this group so they become Independent/General Pool
        $decouple_stmt = $conn->prepare("UPDATE users SET group_id = NULL WHERE group_id = ?");
        $decouple_stmt->bind_param("i", $delete_id);
        $decouple_stmt->execute();
        $decouple_stmt->close();

        // 2. Clear out the primary foreign key entry mapping
        $delete_grp_stmt = $conn->prepare("DELETE FROM user_groups WHERE id = ?");
        $delete_grp_stmt->bind_param("i", $delete_id);
        $delete_grp_stmt->execute();
        $delete_grp_stmt->close();

        $conn->commit();
        $msg = "Operational group removed and structural references decoupled successfully.";
        $msg_type = "success";
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        $msg = "Error processing pipeline deletion constraint: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// --- HANDLE ACTION: UPDATE EXISTING GROUP ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_group'])) {
    $current_group_id = intval($_POST['group_id']);
    $group_name       = trim($_POST['group_name']);
    $leader_id        = intval($_POST['leader_id']);

    if (!empty($group_name) && $leader_id > 0) {
        $conn->begin_transaction();
        try {
            // Update core attributes 
            $upd_stmt = $conn->prepare("UPDATE user_groups SET group_name = ?, leader_id = ? WHERE id = ?");
            $upd_stmt->bind_param("sii", $group_name, $leader_id, $current_group_id);
            $upd_stmt->execute();
            $upd_stmt->close();

            // Enforce that this specific user belongs to the group they are leading
            $upd_user_stmt = $conn->prepare("UPDATE users SET group_id = ? WHERE id = ?");
            $upd_user_stmt->bind_param("ii", $current_group_id, $leader_id);
            $upd_user_stmt->execute();
            $upd_user_stmt->close();

            $conn->commit();
            echo "<script>alert('Group modified successfully.'); window.location.href='manage_groups.php';</script>";
            exit;
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $msg = "Database constraint violation during profile rewrite: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "Please verify all form parameters are supplied correctly.";
        $msg_type = "warning";
    }
}

// --- HANDLE ACTION: CREATE NEW GROUP ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_group'])) {
    $group_name = trim($_POST['group_name']);
    $leader_id  = intval($_POST['leader_id']);

    if (!empty($group_name) && $leader_id > 0) {
        $conn->begin_transaction();
        try {
            // Step A: Insert group definition record
            $stmt = $conn->prepare("INSERT INTO user_groups (group_name, leader_id) VALUES (?, ?)");
            $stmt->bind_param("si", $group_name, $leader_id);
            
            if ($stmt->execute()) {
                $new_group_id = $stmt->insert_id;
                
                // Step B: Set the group leader's profile group relation automatically
                $update_leader = $conn->prepare("UPDATE users SET group_id = ? WHERE id = ?");
                $update_leader->bind_param("ii", $new_group_id, $leader_id);
                $update_leader->execute();
                $update_leader->close();

                $conn->commit();
                $msg = "Operational cluster '<strong>".htmlspecialchars($group_name)."</strong>' initialized into system context.";
                $msg_type = "success";
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $msg = "Database runtime fault recorded: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "Failed execution. Operational group definitions must include a verified title name and structural management lead officer.";
        $msg_type = "warning";
    }
}

// --- CORE DISCOVERY WORKER QUERIES ---
// Pull only logical leaders/officers from the system table list
$eligible_leaders_res = $conn->query("
    SELECT id, full_name, username, designation, role 
    FROM users 
    WHERE role = 'admin' OR designation LIKE '%Manager%' OR designation LIKE '%Officer%' OR designation LIKE '%Head%'
    ORDER BY full_name ASC, username ASC
");

// Read entire defined organization structural matrix
$groups_res = $conn->query("
    SELECT 
        g.id, 
        g.group_name, 
        COALESCE(u.full_name, 'No Leader Assigned') AS leader_name, 
        COALESCE(u.designation, 'N/A') AS leader_title,
        COUNT(m.id) AS total_members
    FROM user_groups g
    LEFT JOIN users u ON g.leader_id = u.id
    LEFT JOIN users m ON m.group_id = g.id
    GROUP BY g.id
    ORDER BY g.group_name ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Operational Groups</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
</head>
<body class="bg-light">
<div class="container mt-5 pb-5">
    
    <?php if(!empty($msg)): ?>
        <div class="alert alert-<?php echo $msg_type; ?> alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas <?php echo ($msg_type === 'success') ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i>
            <?php echo $msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-4 col-lg-5">
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-header <?php echo $edit_mode ? 'bg-warning text-dark' : 'bg-dark text-white'; ?> py-3 fw-bold">
                    <i class="fas <?php echo $edit_mode ? 'fa-edit' : 'fa-folder-plus'; ?> me-2"></i>
                    <?php echo $edit_mode ? 'Modify Existing Group' : 'Initialize New Group Layer'; ?>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="manage_groups.php">
                        <?php if($edit_mode): ?>
                            <input type="hidden" name="group_id" value="<?php echo $edit_id; ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label small text-uppercase fw-bold text-muted">Group Structural Title</label>
                            <input type="text" name="group_name" class="form-control" value="<?php echo htmlspecialchars($edit_group_name); ?>" placeholder="e.g. Corporate Logistics / Operations Group A" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small text-uppercase fw-bold text-muted">Appoint Group Management Lead</label>
                            <select name="leader_id" class="form-select" required>
                                <option value="">-- Choose Eligible Lead Account --</option>
                                <?php if ($eligible_leaders_res && $eligible_leaders_res->num_rows > 0): ?>
                                    <?php while($officer = $eligible_leaders_res->fetch_assoc()): ?>
                                        <option value="<?php echo $officer['id']; ?>" <?php echo ($edit_leader_id == $officer['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($officer['full_name'] ?: $officer['username']); ?> 
                                            (<?php echo htmlspecialchars($officer['designation'] ?: 'Role: '.$officer['role']); ?>)
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                            <div class="form-text text-muted font-monospace" style="font-size:10px;">
                                * Filters system directory records matching administrative tier specifications.
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <?php if($edit_mode): ?>
                                <button type="submit" name="update_group" class="btn btn-warning fw-bold py-2 shadow-sm">
                                    <i class="fas fa-save me-1"></i> Update Group
                                </button>
                                <a href="manage_groups.php" class="btn btn-outline-secondary btn-sm text-center">Abort Changes</a>
                            <?php else: ?>
                                <button type="submit" name="create_group" class="btn btn-success fw-bold py-2 shadow-sm">
                                    <i class="fas fa-plus-circle me-1"></i> Register Team Group
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-8 col-lg-7">
            <div class="card shadow-sm border-0 rounded-3 bg-white">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-sitemap text-primary me-2"></i>Defined Group Infrastructure Roles</h5>
                    <a href="index.php" class="btn btn-sm btn-outline-dark px-3 fw-bold"><i class="fas fa-arrow-left me-1"></i> Dashboard</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 m-0">
                        <thead class="table-light text-uppercase small text-muted" style="font-size: 11px;">
                            <tr>
                                <th class="ps-3" style="width: 35%;">Group Infrastructure Name</th>
                                <th style="width: 30%;">Appointed Team Leader</th>
                                <th style="width: 15%;" class="text-center">Staff Members</th>
                                <th style="width: 20%;" class="text-center">Management Actions</th>
                            </tr>
                        </thead>
                        <tbody class="small">
                            <?php if ($groups_res && $groups_res->num_rows > 0): ?>
                                <?php while($group = $groups_res->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold ps-3 text-dark"><?php echo htmlspecialchars($group['group_name']); ?></td>
                                        <td>
                                            <div class="fw-semibold text-secondary"><i class="fas fa-user-shield text-warning opacity-75 me-1"></i><?php echo htmlspecialchars($group['leader_name']); ?></div>
                                            <div class="text-muted font-monospace" style="font-size:10px;"><?php echo htmlspecialchars($group['leader_title']); ?></div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary rounded-pill font-monospace px-2.5 py-1">
                                                <?php echo $group['total_members']; ?> users
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm shadow-sm" role="group">
                                                <a href="manage_groups.php?edit_id=<?php echo $group['id']; ?>" class="btn btn-outline-primary" title="Modify Details"><i class="fas fa-edit"></i></a>
                                                <a href="manage_groups.php?delete_id=<?php echo $group['id']; ?>" class="btn btn-outline-danger" title="Remove Record Group" onclick="return confirm('Are you sure you want to drop this group? Enrolled staff members will be automatically transitioned back into the general unassigned pool.');"><i class="fas fa-trash-alt"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center p-5 text-muted">
                                        <i class="fas fa-network-wired fa-2x mb-2 text-black-50 opacity-25 d-block"></i> No operating structural user groups defined yet.
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

<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include 'footer.php'; ?>
</body>
</html>