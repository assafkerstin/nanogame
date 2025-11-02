// Project: Nano Empire UI Rendering Functions
// Contains all logic to update the DOM based on the current gameState.

/**
 * Main function to update all dynamic UI elements across the application.
 */
function updateUI() {
    updateDashboard();
    updateWorkersView();
    updateBuildingsView();
    updateArmyView();
}

/**
 * Generates the HTML string detailing resource productivity based on game state object.
 * @param {string} resourceName 
 * @returns {string} Formatted HTML.
 */
function renderProductivityDetails(resourceName) {
    if (!gameState.productivity || !gameState.productivity[resourceName]) {
        return '<p>Loading productivity data...</p>';
    }
    const p = gameState.productivity[resourceName];
    return `<div class="mb-2">
        <strong>${resourceName}:</strong> 
        <p class="mb-0 small">So far you have harvested ${resourceName.toLowerCase()} <strong>${p.actions}</strong> times. Your productivity bonus is <strong>${p.current_bonus_perc}%</strong>. When you reach a total of <strong>${p.actions_for_next_level}</strong> harvesting actions, your productivity bonus will increase to <strong>${p.next_level_bonus_perc}%</strong>.</p>
    </div>`;
}

/**
 * Updates the Home View (Dashboard) with resources, production summary, and building status.
 */
function updateDashboard() {
    if (!gameState || Object.keys(gameState).length === 0) return;
    
    // Update Resource Balances
    $('#earned-nano-balance').text(parseFloat(gameState.nano_earned_balance || 0).toFixed(8));
    $('#unearned-nano-balance').text(parseFloat(gameState.nano_unearned_balance || 0).toFixed(8));
    $('#wood-balance').text(parseFloat(gameState.wood || 0).toFixed(8));
    $('#iron-balance').text(parseFloat(gameState.iron || 0).toFixed(8));
    $('#stone-balance').text(parseFloat(gameState.stone || 0).toFixed(8));
    $('#military-swords').text(Math.floor(gameState.sword || 0));
    $('#soldiers-count').text(gameState.soldiers || 0);
    
    // Update Military Power Breakdown
    const powerBreakdown = gameState.military_power_breakdown || { total: 0, base: 0, bonus_perc: 0 };
    $('#military-power').text(parseFloat(powerBreakdown.total).toFixed(8));
    $('#military-power-breakdown').html(`Base: ${parseFloat(powerBreakdown.base).toFixed(8)} &times; ${parseFloat(1 + powerBreakdown.bonus_perc / 100).toFixed(2)} (Bonus: ${powerBreakdown.bonus_perc}%)`);

    // Update Production Summary
    $('#total-actions').text(gameState.prod_summary.total_actions || 0);
    $('#total-harvested').text(parseFloat(gameState.prod_summary.total_harvested || 0).toFixed(8));
    $('#total-swords-produced').text(gameState.prod_summary.total_swords_produced || 0);
    
    // Update 24-Hour Activity
    $('#all-users-actions-24h').text(gameState.activity_24h.all_users_actions || 0);
    const userShare = gameState.activity_24h.user_share || 0;
    $('#user-share-24h').text(userShare.toFixed(2) + '%');

    // Update Building Summary
    let buildingSummaryHtml = '<ul class="list-group list-group-flush">';
    for (const type in gameState.buildings) {
        const level = gameState.buildings[type];
        const cost = Math.pow(2, level); 
        const hasResources = (parseFloat(gameState.wood) >= cost && parseFloat(gameState.iron) >= cost && parseFloat(gameState.stone) >= cost);
        const buttonHtml = hasResources 
            ? `<button class="btn btn-sm btn-primary upgrade-building-btn" data-building-type="${type}">Upgrade</button>`
            : `<small class="text-danger">Not enough resources to upgrade</small>`;

        let bonusText = '';
        let nextBonus = '';
        if (type === 'Dormitory' && gameState.productivity['Wood']) {
            bonusText = `<div class="small text-muted">Current Prod. Bonus: +${gameState.productivity['Wood'].building_bonus_perc}%</div>`;
            nextBonus = `<div class="small text-info">Next Level Bonus: +${parseInt(gameState.productivity['Wood'].building_bonus_perc) + 1}%</div>`;
        } else if (type === 'Barracks' && gameState.military_power_breakdown) {
            bonusText = `<div class="small text-muted">Current Army Bonus: +${gameState.military_power_breakdown.bonus_perc}%</div>`;
            nextBonus = `<div class="small text-info">Next Level Bonus: +${parseInt(gameState.military_power_breakdown.bonus_perc) + 1}%</div>`;
        }

        buildingSummaryHtml += `<li class="list-group-item">
            <div class="d-flex justify-content-between align-items-center">
                <span><strong>${type}</strong> - Lvl ${level}</span>
                ${buttonHtml}
            </div>
            <div class="small text-muted">Cost to upgrade: ${cost} Wood, ${cost} Iron, ${cost} Stone</div>
            ${bonusText}
            ${nextBonus}
        </li>`;
    }
    buildingSummaryHtml += '</ul>';
    $('#dashboard-buildings-summary').html(buildingSummaryHtml);

    // Update Productivity Details
    let productivityHtml = renderProductivityDetails('Wood') + renderProductivityDetails('Iron') + renderProductivityDetails('Stone');
    $('#dashboard-productivity-stats').html(productivityHtml);

    // Update Worker Status Preview
    $('#dashboard-worker-status').empty();
    for (let i = 1; i <= gameState.workers; i++) {
        const task = gameState.worker_tasks.find(t => t.worker_id == i);
        let statusHtml = task ? `<div class="col-md-4 mb-2">Worker ${i}: ${task.task_type} - <span class="countdown-timer" data-completion="${task.completion_time}"></span></div>` : `<div class="col-md-4 mb-2">Worker ${i}: Idle</div>`;
        $('#dashboard-worker-status').append(statusHtml);
    }
    startAllCountdownTimers();
}

