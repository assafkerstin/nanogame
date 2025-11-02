// Project: Nano Empire Event Handlers
// Contains all jQuery event listeners for user input.

$(document).ready(function() {
    
    // --- Authentication Events ---

    $('#login-btn').on('click', function() {
        const username = $('#login-username').val();
        const password = $('#login-password').val();
        apiCall({ action: 'login', username: username, password: password }, function(response) {
            if (response.success) {
                $('#error-message').hide();
                checkLoginStatus();
            } else {
                showError(response.message);
            }
        });
    });

    $('#register-btn').on('click', function() {
        apiCall({ action: 'register', username: $('#register-username').val(), password: $('#register-password').val(), email: $('#register-email').val() }, function(response) {
            if (response.success) {
                showCustomAlert(response.message, function() {
                    $('#show-login-link').click();
                });
            } else {
                showError(response.message);
            }
        });
    });

    $('#logout-btn').on('click', logout);
    $('#show-register-link').on('click', function(e) { 
        e.preventDefault(); 
        $('#login-form, #error-message').hide(); 
        $('#register-form').show(); 
    });
    $('#show-login-link').on('click', function(e) { 
        e.preventDefault(); 
        $('#register-form, #error-message').hide(); 
        $('#login-form').show(); 
    });

    // --- Navigation Events ---

    $('.nav-link').on('click', function(e) {
        e.preventDefault();
        showView($(this).data('view'));
        $('.navbar-collapse').collapse('hide');
    });

    // --- Game Action Events (Worker, Building, Military) ---

    $(document).on('click', '.start-task-btn', function() {
        apiCall({ action: 'start_task', worker_id: $(this).data('worker-id'), task_type: $(this).data('task-type') }, function(response) {
            if (response.success) fetchAndUpdateGameState(); else showCustomAlert(response.message);
        });
    });
    
    $(document).on('click', '.upgrade-building-btn', function() {
        apiCall({ action: 'upgrade_building', building_type: $(this).data('building-type') }, function(response) {
            if (response.success) fetchAndUpdateGameState(); else showCustomAlert(response.message);
        });
    });

    $('#recruit-btn').on('click', function() {
        apiCall({ action: 'recruit_soldiers' }, function(response) {
            showCustomAlert(response.message);
            if (response.success) {
                fetchAndUpdateGameState();
            }
        });
    });

    // --- Conquer Events ---

    $(document).on('click', '.conquer-btn', function() {
        const targetId = $(this).data('target-id');
        showCustomConfirm('Are you sure you want to conquer this player?', function(confirmed) {
            if (confirmed) {
                apiCall({ action: 'conquer_player', target_id: targetId }, function(response) {
                    showCustomAlert(response.message);
                    if (response.success) {
                        updateConquerView();
                    }
                });
            }
        });
    });

    $(document).on('click', '.liberate-btn', function() {
        const targetId = $(this).data('target-id');
        showCustomConfirm('Are you sure you want to liberate this player?', function(confirmed) {
            if (confirmed) {
                apiCall({ action: 'liberate_player', target_id: targetId }, function(response) {
                    showCustomAlert(response.message);
                    if (response.success) {
                        updateConquerView();
                    }
                });
            }
        });
    });

    // --- Market and Job Events ---

    $('#market-item-select').on('change', updateMarketView);
    
    $('#place-order-btn').on('click', function() {
        apiCall({ action: 'place_market_order', item_type: $('#market-item-select').val(), order_type: $('#order-type').val(), quantity: $('#order-quantity').val(), price: $('#order-price').val() }, function(response){
            if (response.success) {
                $('#order-quantity, #order-price').val('');
                updateMarketView();
                fetchAndUpdateGameState();
            } else {
                showCustomAlert(response.message);
            }
        });
    });

    $('#job-quantity, #job-salary').on('input', function() {
        const quantity = parseInt($('#job-quantity').val());
        const salaryPerItem = parseFloat($('#job-salary').val());
        
        // Basic validation and cost calculation (mirrored from backend logic)
        if (isNaN(quantity) || quantity <= 0 || isNaN(salaryPerItem) || salaryPerItem <= 0) {
            $('#job-cost-summary').hide();
            $('#post-job-btn').prop('disabled', true);
            return;
        }
        
        const ironCost = quantity;
        const totalSalary = quantity * salaryPerItem;
        
        $('#job-resource-cost').text(ironCost);
        $('#job-salary-cost').text(totalSalary.toFixed(8));
        $('#job-cost-summary').show();

        const hasEnoughIron = (parseFloat(gameState.iron) || 0) >= ironCost;
        const totalCurrency = (parseFloat(gameState.nano_unearned_balance) || 0) + (parseFloat(gameState.nano_earned_balance) || 0);
        const hasEnoughCurrency = totalCurrency >= totalSalary;

        let statusText = [];
        if (!hasEnoughIron) statusText.push('Not enough iron.');
        if (!hasEnoughCurrency) statusText.push('Not enough currency.');
        
        $('#job-cost-status').text(statusText.join(' '));
        $('#post-job-btn').prop('disabled', !hasEnoughIron || !hasEnoughCurrency);
    });

    $('#post-job-btn').on('click', function() {
        const quantity = $('#job-quantity').val();
        const salary = $('#job-salary').val();
        apiCall({ action: 'post_job_offer', item: 'sword', quantity, salary }, function(response) {
            if(response.success) {
                showCustomAlert(response.message, function() {
                    $('#job-quantity, #job-salary').val('');
                    $('#job-cost-summary').hide();
                    updateJobsView();
                });
            } else {
                showCustomAlert(response.message);
            }
        });
    });

    $(document).on('click', '.accept-job-btn', function() {
        const jobId = $(this).data('job-id');
        showCustomConfirm('Are you sure you want to accept this job? One of your free workers will be assigned.', function(confirmed) {
            if (confirmed) {
                apiCall({ action: 'accept_job', job_id: jobId }, function(response) {
                    showCustomAlert(response.message);
                    if (response.success) {
                        updateJobsView();
                    }
                });
            }
        });
    });

    // --- Leaderboard/Activity Events ---

    $('#leaderboard-sort-select').on('change', updateLeaderboardView);
    $('#harvesting-timeframe-select').on('change', updateHarvestingActivityView);

    // Initial check
    checkLoginStatus();
});
