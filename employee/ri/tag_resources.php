<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'EMPLOYEE') {
    header("Location: ../unauthorized.php");
    exit();
}

$first_name = htmlspecialchars($user['first_name']);
$middle_name = htmlspecialchars($user['middle_name']);
$last_name = htmlspecialchars($user['last_name']);
$role = htmlspecialchars($user['role']);

$full_name = $first_name;
if (!empty($middle_name)) {
    $full_name .= " " . $middle_name;
}
$full_name .= " " . $last_name;

// Handle form submission for tagging resources
$success_message = '';
$error_message = '';
$form_data = [];

// Fetch all tags from service history and maintenance notes
$existing_tags = [];
$tags_query = "SELECT DISTINCT service_type FROM service_history WHERE service_type LIKE 'tag:%' 
               UNION 
               SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(maintenance_notes, 'TAG:', -1), '\n', 1) as tag 
               FROM resources 
               WHERE maintenance_notes LIKE '%TAG:%' 
               AND TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(maintenance_notes, 'TAG:', -1), '\n', 1)) != ''";
$tags_stmt = $pdo->query($tags_query);
$existing_tags_db = $tags_stmt->fetchAll();

foreach ($existing_tags_db as $tag) {
    $tag_value = $tag['service_type'] ?? $tag['tag'];
    if (!empty($tag_value) && strpos($tag_value, 'tag:') === 0) {
        $tag_name = str_replace('tag:', '', $tag_value);
        if (!empty($tag_name)) {
            $existing_tags[] = trim($tag_name);
        }
    } elseif (!empty($tag_value) && !in_array($tag_value, $existing_tags)) {
        $existing_tags[] = trim($tag_value);
    }
}

$existing_tags = array_unique($existing_tags);

// Fetch all resources with their current tags
$resources_query = "SELECT r.*, 
                   GROUP_CONCAT(DISTINCT 
                       CASE 
                           WHEN sh.service_type LIKE 'tag:%' THEN REPLACE(sh.service_type, 'tag:', '')
                           WHEN r.maintenance_notes LIKE '%TAG:%' THEN 
                               TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(r.maintenance_notes, 'TAG:', -1), '\n', 1))
                           ELSE NULL
                       END
                   ) as current_tags
                   FROM resources r
                   LEFT JOIN service_history sh ON r.id = sh.resource_id AND sh.service_type LIKE 'tag:%'
                   WHERE r.is_active = 1
                   GROUP BY r.id
                   ORDER BY r.resource_name";
$resources_stmt = $pdo->query($resources_query);
$resources = $resources_stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $resource_id = $_POST['resource_id'] ?? '';
    $action = $_POST['action'] ?? '';
    $tag_name = trim($_POST['tag_name'] ?? '');
    $remove_tag = $_POST['remove_tag'] ?? '';
    $tag_notes = $_POST['tag_notes'] ?? '';
    $tag_category = $_POST['tag_category'] ?? 'custom';
    $tag_color = $_POST['tag_color'] ?? '#3b82f6';
    
    if (empty($resource_id)) {
        $error_message = "Please select a resource.";
        $form_data = $_POST;
    } elseif (empty($action)) {
        $error_message = "Please select an action (add or remove tag).";
        $form_data = $_POST;
    } elseif ($action === 'add' && empty($tag_name)) {
        $error_message = "Please enter a tag name.";
        $form_data = $_POST;
    } elseif ($action === 'remove' && empty($remove_tag)) {
        $error_message = "Please select a tag to remove.";
        $form_data = $_POST;
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get resource information
            $resource_query = "SELECT id, resource_name, maintenance_notes FROM resources WHERE id = ?";
            $resource_stmt = $pdo->prepare($resource_query);
            $resource_stmt->execute([$resource_id]);
            $resource = $resource_stmt->fetch();
            
            if (!$resource) {
                throw new Exception("Resource not found.");
            }
            
            if ($action === 'add') {
                // Clean tag name (remove # if included)
                $tag_name = str_replace('#', '', $tag_name);
                $tag_name = ucwords(strtolower($tag_name));
                
                // Check if tag already exists on this resource
                $check_tag_query = "SELECT 1 FROM service_history 
                                   WHERE resource_id = ? AND service_type = ?";
                $check_stmt = $pdo->prepare($check_tag_query);
                $check_stmt->execute([$resource_id, 'tag:' . $tag_name]);
                $tag_exists = $check_stmt->fetch();
                
                if ($tag_exists) {
                    throw new Exception("Tag '$tag_name' already exists on this resource.");
                }
                
                // Create service history entry for the tag
                $service_query = "INSERT INTO service_history 
                                 (resource_id, service_type, service_date, 
                                  performed_by_id, service_notes, status_after_service) 
                                 VALUES (?, ?, NOW(), ?, ?, ?)";
                
                $service_notes = "TAG ADDED: " . $tag_name . "\n" .
                               "Category: " . $tag_category . "\n" .
                               "Color: " . $tag_color . "\n" .
                               "Added by: " . $full_name . "\n";
                
                if (!empty($tag_notes)) {
                    $service_notes .= "Notes: " . $tag_notes . "\n";
                }
                
                $service_stmt = $pdo->prepare($service_query);
                $service_stmt->execute([
                    $resource_id,
                    'tag:' . $tag_name,
                    $user_id,
                    $service_notes,
                    $resource['condition_status'] // Keep current condition status
                ]);
                
                // Update resource maintenance notes with tag info
                $update_resource_query = "UPDATE resources SET 
                                         maintenance_notes = CONCAT(COALESCE(maintenance_notes, ''), 
                                         '\nTAG: " . $tag_name . " - Added: " . date('Y-m-d H:i:s') . 
                                         " by " . $full_name . " - Category: " . $tag_category . 
                                         " - Color: " . $tag_color . 
                                         ($tag_notes ? " - Notes: " . $tag_notes : "") . "')
                                         WHERE id = ?";
                
                $update_stmt = $pdo->prepare($update_resource_query);
                $update_stmt->execute([$resource_id]);
                
                // Add to existing tags list if not already there
                if (!in_array($tag_name, $existing_tags)) {
                    $existing_tags[] = $tag_name;
                }
                
                $success_message = "Tag '$tag_name' added successfully to " . $resource['resource_name'] . ".";
                
            } elseif ($action === 'remove') {
                // Remove tag from service history
                $remove_service_query = "DELETE FROM service_history 
                                        WHERE resource_id = ? AND service_type = ?";
                $remove_service_stmt = $pdo->prepare($remove_service_query);
                $remove_service_stmt->execute([$resource_id, 'tag:' . $remove_tag]);
                
                // Also remove from maintenance notes
                if (!empty($resource['maintenance_notes'])) {
                    $notes_lines = explode("\n", $resource['maintenance_notes']);
                    $new_notes = [];
                    foreach ($notes_lines as $line) {
                        if (strpos($line, 'TAG: ' . $remove_tag) === false) {
                            $new_notes[] = $line;
                        }
                    }
                    $new_notes_text = implode("\n", $new_notes);
                    
                    $update_notes_query = "UPDATE resources SET maintenance_notes = ? WHERE id = ?";
                    $update_notes_stmt = $pdo->prepare($update_notes_query);
                    $update_notes_stmt->execute([$new_notes_text, $resource_id]);
                }
                
                $success_message = "Tag '$remove_tag' removed successfully from " . $resource['resource_name'] . ".";
            }
            
            $pdo->commit();
            $form_data = [];
            
            // Refresh resources after update
            $resources_stmt = $pdo->query($resources_query);
            $resources = $resources_stmt->fetchAll();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = "Error processing tag action: " . $e->getMessage();
            $form_data = $_POST;
        }
    }
}