/**
 * Updates the Workers View with individual worker cards and action buttons.
 */
function updateWorkersView() {
    if (!$('#workers-view').hasClass('active-view') || !gameState.productivity) return;
    $('#worker-list').empty();
    
    // Display global productivity stats card at the top
    const productivityDetailsHtml = `
        <div class='card mb-3'><div class='card-body'>
            ${renderProductivityDetails('Wood')}
            ${renderProductivityDetails('Iron')}
            ${renderProductivityDetails('Stone')}
        </div></div>
    `;

    for (let i = 1; i <= gameState.workers; i++) {
        const task = gameState.worker_tasks.find(t => t.worker_id == i);
        let workerCard;
        if (task) {
            workerCard = `<div class="col-md-4"><div class="card"><div class="card-header">Worker ${i} (Busy)</div><div class="card-body"><p><strong>Task:</strong> ${task.task_type}</p><p><strong>Time left:</strong> <span class="countdown-timer" data-completion="${task.completion_time}"></span></p></div></div></div>`;
        } else {
            workerCard = `<div class="col-md-4"><div class="card"><div class="card-header">Worker ${i} (Free)</div><div class="card-body">
                <p class="text-muted small mb-1">Time: ${formatDuration(gameState.task_duration_seconds)}</p>
                <button class="btn btn-sm btn-outline-success w-100 mb-2 start-task-btn" data-worker-id="${i}" data-task-type="Harvest Wood">Harvest Wood</button>
                <button class="btn btn-sm btn-outline-secondary w-100 mb-2 start-task-btn" data-worker-id="${i}" data-task-type="Harvest Iron">Harvest Iron</button>
                <button class="btn btn-sm btn-outline-dark w-100 start-task-btn" data-worker-id="${i}" data-task-type="Harvest Stone">Harvest Stone</button>
            </div></div></div>`;
        }
        $('#worker-list').append(workerCard);
    }
    $('#worker-list').prepend(`<div class="col-12">${productivityDetailsHtml}</div>`);
    startAllCountdownTimers();
}

/**
 * Updates the Buildings View with detailed upgrade costs and current bonuses.
 */
