// Project: Nano Empire Core JavaScript Logic
// Contains global variables, API wrapper, and core authentication/view switching.

const API_URL = 'api.php';
let gameState = {};
let activeTimers = [];

/**
 * Executes an asynchronous API POST call.
 * @param {object} data - The payload to send (must include 'action').
 * @param {function} callback - Function to execute on successful JSON response.
 */
function apiCall(data, callback) {
    $.post(API_URL, data, function(response) {
        if (response.auth_error) {
            showCustomAlert("Session Expired: Please log in again.", showLoginScreen);
        } else {
            callback(response);
        }
    }, 'json').fail(function(jqXHR) {
        console.error("API Call Failed:", jqXHR.responseText);
        showError("Error communicating with the server. Check console for details.");
    });
}

/**
 * Displays persistent error message in the auth area.
 * @param {string} message 
 */
function showError(message) {
    $('#error-message').text(message).show();
}

/**
 * Clears all active countdown timers (tasks/protection).
 */
function clearAllTimers() {
    activeTimers.forEach(timerId => clearInterval(timerId));
    activeTimers = [];
}

/**
 * Formats seconds into HH:MM:SS duration string.
 * @param {number} totalSeconds 
 * @returns {string}
 */
function formatDuration(totalSeconds) {
    if (isNaN(totalSeconds) || totalSeconds < 0) return "00:00:00";
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return `${String(hours).padStart(2,'0')}:${String(minutes).padStart(2,'0')}:${String(seconds).padStart(2,'0')}`;
}

// --- Custom Modal Implementation (Replaces alert/confirm) ---

/**
 * Displays a custom alert modal.
 * @param {string} message 
 * @param {function} [callback]
 */
function showCustomAlert(message, callback) {
    $('#custom-modal-body').text(message);
    $('#modal-confirm-btn').hide();
    $('#modal-close-btn').show().off('click').on('click', function() {
        var modal = bootstrap.Modal.getInstance(document.getElementById('custom-modal'));
        if (modal) modal.hide();
        if (callback) callback();
    });
    var modal = new bootstrap.Modal(document.getElementById('custom-modal'));
    modal.show();
}

/**
 * Displays a custom confirmation modal.
 * @param {string} message 
 * @param {function} confirmCallback - Function to call with true/false based on user choice.
 */
function showCustomConfirm(message, confirmCallback) {
    $('#custom-modal-body').text(message);
    $('#modal-close-btn').text('Cancel');
    $('#modal-confirm-btn').show().off('click').on('click', function() {
        var modal = bootstrap.Modal.getInstance(document.getElementById('custom-modal'));
        if (modal) modal.hide();
        confirmCallback(true);
        $('#modal-close-btn').text('Close');
    });
    $('#modal-close-btn').off('click').on('click', function() {
        var modal = bootstrap.Modal.getInstance(document.getElementById('custom-modal'));
        if (modal) modal.hide();
        confirmCallback(false);
        $('#modal-close-btn').text('Close');
    });
    var modal = new bootstrap.Modal(document.getElementById('custom-modal'));
    modal.show();
}
// --- End Custom Modal Implementation ---

/**
 * Hides the game and shows the login/register forms.
 */
function showLoginScreen() {
    $('#auth-container').show();
    $('#game-container').hide();
    $('#error-message').hide();
}

/**
 * Fetches the latest game state from the server and triggers UI updates.
 */
function fetchAndUpdateGameState() {
    apiCall({ action: 'get_user_data' }, function(response) {
        if (response.success) {
            gameState = response.data;
            updateUI();
        }
    });
}

/**
 * Checks if the user is currently logged in via API call.
 */
function checkLoginStatus() {
    apiCall({ action: 'get_user_data' }, function(response) {
        if (response.success) {
            $('#username-display').text(response.data.username);
            $('#auth-container').hide();
            $('#game-container').show();
            showView('home-view');
        } else {
            showLoginScreen();
        }
    });
}

/**
 * Handles user logout.
 */
function logout() {
    apiCall({ action: 'logout' }, function() {
        showLoginScreen();
    });
}

/**
 * Switches the displayed game view and reloads necessary data.
 * @param {string} viewId 
 */
function showView(viewId) {
    clearAllTimers();
    $('.view').removeClass('active-view');
    $('#' + viewId).addClass('active-view');

    switch (viewId) {
        case 'home-view':
        case 'workers-view':
        case 'buildings-view':
        case 'army-view':
            fetchAndUpdateGameState();
            break;
        case 'market-view':
            updateMarketView();
            break;
        case 'history-view':
            updateHistoryView();
            break;
        case 'jobs-view':
            updateJobsView();
            break;
        case 'tax-collection-view':
            updateTaxCollectionView();
            break;
        case 'distribution-view':
            updateDistributionView();
            break;
        case 'distribution-history-view':
            updateDistributionHistoryView();
            break;
        case 'leaderboard-view':
            updateLeaderboardView();
            break;
        case 'harvesting-activity-view':
            updateHarvestingActivityView();
            break;
        case 'conquer-view':
            updateConquerView();
            break;
    }
}