// Fetch tag statistics
$tag_stats_query = "SELECT 
                    COUNT(DISTINCT r.id) as tagged_resources,
                    COUNT(DISTINCT 
                        CASE 
                            WHEN sh.service_type LIKE 'tag:%' THEN REPLACE(sh.service_type, 'tag:', '')
                            ELSE NULL
                        END
                    ) as unique_tags,
                    COUNT(DISTINCT 
                        CASE 
                            WHEN r.resource_type = 'Vehicle' THEN r.id
                            ELSE NULL
                        END
                    ) as tagged_vehicles,
                    COUNT(DISTINCT 
                        CASE 
                            WHEN r.resource_type IN ('Tool', 'Equipment') THEN r.id
                            ELSE NULL
                        END
                    ) as tagged_equipment
                    FROM resources r
                    LEFT JOIN service_history sh ON r.id = sh.resource_id AND sh.service_type LIKE 'tag:%'
                    WHERE r.is_active = 1";
$tag_stats_stmt = $pdo->query($tag_stats_query);
$tag_stats = $tag_stats_stmt->fetch();

// Fetch popular tags
$popular_tags_query = "SELECT 
                       REPLACE(service_type, 'tag:', '') as tag_name,
                       COUNT(*) as tag_count,
                       COUNT(DISTINCT resource_id) as resource_count
                       FROM service_history 
                       WHERE service_type LIKE 'tag:%'
                       GROUP BY service_type
                       ORDER BY COUNT(*) DESC
                       LIMIT 10";
$popular_tags_stmt = $pdo->query($popular_tags_query);
$popular_tags = $popular_tags_stmt->fetchAll();

// Fetch recently tagged resources
$recent_tags_query = "SELECT 
                      r.resource_name, r.resource_type, r.category,
                      REPLACE(sh.service_type, 'tag:', '') as tag_name,
                      sh.service_date,
                      u.first_name, u.last_name
                      FROM service_history sh
                      JOIN resources r ON sh.resource_id = r.id
                      JOIN users u ON sh.performed_by_id = u.id
                      WHERE sh.service_type LIKE 'tag:%'
                      ORDER BY sh.service_date DESC
                      LIMIT 15";
$recent_tags_stmt = $pdo->query($recent_tags_query);
$recent_tags = $recent_tags_stmt->fetchAll();

