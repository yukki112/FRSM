<?php
session_start();
require_once '../config/db_connection.php';
require_once('../vendor/setasign/fpdf/fpdf.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header("Location: ../login.php");
    exit();
}

if (isset($_POST['generate_report'])) {
    // Fetch data for report
    $query = "SELECT 
        (SELECT COUNT(*) FROM api_incidents WHERE DATE(created_at_local) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as incidents_30days,
        (SELECT COUNT(*) FROM volunteers WHERE status = 'approved') as active_volunteers,
        (SELECT COUNT(*) FROM resources WHERE condition_status = 'Serviceable') as operational_resources,
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM trainings WHERE status = 'completed') as completed_trainings,
        (SELECT COUNT(*) FROM inspection_reports WHERE status = 'approved') as inspections_completed";
    
    $stmt = $pdo->query($query);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fetch recent incidents
    $query = "SELECT title, location, status, created_at_local 
              FROM api_incidents 
              ORDER BY created_at_local DESC 
              LIMIT 10";
    $stmt = $pdo->query($query);
    $recentIncidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create PDF
    $pdf = new FPDF();
    $pdf->AddPage();
    
    // Header
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Fire & Rescue Services Management System Report', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Generated on ' . date('F j, Y H:i:s'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // System Overview
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'System Overview', 0, 1);
    $pdf->SetFont('Arial', '', 12);
    
    $pdf->Cell(100, 8, 'Total Incidents (Last 30 Days):', 0, 0);
    $pdf->Cell(0, 8, $stats['incidents_30days'], 0, 1);
    
    $pdf->Cell(100, 8, 'Active Volunteers:', 0, 0);
    $pdf->Cell(0, 8, $stats['active_volunteers'], 0, 1);
    
    $pdf->Cell(100, 8, 'Operational Resources:', 0, 0);
    $pdf->Cell(0, 8, $stats['operational_resources'], 0, 1);
    
    $pdf->Cell(100, 8, 'Total System Users:', 0, 0);
    $pdf->Cell(0, 8, $stats['total_users'], 0, 1);
    
    $pdf->Cell(100, 8, 'Completed Trainings:', 0, 0);
    $pdf->Cell(0, 8, $stats['completed_trainings'], 0, 1);
    
    $pdf->Cell(100, 8, 'Completed Inspections:', 0, 0);
    $pdf->Cell(0, 8, $stats['inspections_completed'], 0, 1);
    
    $pdf->Ln(10);
    
    // Recent Incidents
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Recent Incidents', 0, 1);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(80, 8, 'Incident', 1);
    $pdf->Cell(50, 8, 'Location', 1);
    $pdf->Cell(30, 8, 'Status', 1);
    $pdf->Cell(30, 8, 'Date', 1);
    $pdf->Ln();
    
    $pdf->SetFont('Arial', '', 10);
    foreach ($recentIncidents as $incident) {
        $pdf->Cell(80, 8, substr($incident['title'], 0, 30), 1);
        $pdf->Cell(50, 8, substr($incident['location'], 0, 20), 1);
        $pdf->Cell(30, 8, $incident['status'], 1);
        $pdf->Cell(30, 8, date('m/d/Y', strtotime($incident['created_at_local'])), 1);
        $pdf->Ln();
    }
    
    // Footer
    $pdf->SetY(-15);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(0, 10, 'Page ' . $pdf->PageNo(), 0, 0, 'C');
    
    // Output PDF
    $pdf->Output('D', 'FRSM_Report_' . date('Y-m-d') . '.pdf');
    exit();
}
?>