function updateBuildingsView() {
    if (!$('#buildings-view').hasClass('active-view') || !gameState.buildings) return;
    $('#building-list').empty();
    for (const type in gameState.buildings) {
        const level = gameState.buildings[type];
        const cost = Math.pow(2, level);
        const nextLevel = parseInt(level) + 1;
        const hasResources = ((parseFloat(gameState.wood) || 0) >= cost && (parseFloat(gameState.iron) || 0) >= cost && (parseFloat(gameState.stone) || 0) >= cost);
        const buttonHtml = hasResources 
            ? `<button class="btn btn-primary w-100 upgrade-building-btn" data-building-type="${type}">Upgrade to Level ${nextLevel}</button>`
            : `<p class="text-danger mb-0">Not enough resources to upgrade</p>`;
        
        let currentBonus = '';
        let nextBonus = '';

        if (type === 'Dormitory' && gameState.productivity['Wood']) {
            currentBonus = `Current Prod. Bonus: +${gameState.productivity['Wood'].building_bonus_perc}%`;
            nextBonus = `Next Level Bonus: +${parseInt(gameState.productivity['Wood'].building_bonus_perc) + 1}%`;
        } else if (type === 'Barracks' && gameState.military_power_breakdown) {
            currentBonus = `Current Army Bonus: +${gameState.military_power_breakdown.bonus_perc}%`;
            nextBonus = `Next Level Bonus: +${parseInt(gameState.military_power_breakdown.bonus_perc) + 1}%`;
        }

        const buildingCard = `<div class="col-md-4"><div class="card"><div class="card-header">${type} - Level ${level}</div>
            <div class="card-body">
                <p class="card-text">${currentBonus}</p>
                <p class="card-text"><small class="text-muted">${nextBonus}</small></p>
                <hr>
                <p>Upgrade cost: ${cost} Wood, ${cost} Iron, ${cost} Stone</p>
                ${buttonHtml}
            </div></div></div>`;
        $('#building-list').append(buildingCard);
    }
}

/**
 * Updates the Army View with military power breakdown and dynamic recruitment cost.
 */
function updateArmyView() {
    if (!$('#army-view').hasClass('active-view') || !gameState.wood) return;
    $('#army-soldiers').text(gameState.soldiers || 0);
    $('#army-swords').text(Math.floor(gameState.sword || 0));
    const powerBreakdown = gameState.military_power_breakdown || { total: 0, base: 0, bonus_perc: 0 };
    $('#army-power').text(parseFloat(powerBreakdown.total).toFixed(8));
    $('#army-power-breakdown').html(`Base: ${parseFloat(powerBreakdown.base).toFixed(8)} &times; ${parseFloat(1 + powerBreakdown.bonus_perc / 100).toFixed(2)} (Bonus: ${powerBreakdown.bonus_perc}%)`);
    
    const cost = gameState.soldier_recruit_cost;
    $('#recruit-cost-text').text(`Cost: ${cost.wood.toFixed(4)} Wood, ${cost.iron.toFixed(4)} Iron, ${cost.stone.toFixed(4)} Stone`);
    const hasEnough = ((parseFloat(gameState.wood) || 0) >= cost.wood && (parseFloat(gameState.iron) || 0) >= cost.iron && (parseFloat(gameState.stone) || 0) >= cost.stone);
    $('#recruit-cost-status').text(hasEnough ? '' : 'Not enough resources.');
    $('#recruit-btn').prop('disabled', !hasEnough);
}

/**
 * Updates the Conquer View with power stats, occupied players, and potential targets.
 */
function updateConquerView() {
    if (!$('#conquer-view').hasClass('active-view')) return;
    apiCall({ action: 'get_conquer_data' }, function(response) {
        if (response.success) {
            const data = response.data;
            $('#conquer-total-power').text(parseFloat(data.total_power).toFixed(8));
            $('#conquer-used-power').text(parseFloat(data.used_power).toFixed(8));
            $('#conquer-unused-power').text(parseFloat(data.unused_power).toFixed(8));

            const occupiedList = $('#occupied-list');
            occupiedList.empty();
            if (data.occupied_players.length === 0) {
                occupiedList.append('<tr><td colspan="3" class="text-center">You are not occupying any players.</td></tr>');
            } else {
                data.occupied_players.forEach(p => {
                    occupiedList.append(`
                        <tr>
                            <td>${p.username}</td>
                            <td>${parseFloat(p.army_power).toFixed(8)}</td>
                            <td><button class="btn btn-sm btn-warning liberate-btn" data-target-id="${p.id}">Liberate</button></td>
                        </tr>
                    `);
                });
            }

            const targetsList = $('#targets-list');
            targetsList.empty();
            if (data.potential_targets.length === 0) {
                targetsList.append('<tr><td colspan="3" class="text-center">No available targets.</td></tr>');
            } else {
                data.potential_targets.forEach(p => {
                    let actionHtml = '';
                    if (p.status === 'conquerable') {
                        actionHtml = `<button class="btn btn-sm btn-danger conquer-btn" data-target-id="${p.id}">Conquer</button>`;
                    } else if (p.status === 'protected') {
                        actionHtml = `Protected for: <span class="protection-timer" data-protection-end="${p.protection_end_time}"></span>`;
                    } else {
                        actionHtml = `<span class="text-muted">${p.status}</span>`;
                    }
                    targetsList.append(`
                        <tr>
                            <td>${p.username}</td>
                            <td>${parseFloat(p.army_power).toFixed(8)}</td>
                            <td>${actionHtml}</td>
                        </tr>
                    `);
                });
            }
            startAllCountdownTimers(); // To start protection timers
        } else {
            showError(response.message);
        }
    });
}

