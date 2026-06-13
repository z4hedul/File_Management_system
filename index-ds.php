<?php
session_start();
include 'db.php';
include 'header.php';
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Verify authentication state boundary
if (!isset($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

/**
 * FETCH FULL NAME DYNAMICALLY
 */
$display_name = $_SESSION['username'] ?? 'User';
if (isset($_SESSION['username'])) {
    $user_stmt = $conn->prepare("SELECT full_name FROM users WHERE username = ?");
    $user_stmt->bind_param("s", $_SESSION['username']);
    $user_stmt->execute();
    $user_res = $user_stmt->get_result()->fetch_assoc();
    
    if (!empty($user_res['full_name'])) {
        $display_name = $user_res['full_name'];
        $_SESSION['full_name'] = $user_res['full_name'];
    }
    $user_stmt->close();
}

// EXTRACT DATE FILTERS FOR PIPELINE TRACKING SYSTEM
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// Establish date filtering conditions based on sanction_date
$date_condition = ""; 
if (!empty($from_date) && !empty($to_date)) {
    $safe_from = $conn->real_escape_string($from_date);
    $safe_to = $conn->real_escape_string($to_date);
    $date_condition = " AND ff.sanction_date BETWEEN '{$safe_from} 00:00:00' AND '{$safe_to} 23:59:59' ";
}

// Get the active filtered status from the URL click action
$active_filter = $_GET['status_view'] ?? '';
$show_queue = isset($_GET['show_queue']) && $_GET['show_queue'] == '1'; // New parameter to control queue visibility

// 1. Total Proposals Assigned (Unique files tracked)
$q_assigned = "SELECT COUNT(DISTINCT pa.proposal_ref) AS total FROM proposal_assignments pa
               JOIN office_files o ON pa.file_id = o.id
               WHERE o.is_deleted = 0";
$res_assigned = $conn->query($q_assigned);
$total_assigned = $res_assigned ? $res_assigned->fetch_assoc()['total'] : 0;

// 2. Fetch aggregate status pipelines totals matrix block
$stages = [
    'proposal_received' => "Proposal Received",
    'pending'           => "Pending",
    'in_prep'           => "Proposal In Preparation",
    'office_note'       => "Office Note",
    'committee_memo'    => "Committee Memo",
    'committee_minutes' => "Committee Minutes",
    'board_memo'        => "Board Memo",
    'board_minutes'     => "Board Minutes",
    'declined'          => "Declined",
    'approved'          => "Approval/Sanction",
];

$counts = [];
foreach ($stages as $key => $status_value) {
    if ($key === 'approved') {
        // FIX #3: For approved, only count those within last 15 days when no custom date filter
        if (!empty($date_condition)) {
            $sql = "SELECT COUNT(DISTINCT pa.proposal_ref) AS total 
                    FROM proposal_assignments pa 
                    JOIN office_files o ON pa.file_id = o.id 
                    LEFT JOIN file_facilities ff ON o.id = ff.file_record_id
                    WHERE pa.proposal_status = ? AND o.is_deleted = 0 {$date_condition}";
            $stmt = $conn->prepare($sql);
        } else {
            $sql = "SELECT COUNT(DISTINCT pa.proposal_ref) AS total 
                    FROM proposal_assignments pa 
                    JOIN office_files o ON pa.file_id = o.id 
                    INNER JOIN file_facilities ff ON o.id = ff.file_record_id
                    WHERE pa.proposal_status = ? AND o.is_deleted = 0 
                    AND ff.sanction_date >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)"; // Changed from 1 month to 15 days
            $stmt = $conn->prepare($sql);
        }
    } else {
        $sql = "SELECT COUNT(DISTINCT pa.proposal_ref) AS total 
                FROM proposal_assignments pa 
                JOIN office_files o ON pa.file_id = o.id 
                WHERE pa.proposal_status = ? AND o.is_deleted = 0";
        $stmt = $conn->prepare($sql);
    }
    
    $stmt->bind_param('s', $status_value);
    $stmt->execute();
    $counts[$key] = intval($stmt->get_result()->fetch_assoc()['total']);
    $stmt->close(); 
}

$total_processing = $counts['proposal_received'] + $counts['in_prep'] + $counts['office_note'] + $counts['committee_memo'] + $counts['committee_minutes'] + $counts['board_memo'] + $counts['board_minutes'];

// FIX #1: Only fetch matching proposals when show_queue is true
$matching_proposals = [];
if ($show_queue && !empty($active_filter)) {
    $list_sql = "SELECT 
                    pa.id,
                    pa.proposal_ref,
                    pa.proposal_status,
                    pa.remarks,
                    pa.assigned_date AS assigned_time,
                    o.client AS client_name,
                    o.file_no AS file_no,
                    o.branch_name AS branch_name,
                    o.division,
                    o.id AS file_rec_id,
                    u.full_name AS officer_name,
                    pa.proposal_type,
                    pa.proposal_amount
                 FROM proposal_assignments pa
                 JOIN office_files o ON pa.file_id = o.id
                 LEFT JOIN users u ON pa.user_id = u.id
                 WHERE o.is_deleted = 0
                 AND pa.proposal_status = ?";
    
    // For approved status, filter by sanction_date (older than 15 days excluded when no custom date)
    if ($active_filter === 'Approval/Sanction' && empty($from_date) && empty($to_date)) {
        $list_sql .= " AND EXISTS (
            SELECT 1 FROM file_facilities ff 
            WHERE ff.file_record_id = o.id 
            AND ff.sanction_date >= DATE_SUB(CURDATE(), INTERVAL 15 DAY)
        )";
    } elseif ($active_filter === 'Approval/Sanction' && !empty($from_date) && !empty($to_date)) {
        $list_sql .= " AND EXISTS (
            SELECT 1 FROM file_facilities ff 
            WHERE ff.file_record_id = o.id 
            AND ff.sanction_date BETWEEN '{$safe_from} 00:00:00' AND '{$safe_to} 23:59:59'
        )";
    }
    
    $list_sql .= " ORDER BY pa.assigned_date DESC";
    
    $list_stmt = $conn->prepare($list_sql);
    $list_stmt->bind_param('s', $active_filter);
    $list_stmt->execute();
    $matching_proposals = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $list_stmt->close();
}

