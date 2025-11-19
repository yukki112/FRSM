<?php
session_start();
require_once '../../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../login/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query = "SELECT first_name, middle_name, last_name, role FROM users WHERE id = ?";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($user) {
    $first_name = htmlspecialchars($user['first_name']);
    $middle_name = htmlspecialchars($user['middle_name']);
    $last_name = htmlspecialchars($user['last_name']);
    $role = htmlspecialchars($user['role']);
    
    $full_name = $first_name;
    if (!empty($middle_name)) {
        $full_name .= " " . $middle_name;
    }
    $full_name .= " " . $last_name;
} else {
    $full_name = "User";
    $role = "USER";
}

// Pagination setup
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get total count of approved volunteers
$count_query = "SELECT COUNT(*) as total FROM volunteers WHERE status = 'approved'";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute();
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get approved volunteers with pagination
$volunteers_query = "SELECT v.*, u.unit_name, u.unit_code, u.id as unit_id
                     FROM volunteers v 
                     LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id 
                     LEFT JOIN units u ON va.unit_id = u.id 
                     WHERE v.status = 'approved' 
                     ORDER BY v.full_name ASC
                     LIMIT :offset, :records_per_page";
$volunteers_stmt = $pdo->prepare($volunteers_query);
$volunteers_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$volunteers_stmt->bindValue(':records_per_page', $records_per_page, PDO::PARAM_INT);
$volunteers_stmt->execute();
$volunteers = $volunteers_stmt->fetchAll();

// Get all units for assignment
$units_query = "SELECT * FROM units WHERE status = 'Active' ORDER BY unit_name ASC";
$units_stmt = $pdo->prepare($units_query);
$units_stmt->execute();
$units = $units_stmt->fetchAll();

