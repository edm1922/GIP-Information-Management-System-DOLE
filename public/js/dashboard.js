function updateYear(year) {
    if (!year) return;
    const form = document.getElementById('yearFilterForm');
    // Ensure there's a hidden year input if we're calling this from somewhere else
    let yearInput = form.querySelector('input[name="year"]');
    if (!yearInput) {
        yearInput = document.createElement('input');
        yearInput.type = 'hidden';
        yearInput.name = 'year';
        form.appendChild(yearInput);
    }
    yearInput.value = year;
    form.submit();
}

document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.dashboardData === 'undefined') return;

    const data = window.dashboardData;

    // --- Hiring Trends Line Chart ---
    const hiringCtx = document.getElementById('hiringChart');
    if (hiringCtx) {
        const ctx = hiringCtx.getContext('2d');
        const rawData = data.hiringTrends;
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        let labels = [];
        let counts = [];

        if (data.month) {
            const daysInMonth = data.daysInMonth;
            labels = Array.from({ length: daysInMonth }, (_, i) => (i + 1).toString());
            counts = new Array(daysInMonth).fill(0);
            rawData.forEach(item => {
                if (item.day) {
                    counts[item.day - 1] = item.count;
                }
            });
        } else {
            labels = months;
            counts = new Array(12).fill(0);
            rawData.forEach(item => {
                counts[item.month - 1] = item.count;
            });
        }

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'New Hires',
                    data: counts,
                    borderColor: '#1E429B',
                    backgroundColor: 'rgba(30, 66, 155, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#1E429B',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, color: '#9ca3af', font: { size: 11 } },
                        grid: { borderDash: [5, 5], color: '#e5e7eb' }
                    },
                    x: {
                        ticks: { color: '#9ca3af', font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // --- Company Distribution Pie Chart ---
    const companyCtxElem = document.getElementById('companyChart');
    if (companyCtxElem) {
        const companyCtx = companyCtxElem.getContext('2d');
        const companyData = data.companyDistribution;

        // Store the original data for filtering
        const originalLabels = companyData.map(item => item.name);
        const originalCounts = companyData.map(item => item.count);
        const totalCount = originalCounts.reduce((a, b) => a + b, 0);

        const colors = [
            '#1E429B', '#2563eb', '#10b981', '#f59e0b', '#8b5cf6',
            '#ec4899', '#06b6d4', '#4b5563', '#10b981', '#f97316'
        ];

        const companyChart = new Chart(companyCtx, {
            type: 'doughnut',
            data: {
                labels: originalLabels,
                datasets: [{
                    data: originalCounts,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '80%', // Higher cutout for more "thin" ring look
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        padding: 12,
                        cornerRadius: 8
                    }
                }
            }
        });

        const updateLegend = (dataLabels, dataCounts) => {
            const legendContainer = document.getElementById('companyLegend');
            if (legendContainer) {
                let legendHtml = '<div class="legend-list">';
                dataLabels.forEach((label, index) => {
                    const count = dataCounts[index];
                    const colorIndex = originalLabels.indexOf(label);
                    const color = colors[colorIndex % colors.length];
                    const percentage = totalCount > 0 ? ((count / totalCount) * 100).toFixed(0) : 0;

                    legendHtml += `
                        <div class="legend-item-row">
                            <div class="legend-item-left">
                                <span class="legend-ring" style="border-color: ${color}"></span>
                                <span class="legend-label-name" title="${label}">${label}</span>
                            </div>
                            <div class="legend-item-right">
                                <span class="legend-count">${count}</span>
                            </div>
                        </div>`;
                });
                legendHtml += '</div>';
                legendContainer.innerHTML = legendHtml;
            }
        };

        const updateCenterText = (value) => {
            const formattedValue = value.toLocaleString();

            // Update chart center
            const centerValueElem = document.getElementById('totalEmployeeCount');
            if (centerValueElem) {
                centerValueElem.innerText = formattedValue;
            }

            // Update main stat card at the top
            const mainTotalElem = document.getElementById('mainTotalEmployees');
            if (mainTotalElem) {
                mainTotalElem.innerText = formattedValue;
            }
        };

        // Populate the filter dropdown with items
        const dropdownMenu = document.getElementById('companyFilterOptions');
        if (dropdownMenu) {
            originalLabels.forEach((label, index) => {
                const color = colors[index % colors.length];
                const item = document.createElement('div');
                item.className = 'dropdown-item-custom';
                item.innerHTML = `
                    <span class="legend-dot" style="background-color: ${color}"></span>
                    <span>${label}</span>
                `;
                item.onclick = () => {
                    // Update Alpine data manually since this is injected (v3 compatible)
                    const dropdownEl = dropdownMenu.closest('[x-data]');
                    if (dropdownEl && window.Alpine) {
                        try {
                            const xData = Alpine.$data(dropdownEl);
                            xData.selected = label;
                            xData.selectedColor = color;
                            xData.open = false;
                        } catch (e) {
                            console.error('Failed to update Alpine state:', e);
                        }
                    }
                    filterCompanyChart(label);
                };
                dropdownMenu.appendChild(item);
            });
        }

        // Global filter function
        window.filterCompanyChart = function (companyName) {
            if (companyName === 'All') {
                companyChart.data.labels = originalLabels;
                companyChart.data.datasets[0].data = originalCounts;
                companyChart.data.datasets[0].backgroundColor = colors;
                updateCenterText(totalCount);
                updateLegend(originalLabels, originalCounts);
            } else {
                const index = originalLabels.indexOf(companyName);
                if (index !== -1) {
                    companyChart.data.labels = [companyName];
                    companyChart.data.datasets[0].data = [originalCounts[index]];
                    companyChart.data.datasets[0].backgroundColor = [colors[index % colors.length]];
                    updateCenterText(originalCounts[index]);
                    updateLegend([companyName], [originalCounts[index]]);
                }
            }
            companyChart.update();
        };

        // Initial legend render
        updateLegend(originalLabels, originalCounts);
    }

    // --- Bank Distribution Column Chart ---
    const bankCtxElem = document.getElementById('bankChart');
    if (bankCtxElem) {
        const bankCtx = bankCtxElem.getContext('2d');
        const bankData = data.bankDistribution;

        new Chart(bankCtx, {
            type: 'bar',
            data: {
                labels: bankData.map(item => item.name || 'Unknown'),
                datasets: [{
                    label: 'Employees per Bank',
                    data: bankData.map(item => item.count),
                    backgroundColor: '#1E429B',
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        padding: 12,
                        cornerRadius: 8
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, color: '#9ca3af', font: { size: 11 } },
                        grid: { borderDash: [5, 5], color: '#e5e7eb' }
                    },
                    x: {
                        ticks: { color: '#9ca3af', font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // --- Interns Onboarded Line Chart ---
    const internsCtxElem = document.getElementById('internsChart');
    if (internsCtxElem) {
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const counts = new Array(12).fill(0);
        (data.internsTrend || []).forEach(item => {
            counts[item.month - 1] = item.count;
        });

        new Chart(internsCtxElem.getContext('2d'), {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'Interns Onboarded',
                    data: counts,
                    borderColor: '#1E429B',
                    backgroundColor: 'rgba(30, 66, 155, 0.1)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#1E429B',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, color: '#9ca3af', font: { size: 11 } },
                        grid: { borderDash: [5, 5], color: '#e5e7eb' }
                    },
                    x: {
                        ticks: { color: '#9ca3af', font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // --- Intern Status Doughnut ---
    const internStatusCtxElem = document.getElementById('internStatusChart');
    if (internStatusCtxElem) {
        const statusRows = data.internStatusDistribution || [];
        const statusColors = {
            active: '#10b981',
            inactive: '#9ca3af',
            completed: '#2563eb'
        };

        new Chart(internStatusCtxElem.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: statusRows.map(item => item.status.charAt(0).toUpperCase() + item.status.slice(1)),
                datasets: [{
                    data: statusRows.map(item => item.count),
                    backgroundColor: statusRows.map(item => statusColors[item.status] || '#6b7280'),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, font: { size: 11 } }
                    },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        padding: 12,
                        cornerRadius: 8
                    }
                }
            }
        });
    }

    // --- Partner Agency Distribution Pie Chart ---
    const partnerCtxElem = document.getElementById('partnerAgencyChart');
    if (partnerCtxElem) {
        const agencyRows = data.partnerAgencyDistribution || [];
        const agencyColors = [
            '#1E429B', '#2563eb', '#10b981', '#f59e0b', '#8b5cf6',
            '#ec4899', '#06b6d4', '#4b5563', '#10b981', '#f97316'
        ];

        new Chart(partnerCtxElem.getContext('2d'), {
            type: 'pie',
            data: {
                labels: agencyRows.map(item => item.name),
                datasets: [{
                    data: agencyRows.map(item => item.count),
                    backgroundColor: agencyRows.map((_, index) => agencyColors[index % agencyColors.length]),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, font: { size: 11 } }
                    },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        padding: 12,
                        cornerRadius: 8
                    }
                }
            }
        });
    }
});