// FIX #2: Improved workforce query to correctly show active tasks and client names
$unified_workforce_res = $conn->query("
    SELECT 
        u.id AS user_id,
        u.full_name,
        u.username,
        u.employee_id,
        g.group_name,
        g.leader_id,
        (SELECT leader.full_name FROM users leader WHERE leader.id = g.leader_id) AS leader_name,
        COUNT(DISTINCT CASE 
            WHEN o.is_deleted = 0 
            AND pa.proposal_status NOT IN ('Approval/Sanction', 'Declined') 
            AND pa.proposal_status IS NOT NULL
            THEN pa.proposal_ref 
        END) AS active_count,
        GROUP_CONCAT(DISTINCT 
            CASE 
                WHEN o.is_deleted = 0 
                AND pa.proposal_status NOT IN ('Approval/Sanction', 'Declined')
                AND o.client IS NOT NULL
                THEN CONCAT(o.client, '|', pa.proposal_status)
            END 
            ORDER BY pa.assigned_date DESC
            SEPARATOR '||'
        ) AS client_with_status
    FROM users u
    INNER JOIN user_groups g ON u.group_id = g.id
    LEFT JOIN proposal_assignments pa ON u.id = pa.user_id
    LEFT JOIN office_files o ON pa.file_id = o.id AND o.is_deleted = 0
    WHERE u.is_locked = 0 OR u.is_locked IS NULL
    GROUP BY u.id, u.full_name, u.username, u.employee_id, g.group_name, g.leader_id
    ORDER BY g.group_name ASC, active_count DESC, u.full_name ASC
");

$workforce_hierarchy = [];
if ($unified_workforce_res && $unified_workforce_res->num_rows > 0) {
    while ($row = $unified_workforce_res->fetch_assoc()) {
        $g_name = $row['group_name'];
        if (!isset($workforce_hierarchy[$g_name])) {
            $workforce_hierarchy[$g_name] = [
                'leader' => $row['leader_name'] ?? 'None Assigned',
                'leader_id' => $row['leader_id'],
                'roster' => []
            ];
        }
        // Parse client_with_status
        $client_list = [];
        if (!empty($row['client_with_status'])) {
            $items = explode('||', $row['client_with_status']);
            foreach ($items as $item) {
                $parts = explode('|', $item);
                if (count($parts) >= 2 && !empty($parts[0])) {
                    $client_list[] = [
                        'name' => $parts[0],
                        'status' => $parts[1]
                    ];
                } elseif (!empty($item)) {
                    $client_list[] = [
                        'name' => $item,
                        'status' => 'Unknown'
                    ];
                }
            }
        }
        $row['client_details'] = $client_list;
        $workforce_hierarchy[$g_name]['roster'][] = $row;
    }
}
?>