$stmt = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tag Resources - FRSM</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>
        :root {
            --primary-color: #dc2626;
            --primary-dark: #b91c1c;
            --secondary-color: #ef4444;
            --secondary-dark: #dc2626;
            --background-color: #ffffff;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #f9fafb;
            --sidebar-bg: #ffffff;
        }
        
        .dark-mode {
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #1e293b;
            --card-bg: #1e293b;
            --sidebar-bg: #0f172a;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--text-color);
            background-color: var(--background-color);
        }

        .dashboard-content {
            padding: 0;
            min-height: 100vh;
        }

        .dashboard-header {
            color: white;
            padding: 60px 40px 40px;
            border-radius: 0 0 30px 30px;
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid var(--border-color);
        }

        .dark-mode .dashboard-header {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
        }
        
        .dashboard-title {
            font-size: 40px;
            margin-bottom: 12px;
            color: var(--text-color);
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .dashboard-subtitle {
            font-size: 16px;
            opacity: 0.9;
            color: var(--text-color);
        }

        .content-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            padding: 0 40px;
            margin-bottom: 40px;
        }

        @media (max-width: 1024px) {
            .content-wrapper {
                grid-template-columns: 1fr;
            }
        }

        .form-section, .tags-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 25px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 30px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-label .required {
            color: var(--primary-color);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--background-color);
            color: var(--text-color);
        }

        .dark-mode .form-control {
            border-color: #475569;
            background: #1e293b;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        .form-text {
            font-size: 0.875rem;
            color: var(--text-light);
            margin-top: 6px;
        }

        .resource-info {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 8px;
            padding: 15px;
            background: #f3f4f6;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .dark-mode .resource-info {
            background: #334155;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .info-label {
            font-weight: 600;
            color: var(--text-light);
        }

        .info-value {
            font-weight: 700;
            color: var(--text-color);
        }

        .condition-serviceable {
            color: #059669;
        }

        .condition-maintenance {
            color: #d97706;
        }

        .condition-condemned {
            color: #dc2626;
        }

        .action-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
        }

        .action-tab {
            flex: 1;
            padding: 15px 20px;
            text-align: center;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            color: var(--text-light);
            background: var(--background-color);
        }

        .action-tab:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .action-tab.active {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
        }

        .action-tab i {
            margin-right: 8px;
            font-size: 1.2rem;
        }

        .action-details {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .action-details.active {
            display: block;
        }

        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary-dark));
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(220, 38, 38, 0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .alert-message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
            border: 2px solid #6ee7b7;
        }

        .dark-mode .alert-success {
            background: linear-gradient(135deg, #064e3b, #065f46);
            color: #d1fae5;
            border-color: #10b981;
        }

        .alert-error {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #7f1d1d;
            border: 2px solid #fca5a5;
        }

        .dark-mode .alert-error {
            background: linear-gradient(135deg, #7f1d1d, #991b1b);
            color: #fecaca;
            border-color: #ef4444;
        }

        .tags-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .tags-table th {
            text-align: left;
            padding: 12px 16px;
            background: var(--border-color);
            color: var(--text-color);
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
        }

        .dark-mode .tags-table th {
            background: #334155;
            border-bottom-color: #475569;
        }

        .tags-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .dark-mode .tags-table td {
            border-bottom-color: #475569;
        }

        .tags-table tr:hover {
            background: rgba(220, 38, 38, 0.05);
        }

        .dark-mode .tags-table tr:hover {
            background: rgba(220, 38, 38, 0.1);
        }

        .resource-name-cell {
            font-weight: 600;
            color: var(--text-color);
        }

        .tag-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            margin: 2px;
            border: 1px solid transparent;
        }

        .tag-badge:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .tag-category {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            background: rgba(255, 255, 255, 0.2);
            margin-left: 6px;
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            padding: 0 40px;
        }

        .quick-stat-item {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease;
        }

        .quick-stat-item:hover {
            transform: translateY(-5px);
        }

        .quick-stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .stat-tagged {
            color: #4f46e5;
        }

        .stat-unique {
            color: #d97706;
        }

        .stat-vehicles {
            color: #059669;
        }

        .stat-equipment {
            color: #dc2626;
        }

        .quick-stat-label {
            color: var(--text-light);
            font-size: 0.9rem;
            font-weight: 600;
        }

        .quick-stat-desc {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 5px;
        }

        .tag-cloud {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 20px 0;
            padding: 20px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .dark-mode .tag-cloud {
            background: #1e293b;
            border-color: #334155;
        }

        .cloud-tag {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .cloud-tag:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .cloud-tag.selected {
            border: 2px solid var(--primary-color);
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.2);
        }

        .tag-size-1 { font-size: 0.8rem; padding: 4px 8px; }
        .tag-size-2 { font-size: 0.9rem; padding: 5px 10px; }
        .tag-size-3 { font-size: 1rem; padding: 6px 12px; }
        .tag-size-4 { font-size: 1.1rem; padding: 7px 14px; }
        .tag-size-5 { font-size: 1.2rem; padding: 8px 16px; }

        .color-picker {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .color-option {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            border: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .color-option:hover {
            transform: scale(1.1);
        }

        .color-option.selected {
            border: 3px solid var(--text-color);
            box-shadow: 0 0 0 2px var(--background-color);
        }

        .color-preview {
            width: 100%;
            height: 40px;
            border-radius: 8px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .category-badges {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .category-badge {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .category-badge:hover {
            transform: translateY(-1px);
        }

        .category-badge.selected {
            border: 2px solid var(--text-color);
            box-shadow: 0 0 0 2px var(--background-color);
        }

        @media (max-width: 1200px) {
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .dashboard-header {
                padding: 40px 25px 30px;
                border-radius: 0 0 20px 20px;
            }
            
            .dashboard-title {
                font-size: 2.2rem;
            }
            
            .content-wrapper {
                padding: 0 25px;
            }
            
            .form-section, .tags-section {
                padding: 30px 25px;
            }
            
            .quick-stats {
                grid-template-columns: 1fr;
                padding: 0 25px;
            }
            
            .action-tabs {
                flex-direction: column;
            }
            
            .tag-cloud {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="logo">
                <div class="logo-icon">
                    <img src="../../img/frsm-logo.png" alt="Fire & Rescue Logo" style="width: 40px; height: 45px;">
                </div>
                <span class="logo-text">Fire & Rescue</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">FIRE & RESCUE MANAGEMENT</p>
                
                <div class="menu-items">
                    <a href="../employee_dashboard.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bxs-dashboard icon-red'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- Fire & Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('fire-incident')">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-alarm-exclamation icon-orange'></i>
                        </div>
                        <span class="font-medium">Fire & Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="fire-incident" class="submenu">
                         <a href="../fir/recieve_data.php" class="submenu-item">Receive Data</a>
                        <a href="../fir/update_status.php" class="submenu-item">Update Status</a>
                    </div>
                    
                    <!-- Dispatch Coordination -->
                    <div class="menu-item" onclick="toggleSubmenu('dispatch')">
                        <div class="icon-box icon-bg-yellow">
                            <i class='bx bxs-truck icon-yellow'></i>
                        </div>
                        <span class="font-medium">Dispatch Coordination</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="dispatch" class="submenu">
                        <a href="../dc/select_unit.php" class="submenu-item">Select Unit</a>
                        <a href="../dc/send_dispatch.php" class="submenu-item">Send Dispatch Info</a>
                        <a href="../dc/track_status.php" class="submenu-item">Track Status</a>
                    </div>
                    
                    <!-- Barangay Volunteer Roster Access -->
                    <div class="menu-item" onclick="toggleSubmenu('volunteer')">
                        <div class="icon-box icon-bg-blue">
                            <i class='bx bxs-user-detail icon-blue'></i>
                        </div>
                        <span class="font-medium">Volunteer Roster Access</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                   <div id="volunteer" class="submenu">
                        <a href="review_data.php" class="submenu-item">Review/Aprroved Data Management</a>
                        <a href="approve_applications.php" class="submenu-item">Assign Volunteers</a>
                        <a href="view_availability.php" class="submenu-item">View Availability</a>
                        <a href="remove_volunteers.php" class="submenu-item">Remove Volunteers</a>
                        <a href="toggle_volunteer_registration.php" class="submenu-item">Open/Close Registration</a>
                    </div>
                    
                    <!-- Resource Inventory Updates -->
                    <div class="menu-item" onclick="toggleSubmenu('inventory')">
                        <div class="icon-box icon-bg-green">
                            <i class='bx bxs-cube icon-green'></i>
                        </div>
                        <span class="font-medium">Resource Inventory</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inventory" class="submenu active">
                        <a href="log_usage.php" class="submenu-item">Log Usage</a>
                        <a href="report_damages.php" class="submenu-item">Report Damages</a>
                        <a href="request_supplies.php" class="submenu-item">Request Supplies</a>
                        <a href="tag_resources.php" class="submenu-item active">Tag Resources</a>
                    </div>
                    
                    <!-- Shift & Duty Scheduling -->
                    <div class="menu-item" onclick="toggleSubmenu('schedule')">
                        <div class="icon-box icon-bg-purple">
                            <i class='bx bxs-calendar icon-purple'></i>
                        </div>
                        <span class="font-medium">Shift & Duty Scheduling</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="schedule" class="submenu">
                        <a href="../sds/view_shifts.php" class="submenu-item">View Shifts</a>
                        <a href="../sds/confirm_availability.php" class="submenu-item">Confirm Availability</a>
                        <a href="../sds/request_change.php" class="submenu-item">Request Change</a>
                    </div>
                    
                    <!-- Training & Certification Logging -->
                    <div class="menu-item" onclick="toggleSubmenu('training')">
                        <div class="icon-box icon-bg-teal">
                            <i class='bx bxs-graduation icon-teal'></i>
                        </div>
                        <span class="font-medium">Training & Certification</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="training" class="submenu">
                        <a href="../training/submit_training.php" class="submenu-item">Submit Training</a>
                        <a href="../training/upload_certificates.php" class="submenu-item">Upload Certificates</a>
                        <a href="../training/request_training.php" class="submenu-item">Request Training</a>
                        <a href="../training/view_events.php" class="submenu-item">View Events</a>
                    </div>
                    
                    <!-- Inspection Logs -->
                    <div class="menu-item" onclick="toggleSubmenu('inspection')">
                        <div class="icon-box icon-bg-cyan">
                            <i class='bx bxs-check-shield icon-cyan'></i>
                        </div>
                        <span class="font-medium">Inspection Logs</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="inspection" class="submenu">
                        <a href="../inspection/conduct_inspections.php" class="submenu-item">Conduct Inspections</a>
                        <a href="../inspection/submit_findings.php" class="submenu-item">Submit Findings</a>
                        <a href="../inspection/upload_photos.php" class="submenu-item">Upload Photos</a>
                        <a href="../inspection/tag_violations.php" class="submenu-item">Tag Violations</a>
                    </div>
                    
                    <!-- Post-Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('postincident')">
                        <div class="icon-box icon-bg-pink">
                            <i class='bx bxs-file-doc icon-pink'></i>
                        </div>
                        <span class="font-medium">Post-Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="postincident" class="submenu">
                        <a href="../postincident/upload_reports.php" class="submenu-item">Upload Reports</a>
                        <a href="../postincident/add_notes.php" class="submenu-item">Add Notes</a>
                        <a href="../postincident/attach_equipment.php" class="submenu-item">Attach Equipment</a>
                        <a href="../postincident/mark_completed.php" class="submenu-item">Mark Completed</a>
                    </div>
                </div>
                
                <p class="menu-title" style="margin-top: 32px;">GENERAL</p>
                
                <div class="menu-items">
                    <a href="../settings.php" class="menu-item">
                        <div class="icon-box icon-bg-indigo">
                            <i class='bx bxs-cog icon-indigo'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="../profile/profile.php" class="menu-item">
                        <div class="icon-box icon-bg-orange">
                            <i class='bx bxs-user icon-orange'></i>
                        </div>
                        <span class="font-medium">Profile</span>
                    </a>
                    
                    <a href="../../includes/logout.php" class="menu-item">
                        <div class="icon-box icon-bg-red">
                            <i class='bx bx-log-out icon-red'></i>
                        </div>
                        <span class="font-medium">Logout</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search tags, resources..." class="search-input">
                        </div>
                    </div>
                    
                    <div class="header-actions">
                        <button class="theme-toggle" id="theme-toggle">
                            <i class='bx bx-moon'></i>
                            <span>Dark Mode</span>
                        </button>
                        <div class="time-display" id="time-display">
                            <i class='bx bx-time time-icon'></i>
                            <span id="current-time">Loading...</span>
                        </div>
                        <div class="user-profile">
                            <img src="../../img/rei.jfif" alt="User" class="user-avatar">
                            <div class="user-info">
                                <p class="user-name"><?php echo $full_name; ?></p>
                                <p class="user-email"><?php echo $role; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Hero Header -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Tag Resources</h1>
                        <p class="dashboard-subtitle">Organize and categorize resources with custom tags for better tracking</p>
                    </div>
                </div>

                <!-- Tag Statistics -->
                <div class="quick-stats">
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-tagged"><?php echo $tag_stats['tagged_resources'] ?? 0; ?></div>
                        <div class="quick-stat-label">Tagged Resources</div>
                        <div class="quick-stat-desc">Resources with at least one tag</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-unique"><?php echo $tag_stats['unique_tags'] ?? 0; ?></div>
                        <div class="quick-stat-label">Unique Tags</div>
                        <div class="quick-stat-desc">Different tags in use</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-vehicles"><?php echo $tag_stats['tagged_vehicles'] ?? 0; ?></div>
                        <div class="quick-stat-label">Tagged Vehicles</div>
                        <div class="quick-stat-desc">Vehicles with tags</div>
                    </div>
                    <div class="quick-stat-item">
                        <div class="quick-stat-number stat-equipment"><?php echo $tag_stats['tagged_equipment'] ?? 0; ?></div>
                        <div class="quick-stat-label">Tagged Equipment</div>
                        <div class="quick-stat-desc">Tools & equipment tagged</div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                    <div class="alert-message alert-success" style="margin: 0 40px 25px;">
                        <i class='bx bxs-check-circle'></i>
                        <?php echo $success_message; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert-message alert-error" style="margin: 0 40px 25px;">
                        <i class='bx bxs-error-circle'></i>
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>

                <!-- Main Content -->
                <div class="content-wrapper">
                    <!-- Tag Management Form -->
                    <div class="form-section">
                        <h2 class="section-title">
                            <i class='bx bxs-tag'></i>
                            Manage Tags
                        </h2>

                        <!-- Action Tabs -->
                        <div class="action-tabs">
                            <div class="action-tab active" data-action="add">
                                <i class='bx bx-plus-circle'></i>
                                Add Tag
                            </div>
                            <div class="action-tab" data-action="remove">
                                <i class='bx bx-minus-circle'></i>
                                Remove Tag
                            </div>
                        </div>

                        <form method="POST" id="tag-form">
                            <input type="hidden" name="action" id="action_input" value="add">
                            
                            <div class="form-group">
                                <label class="form-label">
                                    <span class="required">*</span> Resource
                                </label>
                                <select class="form-control" name="resource_id" id="resource_id" required>
                                    <option value="">Select a resource...</option>
                                    <?php foreach ($resources as $resource): 
                                        // Determine condition class
                                        $condition_class = '';
                                        switch ($resource['condition_status']) {
                                            case 'Serviceable':
                                                $condition_class = 'condition-serviceable';
                                                break;
                                            case 'Under Maintenance':
                                                $condition_class = 'condition-maintenance';
                                                break;
                                            case 'Condemned':
                                                $condition_class = 'condition-condemned';
                                                break;
                                        }
                                        
                                        // Parse current tags
                                        $current_tags = [];
                                        if (!empty($resource['current_tags'])) {
                                            $current_tags = array_map('trim', explode(',', $resource['current_tags']));
                                            $current_tags = array_filter($current_tags); // Remove empty values
                                        }
                                    ?>
                                        <option value="<?php echo $resource['id']; ?>"
                                            <?php echo ($form_data['resource_id'] ?? '') == $resource['id'] ? 'selected' : ''; ?>
                                            data-quantity="<?php echo $resource['quantity']; ?>"
                                            data-available="<?php echo $resource['available_quantity']; ?>"
                                            data-condition="<?php echo $resource['condition_status']; ?>"
                                            data-type="<?php echo $resource['resource_type']; ?>"
                                            data-category="<?php echo $resource['category']; ?>"
                                            data-unit="<?php echo $resource['unit_of_measure'] ?? 'units'; ?>"
                                            data-tags="<?php echo htmlspecialchars(json_encode($current_tags)); ?>">
                                            <?php echo htmlspecialchars($resource['resource_name']); ?> 
                                            (<?php echo $resource['resource_type']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="resource-info-display" class="resource-info" style="display: none;">
                                    <div class="info-item">
                                        <span class="info-label">Type:</span>
                                        <span class="info-value" id="resource-type"></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Category:</span>
                                        <span class="info-value" id="resource-category"></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Condition:</span>
                                        <span class="info-value" id="condition-status"></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Current Tags:</span>
                                        <div id="current-tags-display"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Add Tag Details (Default) -->
                            <div id="add-details" class="action-details active">
                                <div class="form-group">
                                    <label class="form-label">
                                        <span class="required">*</span> Tag Name
                                    </label>
                                    <div class="tag-cloud" id="tag-suggestions">
                                        <?php if (!empty($existing_tags)): 
                                            $tag_sizes = [1, 2, 3, 4, 5];
                                            shuffle($existing_tags);
                                            foreach ($existing_tags as $index => $tag): 
                                                $size_class = 'tag-size-' . $tag_sizes[$index % count($tag_sizes)];
                                                $color = generateTagColor($tag);
                                        ?>
                                            <div class="cloud-tag <?php echo $size_class; ?>" 
                                                 data-tag="<?php echo htmlspecialchars($tag); ?>"
                                                 style="background-color: <?php echo $color; ?>; color: white;">
                                                <?php echo htmlspecialchars($tag); ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                            <div style="color: var(--text-light); text-align: center; width: 100%;">
                                                No existing tags. Create your first tag!
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <input type="text" 
                                           class="form-control" 
                                           name="tag_name" 
                                           id="tag_name"
                                           required
                                           value="<?php echo htmlspecialchars($form_data['tag_name'] ?? ''); ?>"
                                           placeholder="Enter tag name (e.g., 'Emergency', 'New', 'Needs Calibration')">
                                    <div class="form-text">Click on a tag above to select it, or type a new one</div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Tag Category</label>
                                    <div class="category-badges">
                                        <div class="category-badge selected" data-category="custom" style="background-color: #3b82f6; color: white;">Custom</div>
                                        <div class="category-badge" data-category="status" style="background-color: #10b981; color: white;">Status</div>
                                        <div class="category-badge" data-category="priority" style="background-color: #f59e0b; color: white;">Priority</div>
                                        <div class="category-badge" data-category="location" style="background-color: #8b5cf6; color: white;">Location</div>
                                        <div class="category-badge" data-category="maintenance" style="background-color: #ef4444; color: white;">Maintenance</div>
                                    </div>
                                    <input type="hidden" name="tag_category" id="tag_category" value="custom">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Tag Color</label>
                                    <div class="color-picker">
                                        <?php 
                                        $colors = [
                                            '#3b82f6' => 'Blue',
                                            '#10b981' => 'Green',
                                            '#f59e0b' => 'Amber',
                                            '#ef4444' => 'Red',
                                            '#8b5cf6' => 'Purple',
                                            '#ec4899' => 'Pink',
                                            '#6366f1' => 'Indigo',
                                            '#14b8a6' => 'Teal',
                                            '#f97316' => 'Orange',
                                            '#84cc16' => 'Lime'
                                        ];
                                        foreach ($colors as $color => $name): ?>
                                            <div class="color-option <?php echo $color == '#3b82f6' ? 'selected' : ''; ?>" 
                                                 data-color="<?php echo $color; ?>"
                                                 style="background-color: <?php echo $color; ?>;"
                                                 title="<?php echo $name; ?>"></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="color-preview" id="color-preview" style="background-color: #3b82f6;">
                                        Preview: <span id="preview-tag-name">Tag Name</span>
                                    </div>
                                    <input type="hidden" name="tag_color" id="tag_color" value="#3b82f6">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Tag Notes (Optional)</label>
                                    <textarea class="form-control" 
                                              name="tag_notes" 
                                              rows="3"
                                              placeholder="Additional notes about this tag..."><?php echo htmlspecialchars($form_data['tag_notes'] ?? ''); ?></textarea>
                                    <div class="form-text">Optional notes to provide context about this tag</div>
                                </div>
                            </div>

                            <!-- Remove Tag Details (Hidden by default) -->
                            <div id="remove-details" class="action-details">
                                <div class="form-group">
                                    <label class="form-label">
                                        <span class="required">*</span> Tag to Remove
                                    </label>
                                    <select class="form-control" name="remove_tag" id="remove_tag">
                                        <option value="">Select a tag to remove...</option>
                                    </select>
                                    <div class="form-text">Only shows tags that exist on the selected resource</div>
                                </div>
                            </div>

                            <button type="submit" class="btn-submit" id="submit-btn">
                                <i class='bx bx-check'></i>
                                <span id="submit-text">Add Tag</span>
                            </button>
                        </form>
                    </div>

                    <!-- Recent Tags & Popular Tags -->
                    <div class="tags-section">
                        <h2 class="section-title">
                            <i class='bx bxs-tag-alt'></i>
                            Tag Overview
                        </h2>

                        <!-- Popular Tags Section -->
                        <div style="margin-bottom: 30px;">
                            <h3 style="font-size: 1.2rem; margin-bottom: 15px; color: var(--text-color);">
                                <i class='bx bxs-star'></i> Popular Tags
                            </h3>
                            
                            <?php if (!empty($popular_tags)): ?>
                                <div class="tag-cloud">
                                    <?php foreach ($popular_tags as $tag): 
                                        $size = min(5, ceil($tag['tag_count'] / 2));
                                        $size_class = 'tag-size-' . $size;
                                        $color = generateTagColor($tag['tag_name']);
                                    ?>
                                        <div class="cloud-tag <?php echo $size_class; ?>" 
                                             data-tag="<?php echo htmlspecialchars($tag['tag_name']); ?>"
                                             style="background-color: <?php echo $color; ?>; color: white;"
                                             title="<?php echo $tag['resource_count']; ?> resources">
                                            <?php echo htmlspecialchars($tag['tag_name']); ?>
                                            <span class="tag-category"><?php echo $tag['tag_count']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="no-data" style="padding: 20px;">
                                    <i class='bx bx-tag'></i>
                                    <p>No popular tags yet</p>
                                    <p class="form-text">Tags will appear here as they are created</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Recently Tagged Resources -->
                        <div>
                            <h3 style="font-size: 1.2rem; margin-bottom: 15px; color: var(--text-color);">
                                <i class='bx bxs-time'></i> Recently Tagged Resources
                            </h3>

                            <?php if (!empty($recent_tags)): ?>
                                <div style="overflow-x: auto;">
                                    <table class="tags-table">
                                        <thead>
                                            <tr>
                                                <th>Resource</th>
                                                <th>Tag</th>
                                                <th>Date</th>
                                                <th>By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_tags as $tag): 
                                                $color = generateTagColor($tag['tag_name']);
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="resource-name-cell"><?php echo htmlspecialchars($tag['resource_name']); ?></div>
                                                    <div class="form-text" style="font-size: 0.75rem;">
                                                        <?php echo $tag['category']; ?> • <?php echo $tag['resource_type']; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="tag-badge" style="background-color: <?php echo $color; ?>; color: white;">
                                                        <?php echo htmlspecialchars($tag['tag_name']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php echo date('M j', strtotime($tag['service_date'])); ?><br>
                                                    <div class="form-text" style="font-size: 0.75rem;">
                                                        <?php echo date('g:i A', strtotime($tag['service_date'])); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($tag['first_name'] . ' ' . $tag['last_name']); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div style="text-align: center; margin-top: 20px;">
                                    <a href="#" class="btn-submit" style="width: auto; padding: 10px 20px;">
                                        <i class='bx bx-list-ul'></i>
                                        View All Tags
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="no-data">
                                    <i class='bx bx-package'></i>
                                    <p>No recent tags found</p>
                                    <p class="form-text">Tags will appear here after they are created</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Theme toggle
            const themeToggle = document.getElementById('theme-toggle');
            const themeIcon = themeToggle.querySelector('i');
            const themeText = themeToggle.querySelector('span');
            
            themeToggle.addEventListener('click', function() {
                document.body.classList.toggle('dark-mode');
                
                if (document.body.classList.contains('dark-mode')) {
                    themeIcon.className = 'bx bx-sun';
                    themeText.textContent = 'Light Mode';
                } else {
                    themeIcon.className = 'bx bx-moon';
                    themeText.textContent = 'Dark Mode';
                }
            });
            
            // Time display
            function updateTime() {
                const now = new Date();
                const hours = now.getHours().toString().padStart(2, '0');
                const minutes = now.getMinutes().toString().padStart(2, '0');
                const seconds = now.getSeconds().toString().padStart(2, '0');
                
                const timeString = `${hours}:${minutes}:${seconds}`;
                document.getElementById('current-time').textContent = timeString;
            }
            
            updateTime();
            setInterval(updateTime, 1000);
            
            // Action tabs
            const tabs = document.querySelectorAll('.action-tab');
            const addDetails = document.getElementById('add-details');
            const removeDetails = document.getElementById('remove-details');
            const actionInput = document.getElementById('action_input');
            const submitText = document.getElementById('submit-text');
            const removeTagSelect = document.getElementById('remove_tag');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const action = this.getAttribute('data-action');
                    
                    // Update active tab
                    tabs.forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Update action input
                    actionInput.value = action;
                    
                    // Show/hide appropriate details
                    if (action === 'add') {
                        addDetails.classList.add('active');
                        removeDetails.classList.remove('active');
                        submitText.textContent = 'Add Tag';
                    } else {
                        addDetails.classList.remove('active');
                        removeDetails.classList.add('active');
                        submitText.textContent = 'Remove Tag';
                    }
                });
            });
            
            // Resource information display
            const resourceSelect = document.getElementById('resource_id');
            const resourceInfo = document.getElementById('resource-info-display');
            const resourceType = document.getElementById('resource-type');
            const resourceCategory = document.getElementById('resource-category');
            const conditionStatus = document.getElementById('condition-status');
            const currentTagsDisplay = document.getElementById('current-tags-display');
            
            resourceSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const type = selectedOption.getAttribute('data-type');
                const category = selectedOption.getAttribute('data-category');
                const condition = selectedOption.getAttribute('data-condition');
                const tagsJson = selectedOption.getAttribute('data-tags');
                
                if (type !== null) {
                    resourceType.textContent = type;
                    resourceCategory.textContent = category;
                    
                    // Set condition with color
                    conditionStatus.textContent = condition;
                    conditionStatus.className = 'info-value ';
                    if (condition === 'Serviceable') {
                        conditionStatus.classList.add('condition-serviceable');
                    } else if (condition === 'Under Maintenance') {
                        conditionStatus.classList.add('condition-maintenance');
                    } else if (condition === 'Condemned') {
                        conditionStatus.classList.add('condition-condemned');
                    }
                    
                    // Display current tags
                    if (tagsJson) {
                        const tags = JSON.parse(tagsJson);
                        currentTagsDisplay.innerHTML = '';
                        
                        if (tags.length > 0) {
                            tags.forEach(tag => {
                                const color = generateColorForTag(tag);
                                const tagElement = document.createElement('span');
                                tagElement.className = 'tag-badge';
                                tagElement.style.backgroundColor = color;
                                tagElement.style.color = 'white';
                                tagElement.style.marginRight = '4px';
                                tagElement.style.marginBottom = '4px';
                                tagElement.textContent = tag;
                                currentTagsDisplay.appendChild(tagElement);
                            });
                        } else {
                            currentTagsDisplay.innerHTML = '<span style="color: var(--text-light);">No tags</span>';
                        }
                    }
                    
                    resourceInfo.style.display = 'flex';
                    
                    // Update remove tag dropdown
                    updateRemoveTagOptions(tagsJson);
                    
                } else {
                    resourceInfo.style.display = 'none';
                }
            });
            
            // Tag suggestions
            const tagSuggestions = document.getElementById('tag-suggestions');
            const tagNameInput = document.getElementById('tag_name');
            
            if (tagSuggestions) {
                tagSuggestions.addEventListener('click', function(e) {
                    if (e.target.classList.contains('cloud-tag')) {
                        const tag = e.target.getAttribute('data-tag');
                        tagNameInput.value = tag;
                        
                        // Update preview
                        document.getElementById('preview-tag-name').textContent = tag;
                        
                        // Remove previous selections
                        document.querySelectorAll('.cloud-tag.selected').forEach(t => {
                            t.classList.remove('selected');
                        });
                        
                        // Add selection to clicked tag
                        e.target.classList.add('selected');
                    }
                });
            }
            
            // Category selection
            const categoryBadges = document.querySelectorAll('.category-badge');
            const categoryInput = document.getElementById('tag_category');
            
            categoryBadges.forEach(badge => {
                badge.addEventListener('click', function() {
                    categoryBadges.forEach(b => b.classList.remove('selected'));
                    this.classList.add('selected');
                    categoryInput.value = this.getAttribute('data-category');
                });
            });
            
            // Color selection
            const colorOptions = document.querySelectorAll('.color-option');
            const colorInput = document.getElementById('tag_color');
            const colorPreview = document.getElementById('color-preview');
            
            colorOptions.forEach(option => {
                option.addEventListener('click', function() {
                    colorOptions.forEach(o => o.classList.remove('selected'));
                    this.classList.add('selected');
                    
                    const color = this.getAttribute('data-color');
                    colorInput.value = color;
                    colorPreview.style.backgroundColor = color;
                });
            });
            
            // Update tag preview when tag name changes
            tagNameInput.addEventListener('input', function() {
                const tagName = this.value.trim();
                if (tagName) {
                    document.getElementById('preview-tag-name').textContent = tagName;
                }
            });
            
            // Update remove tag options based on selected resource
            function updateRemoveTagOptions(tagsJson) {
                if (!removeTagSelect) return;
                
                // Clear existing options except the first one
                while (removeTagSelect.options.length > 1) {
                    removeTagSelect.remove(1);
                }
                
                if (tagsJson) {
                    const tags = JSON.parse(tagsJson);
                    if (tags.length > 0) {
                        tags.forEach(tag => {
                            const option = document.createElement('option');
                            option.value = tag;
                            option.textContent = tag;
                            removeTagSelect.appendChild(option);
                        });
                    }
                }
            }
            
            // Color generation function (matches PHP function)
            function generateColorForTag(tagName) {
                // Simple deterministic color generation
                const colors = [
                    '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
                    '#ec4899', '#6366f1', '#14b8a6', '#f97316', '#84cc16',
                    '#06b6d4', '#8b5cf6', '#f43f5e', '#0ea5e9', '#22c55e'
                ];
                
                // Generate a consistent index based on tag name
                let hash = 0;
                for (let i = 0; i < tagName.length; i++) {
                    hash = ((hash << 5) - hash) + tagName.charCodeAt(i);
                    hash = hash & hash;
                }
                
                const index = Math.abs(hash) % colors.length;
                return colors[index];
            }
            
            // Validate form before submission
            document.getElementById('tag-form').addEventListener('submit', function(e) {
                const resourceId = resourceSelect.value;
                const action = actionInput.value;
                const tagName = tagNameInput.value.trim();
                
                if (!resourceId) {
                    e.preventDefault();
                    alert('Please select a resource.');
                    resourceSelect.focus();
                    return false;
                }
                
                if (action === 'add' && (!tagName || tagName.length < 2)) {
                    e.preventDefault();
                    alert('Please enter a valid tag name (at least 2 characters).');
                    tagNameInput.focus();
                    return false;
                }
                
                if (action === 'remove' && !removeTagSelect.value) {
                    e.preventDefault();
                    alert('Please select a tag to remove.');
                    removeTagSelect.focus();
                    return false;
                }
                
                // Show loading state
                const submitBtn = document.getElementById('submit-btn');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Processing...';
            });
            
            // Auto-hide success messages after 5 seconds
            const successMessage = document.querySelector('.alert-success');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.style.display = 'none';
                }, 5000);
            }
            
            // Initialize resource info display if there's a pre-selected value
            if (resourceSelect.value) {
                resourceSelect.dispatchEvent(new Event('change'));
            }
            
            // Toggle submenu function
            function toggleSubmenu(id) {
                const submenu = document.getElementById(id);
                const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
                
                submenu.classList.toggle('active');
                arrow.classList.toggle('rotated');
            }
            
            // Attach toggle function to window for sidebar
            window.toggleSubmenu = toggleSubmenu;
        });
    </script>
</body>
</html>

<?php
// Helper function to generate consistent colors for tags
function generateTagColor($tagName) {
    $colors = [
        '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
        '#ec4899', '#6366f1', '#14b8a6', '#f97316', '#84cc16',
        '#06b6d4', '#8b5cf6', '#f43f5e', '#0ea5e9', '#22c55e'
    ];
    
    // Generate a consistent index based on tag name
    $hash = 0;
    for ($i = 0; $i < strlen($tagName); $i++) {
        $hash = (($hash << 5) - $hash) + ord($tagName[$i]);
        $hash = $hash & $hash;
    }
    
    $index = abs($hash) % count($colors);
    return $colors[$index];
}
?>