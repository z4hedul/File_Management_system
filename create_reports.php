<?php
session_start();
include 'db.php';
include 'header.php';

if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

// Get statistics
$stats = [];

// Total files
$stmt = $conn->query("SELECT COUNT(*) as total FROM office_files WHERE is_deleted = 0");
$stats['total_files'] = $stmt->fetch_assoc()['total'];

// Total clients
$stmt = $conn->query("SELECT COUNT(*) as total FROM client_profiles");
$stats['total_clients'] = $stmt->fetch_assoc()['total'];

// Files by division
$div_stats = $conn->query("SELECT division, COUNT(*) as count FROM office_files WHERE is_deleted = 0 GROUP BY division");
$stats['by_division'] = [];
while ($row = $div_stats->fetch_assoc()) {
    $stats['by_division'][$row['division']] = $row['count'];
}

// Files by cabinet
$cab_stats = $conn->query("SELECT cabinet_name, COUNT(*) as count FROM office_files WHERE is_deleted = 0 AND cabinet_name IS NOT NULL GROUP BY cabinet_name ORDER BY count DESC LIMIT 10");
$stats['top_cabinets'] = [];
while ($row = $cab_stats->fetch_assoc()) {
    $stats['top_cabinets'][] = $row;
}

// Recent files
$recent = $conn->query("SELECT of.*, cp.client_name as client_profile_name 
                        FROM office_files of 
                        LEFT JOIN client_profiles cp ON of.client_id = cp.id 
                        WHERE of.is_deleted = 0 
                        ORDER BY of.created_at DESC LIMIT 10");
$stats['recent_files'] = $recent->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Statistics</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        .stat-card { background: #fff; border-radius: 12px; padding: 20px; text-align: center; border: 1px solid #e9ecef; transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .stat-card .number { font-size: 36px; font-weight: 700; color: #006a4e; }
        .stat-card .label { font-size: 14px; color: #6c757d; margin-top: 5px; }
        .stat-card .icon { font-size: 32px; color: #ffc72c; margin-bottom: 10px; }
    </style>
</head>
<body class="bg-light p-4">
    <div class="container" style="max-width: 1400px;">
        <h4 class="mb-4"><i class="fas fa-chart-bar text-primary"></i> Reports & Statistics</h4>
        
        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="icon"><i class="fas fa-folder-open"></i></div>
                    <div class="number"><?php echo number_format($stats['total_files']); ?></div>
                    <div class="label">Total Files</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="icon"><i class="fas fa-users"></i></div>
                    <div class="number"><?php echo number_format($stats['total_clients']); ?></div>
                    <div class="label">Total Clients</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="icon"><i class="fas fa-layer-group"></i></div>
                    <div class="number"><?php echo number_format(count($stats['by_division'])); ?></div>
                    <div class="label">Divisions</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="icon"><i class="fas fa-archive"></i></div>
                    <div class="number"><?php echo number_format(count($stats['top_cabinets'])); ?></div>
                    <div class="label">Active Cabinets</div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Files by Division -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-layer-group text-info"></i> Files by Division</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($stats['by_division'])): ?>
                            <?php foreach ($stats['by_division'] as $division => $count): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span><?php echo htmlspecialchars($division ?: 'Unassigned'); ?></span>
                                    <span class="badge bg-primary"><?php echo number_format($count); ?></span>
                                </div>
                                <div class="progress mb-3" style="height: 8px;">
                                    <div class="progress-bar" style="width: <?php echo ($count / $stats['total_files']) * 100; ?>%; background: linear-gradient(90deg, #006a4e, #00a86b);"></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted">No data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Top Cabinets -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-archive text-warning"></i> Top Cabinets</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($stats['top_cabinets'])): ?>
                            <?php foreach ($stats['top_cabinets'] as $cab): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span>Cabinet <?php echo htmlspecialchars($cab['cabinet_name']); ?></span>
                                    <span class="badge bg-secondary"><?php echo number_format($cab['count']); ?> files</span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted">No data available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Files -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-clock text-success"></i> Recent Files</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>File No</th>
                                        <th>Client</th>
                                        <th>Cabinet</th>
                                        <th>Division</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['recent_files'] as $file): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($file['file_no'] ?? 'N/A'); ?></strong></td>
                                            <td><?php echo htmlspecialchars($file['client'] ?? $file['client_profile_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($file['cabinet_name'] ?? 'N/A'); ?></td>
                                            <td><span class="badge bg-info"><?php echo htmlspecialchars($file['division'] ?? 'N/A'); ?></span></td>
                                            <td><?php echo date('d M Y', strtotime($file['created_at'] ?? 'now')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include 'footer.php'; ?>
</body>
</html>