// Get assignment statistics
$stats_query = "SELECT 
                COUNT(*) as total_approved,
                COUNT(va.id) as total_assigned,
                (SELECT COUNT(*) FROM units WHERE status = 'Active') as total_units
                FROM volunteers v
                LEFT JOIN volunteer_assignments va ON v.id = va.volunteer_id
                WHERE v.status = 'approved'";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Applications & Assign Units - Fire & Rescue Services</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="icon" type="image/png" sizes="32x32" href="../../img/frsm-logo.png">
    <link rel="stylesheet" href="../../css/dashboard.css">
    <style>
        
        /* Add AI button styling */
        .action-button.ai-button {
            background-color: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .action-button.ai-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .action-button.ai-button i {
            font-size: 16px;
        }

        /* AI Recommendation Modal */
        .ai-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .ai-modal.active {
            display: flex;
        }

        .ai-modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .ai-modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .ai-modal-header h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ai-modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .ai-modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .ai-modal-body {
            padding: 20px;
        }

        .ai-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .ai-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #e5e7eb;
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .ai-loading-text {
            margin-top: 15px;
            color: #6b7280;
            font-weight: 500;
        }

        .ai-volunteer-info {
            background: #f3f4f6;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }

        .ai-volunteer-name {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
        }

        .ai-volunteer-skills {
            font-size: 13px;
            color: #6b7280;
            margin-top: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .ai-skill-badge {
            background: white;
            padding: 4px 10px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            font-size: 12px;
        }

        .ai-recommendations {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .ai-recommendation-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .ai-recommendation-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }

        .ai-recommendation-card.selected {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .ai-recommendation-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }

        .ai-unit-name {
            font-size: 15px;
            font-weight: 600;
            color: #1f2937;
        }

        .ai-match-score {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .ai-recommendation-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .ai-detail-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #6b7280;
        }

        .ai-detail-item i {
            color: #667eea;
            font-size: 14px;
        }

        .ai-matched-skills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
        }

        .ai-matched-skill-tag {
            background: #dcfce7;
            color: #166534;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .ai-no-match {
            text-align: center;
            padding: 30px 20px;
            color: #6b7280;
        }

        .ai-error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .ai-modal-footer {
            padding: 15px 20px;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            border-radius: 0 0 12px 12px;
        }

        .ai-button-assign {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .ai-button-assign:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .ai-button-assign:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .ai-button-cancel {
            background: #e5e7eb;
            color: #1f2937;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .ai-button-cancel:hover {
            background: #d1d5db;
        }
    </style>
</head>
<body>
    <!-- ... existing dashboard structure ... -->
    
    <!-- Add AI Recommendation Modal -->
    <div class="ai-modal" id="ai-modal">
        <div class="ai-modal-content">
            <div class="ai-modal-header">
                <h2>
                    <i class='bx bx-sparkles'></i>
                    AI Unit Recommendation
                </h2>
                <button class="ai-modal-close" id="ai-modal-close">&times;</button>
            </div>
            <div class="ai-modal-body">
                <div id="ai-loading" class="ai-loading" style="display: none;">
                    <div class="ai-spinner"></div>
                    <div class="ai-loading-text">Analyzing skills...</div>
                </div>
                
                <div id="ai-content" style="display: none;">
                    <div id="ai-volunteer-info" class="ai-volunteer-info"></div>
                    <div id="ai-error" class="ai-error" style="display: none;"></div>
                    <div id="ai-recommendations" class="ai-recommendations"></div>
                </div>
            </div>
            <div class="ai-modal-footer">
                <button class="ai-button-cancel" id="ai-button-close">Close</button>
                <button class="ai-button-assign" id="ai-button-assign" disabled>Assign Selected Unit</button>
            </div>
        </div>
    </div>
    
    <!-- JavaScript for AI Recommendation -->
    <script>
        let selectedUnitId = null;
        let selectedVolunteerId = null;

        // Open AI Recommendation Modal
        function getAIRecommendation(volunteerId, volunteerName) {
            selectedVolunteerId = volunteerId;
            selectedUnitId = null;
            
            const modal = document.getElementById('ai-modal');
            const loading = document.getElementById('ai-loading');
            const content = document.getElementById('ai-content');
            
            modal.classList.add('active');
            loading.style.display = 'flex';
            content.style.display = 'none';
            
            // Fetch recommendations from API
            fetch('get_ai_recommendation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'volunteer_id=' + volunteerId
            })
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';
                content.style.display = 'block';
                
                if (data.success) {
                    displayAIRecommendations(data.recommendation, data.volunteer);
                } else {
                    showAIError(data.message);
                }
            })
            .catch(error => {
                loading.style.display = 'none';
                content.style.display = 'block';
                console.error('Error:', error);
                showAIError('Failed to get recommendations: ' + error.message);
            });
        }

        // Display AI Recommendations
        function displayAIRecommendations(recommendations, volunteerInfo) {
            const volunteerInfo_div = document.getElementById('ai-volunteer-info');
            const recommendationsDiv = document.getElementById('ai-recommendations');
            
            // Show volunteer info
            const skillsHtml = recommendations[0]?.matchedSkills.length > 0 
                ? '<div class="ai-volunteer-skills">' +
                  recommendations.flatMap(r => r.matchedSkills).filter((v, i, a) => a.indexOf(v) === i)
                    .map(skill => `<span class="ai-skill-badge">${skill}</span>`).join('') +
                  '</div>'
                : '<div class="ai-volunteer-skills"><span class="ai-skill-badge">No matched skills</span></div>';
            
            volunteerInfo_div.innerHTML = `
                <div class="ai-volunteer-name">${volunteerInfo.name}</div>
                ${skillsHtml}
            `;
            
            // Show recommendations
            if (recommendations.length > 0) {
                recommendationsDiv.innerHTML = recommendations.map((rec, index) => `
                    <div class="ai-recommendation-card" onclick="selectUnit(${rec.unit_id}, this)">
                        <div class="ai-recommendation-header">
                            <div>
                                <div class="ai-unit-name">${rec.unit_name}</div>
                                <div style="font-size: 12px; color: #9ca3af;">${rec.unit_code} • ${rec.unit_type}</div>
                            </div>
                            <span class="ai-match-score">${rec.score}% Match</span>
                        </div>
                        <div class="ai-recommendation-details">
                            <div class="ai-detail-item">
                                <i class='bx bx-map'></i>
                                <span>${rec.location}</span>
                            </div>
                            <div class="ai-detail-item">
                                <i class='bx bx-building'></i>
                                <span>${rec.current_count}/${rec.capacity} Members</span>
                            </div>
                        </div>
                        <div class="ai-matched-skills">
                            ${rec.matchedSkills.map(skill => `<span class="ai-matched-skill-tag">✓ ${skill}</span>`).join('')}
                        </div>
                    </div>
                `).join('');
            } else {
                recommendationsDiv.innerHTML = '<div class="ai-no-match">No suitable units found based on volunteer skills.</div>';
            }
            
            document.getElementById('ai-error').style.display = 'none';
        }

        // Select a unit from recommendations
        function selectUnit(unitId, element) {
            selectedUnitId = unitId;
            
            // Remove previous selection
            document.querySelectorAll('.ai-recommendation-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selection to clicked card
            element.classList.add('selected');
            
            // Enable assign button
            document.getElementById('ai-button-assign').disabled = false;
        }

        // Show error message
        function showAIError(message) {
            document.getElementById('ai-error').textContent = message;
            document.getElementById('ai-error').style.display = 'block';
            document.getElementById('ai-recommendations').innerHTML = '';
        }

        // Assign selected unit
        document.getElementById('ai-button-assign').addEventListener('click', function() {
            if (selectedUnitId && selectedVolunteerId) {
                // Show password confirmation modal
                showPasswordModal('assign', selectedVolunteerId, selectedUnitId);
                // Close AI modal
                closeAIModal();
            }
        });

        // Close AI Modal
        function closeAIModal() {
            document.getElementById('ai-modal').classList.remove('active');
            selectedUnitId = null;
            selectedVolunteerId = null;
        }

        document.getElementById('ai-modal-close').addEventListener('click', closeAIModal);
        document.getElementById('ai-button-close').addEventListener('click', closeAIModal);

        // Close modal when clicking outside
        document.getElementById('ai-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAIModal();
            }
        });
    </script>
</body>
</html>