/**
 * Updates the Market View with current buy and sell orders.
 */
function updateMarketView() {
    if (!$('#market-view').hasClass('active-view')) return;
    const itemType = $('#market-item-select').val();
    apiCall({ action: 'get_market_data', item_type: itemType }, function(response) {
        if(response.success) {
            const { buys, sells } = response.data;
            $('#buy-orders').empty();
            buys.forEach(o => $('#buy-orders').append(`<tr><td>${parseFloat(o.price).toFixed(8)}</td><td>${o.quantity}</td></tr>`));
            $('#sell-orders').empty();
            sells.forEach(o => $('#sell-orders').append(`<tr><td>${parseFloat(o.price).toFixed(8)}</td><td>${o.quantity}</td></tr>`));
        } else {
            showError(response.message);
        }
    });
}

/**
 * Updates the Job View with open offers and updates local state.
 */
function updateJobsView() {
    if (!$('#jobs-view').hasClass('active-view')) return;
    apiCall({ action: 'get_job_offers' }, function(response) {
        if(response.success) {
            const jobList = $('#job-offers-list');
            jobList.empty();
            if (response.data.length === 0) {
                jobList.append('<tr><td colspan="6" class="text-center">No open jobs available.</td></tr>');
            } else {
                response.data.forEach(job => {
                    jobList.append(`
                        <tr>
                            <td>${job.employer_username}</td>
                            <td>${job.item_to_produce}</td>
                            <td>${job.quantity}</td>
                            <td>${parseFloat(job.salary).toFixed(8)}</td>
                            <td>${formatDuration(gameState.task_duration_seconds)}</td>
                            <td><button class="btn btn-sm btn-success accept-job-btn" data-job-id="${job.id}">Accept</button></td>
                        </tr>
                    `);
                });
            }
        } else {
            showError(response.message);
        }
    });
    fetchAndUpdateGameState();
}

/**
 * Updates the History View with action logs.
 */
function updateHistoryView() {
    if (!$('#history-view').hasClass('active-view')) return;
    apiCall({ action: 'get_history' }, function(response) {
        if(response.success) {
            $('#history-log').empty();
            response.data.forEach(log => {
                const logDate = new Date(log.timestamp.replace(' ', 'T'));
                $('#history-log').append(`<li class="list-group-item">[${logDate.toLocaleString()}] ${log.description}</li>`);
            });
        } else {
            showError(response.message);
        }
    });
}

/**
 * Updates the Tax Collection View with Government balances and tax history.
 */
function updateTaxCollectionView() {
    if (!$('#tax-collection-view').hasClass('active-view')) return;
    apiCall({ action: 'get_government_data' }, function(response) {
        if(response.success) {
            const gov = response.data;
            let html = `
                <div class="col-6 col-md-4 mb-3"><strong>Nano Coin:</strong> ${parseFloat(gov.nano_earned_balance).toFixed(8)}</div>
                <div class="col-6 col-md-4 mb-3"><strong>Game Credits:</strong> ${parseFloat(gov.nano_unearned_balance).toFixed(8)}</div>
                <div class="col-6 col-md-4 mb-3"><strong>Wood:</strong> ${parseFloat(gov.wood).toFixed(8)}</div>
                <div class="col-6 col-md-4 mb-3"><strong>Iron:</strong> ${parseFloat(gov.iron).toFixed(8)}</div>
                <div class="col-6 col-md-4 mb-3"><strong>Stone:</strong> ${parseFloat(gov.stone).toFixed(8)}</div>
            `;
            $('#gov-balances-display').html(html);
        } else {
            showError(response.message);
        }
    });

    apiCall({ action: 'get_tax_history' }, function(response) {
        if(response.success) {
            const taxLog = $('#tax-history-log');
            taxLog.empty();
            if(response.data.length === 0) {
                taxLog.append('<tr><td colspan="3" class="text-center">No tax history found.</td></tr>');
            } else {
                response.data.forEach(log => {
                    const logDate = new Date(log.timestamp.replace(' ', 'T'));
                    taxLog.append(`
                        <tr>
                            <td>${log.username}</td>
                            <td>${log.description}</td>
                            <td>${logDate.toLocaleString()}</td>
                        </tr>
                    `);
                });
            }
        } else {
            showError(response.message);
        }
    });
}

