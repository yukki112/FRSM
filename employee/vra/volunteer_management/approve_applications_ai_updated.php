<?php
// This file shows the modifications needed to add AI button and modal to approve_applications.php
// Add this in the action-buttons section of the table for each volunteer row

// IN THE TABLE ROW, after the View button, add this AI button:
?>
<!-- Add AI Suggest button for AI-powered unit recommendation -->
<button class="action-button ai-button" onclick="getAIRecommendation(<?php echo $volunteer['id']; ?>, '<?php echo htmlspecialchars($volunteer['full_name']); ?>')">
    <i class='bx bx-sparkles'></i>
    AI Suggest
</button>
