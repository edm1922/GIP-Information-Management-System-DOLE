/**
 * Reviewer-facing viewer for the working days claimed on a DTR.
 *
 * The reviewer sees the same calendar the intern picked from, so the claim can
 * be checked against the uploaded scan. Days that fall outside the intern's
 * contract window are highlighted separately.
 *
 * Usage: <button data-dtr-days-view data-dtr-days='[{date,hours,outside_contract}]'>
 */
(function () {
    'use strict';

    var MODAL_ID = 'dtrDaysViewer';

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function parseDate(key) {
        var parts = key.split('-');
        return new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    }

    function monthLabel(year, monthIndex) {
        return new Date(year, monthIndex, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' });
    }

    function formatHours(value) {
        var rounded = Math.round((parseFloat(value) || 0) * 10) / 10;
        return (rounded % 1 === 0 ? String(rounded) : rounded.toFixed(1)) + 'h';
    }

    function renderMonth(year, monthIndex, days) {
        var byDate = new Map();
        days.forEach(function (day) {
            byDate.set(day.date, day);
        });

        var first = new Date(year, monthIndex, 1);
        var startOffset = first.getDay();
        var cells = [];

        for (var i = 0; i < 42; i++) {
            var date = new Date(year, monthIndex, 1 - startOffset + i);
            var key = date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
            var claim = byDate.get(key);
            var inMonth = date.getMonth() === monthIndex;
            var weekend = date.getDay() === 0 || date.getDay() === 6;

            var classes = ['dtr-view-cell'];
            if (!inMonth) classes.push('is-outside');
            if (weekend) classes.push('is-weekend');
            if (claim) classes.push('is-claimed');
            if (claim && claim.outside_contract) classes.push('is-outside-contract');

            var title = claim
                ? ' title="' + key + ' &middot; ' + formatHours(claim.hours) +
                    (claim.outside_contract ? ' &middot; outside contract' : '') + '"'
                : '';

            cells.push(
                '<div class="' + classes.join(' ') + '"' + title + '>' +
                '<span>' + date.getDate() + '</span>' +
                (claim ? '<small class="dtr-view-hours">' + formatHours(claim.hours) + '</small>' : '') +
                '</div>'
            );
        }

        return '<div class="dtr-view-month">' +
            '<div class="dtr-view-month-label">' + monthLabel(year, monthIndex) + '</div>' +
            '<div class="dtr-view-grid dtr-view-grid-head">' +
            ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].map(function (d) {
                return '<div class="dtr-view-weekday">' + d + '</div>';
            }).join('') +
            '</div>' +
            '<div class="dtr-view-grid">' + cells.join('') + '</div>' +
            '</div>';
    }

    function render(container, days) {
        if (!days || !days.length) {
            container.innerHTML = '<p class="text-muted mb-0">No working days recorded for this document.</p>';
            return;
        }

        var groups = {};
        days.forEach(function (day) {
            var key = day.date.slice(0, 7);
            (groups[key] = groups[key] || []).push(day);
        });

        container.innerHTML = Object.keys(groups).sort().map(function (key) {
            var parts = key.split('-');
            return renderMonth(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, groups[key]);
        }).join('');
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-dtr-days-view]');
        if (!trigger) {
            return;
        }

        event.preventDefault();

        var modalEl = document.getElementById(MODAL_ID);
        if (!modalEl) {
            return;
        }

        var days = [];
        try {
            days = JSON.parse(trigger.getAttribute('data-dtr-days') || '[]');
        } catch (e) {
            days = [];
        }

        var totalHours = days.reduce(function (sum, day) {
            return sum + (parseFloat(day.hours) || 0);
        }, 0);
        var outside = days.filter(function (day) {
            return day.outside_contract;
        }).length;

        modalEl.querySelector('[data-dtr-view-title]').textContent =
            trigger.getAttribute('data-dtr-title') || 'Claimed working days';
        modalEl.querySelector('[data-dtr-view-meta]').textContent =
            days.length + ' day(s) · ' + (Math.round(totalHours * 10) / 10) + ' hrs' +
            (outside ? ' · ' + outside + ' outside contract' : '');

        render(modalEl.querySelector('[data-dtr-view-body]'), days);

        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    });
})();
