<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_type = isset($_GET['search_type']) ? $_GET['search_type'] : 'all';

$results = [];
if (!empty($search_term)) {
    $search_term = '%' . $search_term . '%';
    
    $query = "SELECT 
                of.*,
                cp.client_name as client_profile_name
              FROM office_files of
              LEFT JOIN client_profiles cp ON of.client_id = cp.id
              WHERE of.is_deleted = 0";
    
    $conditions = [];
    $params = [];
    $types = "";
    
    if ($search_type == 'all' || $search_type == 'client') {
        $conditions[] = "of.client LIKE ?";
        $params[] = $search_term;
        $types .= "s";
    }
    if ($search_type == 'all' || $search_type == 'file_no') {
        $conditions[] = "of.file_no LIKE ?";
        $params[] = $search_term;
        $types .= "s";
    }
    if ($search_type == 'all' || $search_type == 'cabinet') {
        $conditions[] = "of.cabinet_name LIKE ?";
        $params[] = $search_term;
        $types .= "s";
    }
    if ($search_type == 'all' || $search_type == 'branch') {
        $conditions[] = "of.branch_name LIKE ?";
        $params[] = $search_term;
        $types .= "s";
    }
    
    if (!empty($conditions)) {
        $query .= " AND (" . implode(" OR ", $conditions) . ")";
        $query .= " ORDER BY of.file_no ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Files</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        .search-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        .result-item { border-left: 4px solid #006a4e; padding: 15px; margin-bottom: 10px; background: #f8f9fa; border-radius: 8px; }
        .result-item:hover { background: #e8f5e9; }
    </style>
</head>
<body class="bg-light p-4">
    <div class="container" style="max-width: 1200px;">
        <div class="search-card p-4 mb-4">
            <h4><i class="fas fa-search text-primary"></i> Search Files</h4>
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <input type="text" name="search" class="form-control form-control-lg" 
                           placeholder="Search by client, file no, cabinet, branch..." 
                           value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <select name="search_type" class="form-select form-select-lg">
                        <option value="all" <?php echo ($search_type == 'all') ? 'selected' : ''; ?>>All Fields</option>
                        <option value="client" <?php echo ($search_type == 'client') ? 'selected' : ''; ?>>Client Name</option>
                        <option value="file_no" <?php echo ($search_type == 'file_no') ? 'selected' : ''; ?>>File No</option>
                        <option value="cabinet" <?php echo ($search_type == 'cabinet') ? 'selected' : ''; ?>>Cabinet</option>
                        <option value="branch" <?php echo ($search_type == 'branch') ? 'selected' : ''; ?>>Branch</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fas fa-search"></i> Search</button>
                </div>
            </form>
        </div>

        <?php if (!empty($search_term)): ?>
            <div class="card">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Results: <?php echo count($results); ?> files found</h5>
                </div>
                <div class="card-body">
                    <?php if (count($results) > 0): ?>
                        <?php foreach ($results as $file): ?>
                            <div class="result-item">
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <strong><i class="fas fa-file text-primary"></i> <?php echo htmlspecialchars($file['file_no'] ?? 'N/A'); ?></strong>
                                        <div class="text-muted small"><?php echo htmlspecialchars($file['client'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <i class="fas fa-archive text-warning"></i> <?php echo htmlspecialchars($file['cabinet_name'] ?? 'N/A'); ?>
                                        <div class="text-muted small">Shelf: <?php echo htmlspecialchars($file['shelf_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-md-3">
                                        <span class="badge bg-info"><?php echo htmlspecialchars($file['division'] ?? 'N/A'); ?></span>
                                        <div class="text-muted small"><?php echo htmlspecialchars($file['branch_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div class="col-md-2 text-end">
                                        <a href="view_details.php?id=<?php echo $file['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-search" style="font-size: 48px;"></i>
                            <p class="mt-3">No files found matching your search.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php include 'footer.php'; ?>
</body>
</html>