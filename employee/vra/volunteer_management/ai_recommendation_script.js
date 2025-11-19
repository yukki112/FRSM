/**
 * Get AI Recommendation for a volunteer
 */
function getAIRecommendation(volunteerId, volunteerName) {
    // Show modal with loading state
    const modal = document.getElementById('ai-recommendation-modal');
    const modalBody = document.getElementById('ai-modal-body');
    
    modal.classList.add('active');
    
    // Fetch AI recommendation
    fetch('get_ai_recommendation.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'volunteer_id=' + encodeURIComponent(volunteerId)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayAIRecommendation(data.recommendation, volunteerName);
        } else {
            showNotification('error', 'AI Analysis Failed', data.message || 'Failed to get recommendations');
            modal.classList.remove('active');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'Error', 'Failed to fetch AI recommendations');
        modal.classList.remove('active');
    });
}

/**
 * Display AI recommendations in modal
 */
function displayAIRecommendation(recommendations, volunteerName) {
    const modalBody = document.getElementById('ai-modal-body');
    
    if (!recommendations || recommendations.length === 0) {
        modalBody.innerHTML = `
            <div style="text-align: center; padding: 40px;">
                <i class='bx bx-info-circle' style="font-size: 48px; color: var(--warning); margin-bottom: 16px;"></i>
                <h3>No Matches Found</h3>
                <p style="color: var(--text-light); margin-top: 12px;">
                    No suitable units could be found based on the volunteer's current skill set.<br>
                    Please manually review their profile.
                </p>
            </div>
        `;
        return;
    }
    
    let html = `
        <div style="margin-bottom: 24px;">
            <h3 style="color: var(--primary-color); margin-bottom: 8px;">📊 Analysis for: ${volunteerName}</h3>
            <p style="color: var(--text-light); font-size: 14px;">Based on their registered skills, here are the top recommended units:</p>
        </div>
    `;
    
    // Display each recommendation
    recommendations.forEach((rec, index) => {
        const matchPercentage = rec.score;
        const skillsList = rec.matchedSkills.join(', ');
        const availabilityColor = rec.available_spots > 0 ? '#10b981' : '#f59e0b';
        const availabilityIcon = rec.available_spots > 0 ? '✓' : '⚠️';
        
        html += `
            <div style="
                background: rgba(220, 38, 38, 0.05);
                border: 1px solid rgba(220, 38, 38, 0.2);
                border-radius: 12px;
                padding: 16px;
                margin-bottom: 16px;
            ">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                    <div>
                        <h4 style="margin: 0; color: var(--text-color); font-weight: 700;">
                            #${index + 1}: ${rec.unit_name}
                        </h4>
                        <p style="margin: 4px 0 0 0; color: var(--text-light); font-size: 13px;">
                            Code: ${rec.unit_code} | Type: ${rec.unit_type}
                        </p>
                    </div>
                    <div style="
                        background: linear-gradient(135deg, #10b981, #059669);
                        color: white;
                        padding: 8px 12px;
                        border-radius: 8px;
                        text-align: center;
                        font-weight: 700;
                    ">
                        ${matchPercentage}%<br>
                        <span style="font-size: 11px; font-weight: 500;">MATCH</span>
                    </div>
                </div>
                
                <div style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid rgba(220, 38, 38, 0.1);">
                    <p style="margin: 0 0 8px 0; color: var(--text-light); font-size: 13px; font-weight: 500;">
                        📍 Location: ${rec.location}
                    </p>
                    <p style="margin: 0 0 8px 0; color: var(--text-light); font-size: 13px; font-weight: 500;">
                        📦 Availability: <span style="color: ${availabilityColor}; font-weight: 700;">
                            ${availabilityIcon} ${rec.available_spots}/${rec.capacity} spots
                        </span>
                    </p>
                </div>
                
                <div>
                    <p style="margin: 0 0 8px 0; color: var(--text-color); font-size: 13px; font-weight: 600;">
                        ✓ Matched Skills:
                    </p>
                    <p style="margin: 0; color: var(--text-light); font-size: 13px;">
                        ${skillsList || 'N/A'}
                    </p>
                </div>
                
                ${rec.description ? `
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(220, 38, 38, 0.1);">
                        <p style="margin: 0; color: var(--text-light); font-size: 12px; font-style: italic;">
                            📝 ${rec.description}
                        </p>
                    </div>
                ` : ''}
                
                <button class="action-button assign-button" 
                    style="width: 100%; margin-top: 12px; justify-content: center;"
                    onclick="assignFromAI(this, null, ${rec.unit_id})">
                    <i class='bx bx-user-plus'></i>
                    Assign to ${rec.unit_name}
                </button>
            </div>
        `;
    });
    
    html += `
        <div style="
            background: rgba(59, 130, 246, 0.05);
            border-left: 4px solid #3b82f6;
            padding: 12px;
            border-radius: 8px;
            margin-top: 20px;
        ">
            <p style="margin: 0; color: var(--info); font-size: 13px; font-weight: 500;">
                💡 <strong>AI Tip:</strong> The recommendation is based on the volunteer's registered skills. 
                You can still manually select a different unit if needed.
            </p>
        </div>
    `;
    
    modalBody.innerHTML = html;
}

/**
 * Assign volunteer from AI recommendation
 */
function assignFromAI(button, volunteerId, unitId) {
    // Get volunteer ID from the table row if not provided
    if (!volunteerId) {
        // Find the volunteer ID from nearby table row
        volunteerId = document.getElementById('confirm-volunteer-id')?.value;
    }
    
    if (!volunteerId || !unitId) {
        showNotification('error', 'Error', 'Missing volunteer or unit information');
        return;
    }
    
    button.disabled = true;
    button.innerHTML = '<i class="bx bx-loader-circle bx-spin"></i> Assigning...';
    
    // Show password modal for security
    currentAssignment = {
        volunteerId: volunteerId,
        unitId: unitId,
        action: 'assign'
    };
    
    closeAIModal();
    showPasswordModal('Confirm AI-recommended assignment');
}

/**
 * Close AI recommendation modal
 */
function closeAIModal() {
    document.getElementById('ai-recommendation-modal').classList.remove('active');
}

// Add event listeners for AI modal
document.addEventListener('DOMContentLoaded', function() {
    const aiModalClose = document.getElementById('ai-modal-close');
    const aiModalCloseBtn = document.getElementById('ai-modal-close-btn');
    
    if (aiModalClose) {
        aiModalClose.addEventListener('click', closeAIModal);
    }
    
    if (aiModalCloseBtn) {
        aiModalCloseBtn.addEventListener('click', closeAIModal);
    }
});