/**
 * Updates the Distribution View with the total 24-hour tax revenue collected.
 */
function updateDistributionView() {
    if (!$('#distribution-view').hasClass('active-view')) return;
    apiCall({ action: 'get_distribution_data' }, function(response) {
        if (response.success) {
            $('#distribution-total').text(parseFloat(response.data.total_tax_24h || 0).toFixed(8));
        } else {
            showError(response.message);
        }
    });
}

/**
 * Updates the Distribution History View.
 */
function updateDistributionHistoryView() {
    if (!$('#distribution-history-view').hasClass('active-view')) return;
    apiCall({ action: 'get_distribution_history' }, function(response) {
        if (response.success) {
            const distLog = $('#distribution-history-log');
            distLog.empty();
            if(response.data.length === 0) {
                distLog.append('<tr><td colspan="4" class="text-center">No distribution history found.</td></tr>');
            } else {
                response.data.forEach(log => {
                    const logDate = new Date(log.timestamp.replace(' ', 'T'));
                    distLog.append(`
                        <tr>
                            <td>${log.username}</td>
                            <td>${parseFloat(log.amount).toFixed(8)} ${log.asset_type}</td>
                            <td>${log.description}</td>
                            <td>${logDate.toLocaleString()}</td>
                        </tr>
                    `);
                });
            }
        } else {
            showError(response.message);
        }
    });
}

/**
 * Updates the Harvesting Activity View based on the selected timeframe.
 */
function updateHarvestingActivityView() {
    if (!$('#harvesting-activity-view').hasClass('active-view')) return;
    const timeframe = $('#harvesting-timeframe-select').val();
    apiCall({ action: 'get_harvesting_activity', timeframe: timeframe }, function(response) {
        if(response.success) {
            const activityBody = $('#harvesting-activity-content');
            activityBody.empty();
            if(response.data.length === 0) {
                activityBody.append('<tr><td colspan="3" class="text-center">No activity found for this period.</td></tr>');
            } else {
                response.data.forEach(player => {
                    activityBody.append(`
                        <tr>
                            <td>${player.rank}</td>
                            <td>${player.username}</td>
                            <td>${player.action_count}</td>
                        </tr>
                    `);
                });
            }
        } else {
            showError(response.message);
        }
    });
}

/**
 * Updates the Leaderboard View based on the selected sort criteria.
 */
function updateLeaderboardView() {
    if (!$('#leaderboard-view').hasClass('active-view')) return;
    const sortBy = $('#leaderboard-sort-select').val();
    const headerText = $('#leaderboard-sort-select option:selected').text();
    
    $('#leaderboard-score-header').text(headerText);

    apiCall({ action: 'get_leaderboard', sort_by: sortBy }, function(response) {
        if(response.success) {
            const leaderboardBody = $('#leaderboard-content');
            leaderboardBody.empty();
            if(response.data.length === 0) {
                leaderboardBody.append('<tr><td colspan="3" class="text-center">Leaderboard is empty.</td></tr>');
            } else {
                response.data.forEach(player => {
                    let score = player.score;
                    let formattedScore = (score % 1 === 0) ? parseFloat(score).toFixed(0) : parseFloat(score).toFixed(8);
                    leaderboardBody.append(`
                        <tr>
                            <td>${player.rank}</td>
                            <td>${player.username}</td>
                            <td>${formattedScore}</td>
                        </tr>
                    `);
                });
            }
        } else {
            showError(response.message);
        }
    });
}
