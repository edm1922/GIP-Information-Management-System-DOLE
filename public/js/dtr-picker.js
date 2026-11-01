/**
 * DTR working-day picker.
 *
 * Clicking a day opens a small hours prompt, so every claimed day carries its own
 * value (8.2, 7.8, …). The selected days — not the uploaded scan — are the record
 * of what was worked; the scan is evidence for the reviewer.
 *
 * Expects: dtrPicker({ days: [], dayHours: {}, taken: [], contractStart, contractEnd })
 */
window.dtrPicker = function (config) {
    config = config || {};

    function round1(value) {
        return Math.round((parseFloat(value) || 0) * 10) / 10;
    }

    return {
        days: (config.days || []).slice(),
        hours: Object.assign({}, config.dayHours || {}),
        taken: (config.taken || []).slice(),
        contractStart: config.contractStart || null,
        contractEnd: config.contractEnd || null,

        // Hours prompt state
        entryOpen: false,
        editingKey: null,
        entryHours: 8,

        cursor: null,

        init() {
            const today = new Date();
            this.cursor = new Date(today.getFullYear(), today.getMonth(), 1);

            // Legacy/initial selections without a value default to a full day.
            this.days.forEach((day) => {
                if (this.hours[day] === undefined || this.hours[day] === null) {
                    this.hours[day] = 8;
                }
            });
        },

        pad(n) {
            return String(n).padStart(2, '0');
        },

        key(date) {
            return date.getFullYear() + '-' + this.pad(date.getMonth() + 1) + '-' + this.pad(date.getDate());
        },

        todayKey() {
            return this.key(new Date());
        },

        pretty(day) {
            const parts = day.split('-');
            const date = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));

            return date.toLocaleString('en-US', { weekday: 'long', month: 'short', day: '2-digit', year: 'numeric' });
        },

        get monthLabel() {
            if (!this.cursor) return '';
            return this.cursor.toLocaleString('en-US', { month: 'long', year: 'numeric' });
        },

        get contractLabel() {
            if (!this.contractStart && !this.contractEnd) return null;
            return (this.contractStart || '—') + ' to ' + (this.contractEnd || '—');
        },

        get grid() {
            if (!this.cursor) return [];

            const first = new Date(this.cursor.getFullYear(), this.cursor.getMonth(), 1);
            const startOffset = first.getDay(); // Sunday = 0
            const cells = [];
            const today = this.todayKey();
            const selected = new Set(this.days);
            const taken = new Set(this.taken);

            for (let i = 0; i < 42; i++) {
                const date = new Date(first.getFullYear(), first.getMonth(), 1 - startOffset + i);
                const key = this.key(date);
                const inMonth = date.getMonth() === first.getMonth();
                const future = key > today;
                // Days outside the contract are still claimable so interns can
                // back-fill earlier DTRs; the reviewer sees them flagged.
                const outsideContract = (this.contractStart && key < this.contractStart)
                    || (this.contractEnd && key > this.contractEnd);

                cells.push({
                    key,
                    day: date.getDate(),
                    inMonth,
                    weekend: date.getDay() === 0 || date.getDay() === 6,
                    today: key === today,
                    selected: selected.has(key),
                    hours: this.hours[key] ?? null,
                    taken: taken.has(key),
                    outsideContract: Boolean(outsideContract),
                    // Only the future and already-claimed days are hard-blocked.
                    disabled: !inMonth || future || taken.has(key),
                });
            }

            return cells;
        },

        get monthStats() {
            const weekdays = new Set();
            const started = new Set(this.days);
            this.grid.forEach((cell) => {
                if (cell.inMonth && !cell.weekend) weekdays.add(cell.key);
            });
            let covered = 0;
            weekdays.forEach((key) => {
                if (started.has(key)) covered++;
            });
            return { weekdays: weekdays.size, covered };
        },

        get monthHasSelectable() {
            return this.grid.some((cell) => cell.inMonth && !cell.disabled);
        },

        get sortedDays() {
            return this.days.slice().sort();
        },

        get count() {
            return this.days.length;
        },

        get totalHours() {
            return round1(this.days.reduce((sum, day) => sum + (parseFloat(this.hours[day]) || 0), 0));
        },

        get isEditingSelected() {
            return this.editingKey !== null && this.days.includes(this.editingKey);
        },

        get editingLabel() {
            return this.editingKey ? this.pretty(this.editingKey) : '';
        },

        prev() {
            this.cursor = new Date(this.cursor.getFullYear(), this.cursor.getMonth() - 1, 1);
        },

        next() {
            this.cursor = new Date(this.cursor.getFullYear(), this.cursor.getMonth() + 1, 1);
        },

        /** Clicking a day opens the hours prompt (selected days can be edited). */
        openEntry(cell) {
            if (cell.disabled) return;

            this.editingKey = cell.key;
            this.entryHours = cell.selected ? (parseFloat(this.hours[cell.key]) || 8) : 8;
            this.entryOpen = true;

            this.$nextTick(() => {
                const input = this.$refs.entryInput;
                if (input) {
                    input.focus();
                    input.select();
                }
            });
        },

        saveEntry() {
            if (!this.editingKey) return;

            const value = Math.min(24, Math.max(0.1, round1(this.entryHours) || 8));
            this.entryHours = value;

            if (!this.days.includes(this.editingKey)) {
                this.days.push(this.editingKey);
            }
            this.hours[this.editingKey] = value;

            this.closeEntry();
        },

        removeEntry() {
            if (!this.editingKey) return;

            const index = this.days.indexOf(this.editingKey);
            if (index !== -1) {
                this.days.splice(index, 1);
                delete this.hours[this.editingKey];
            }

            this.closeEntry();
        },

        closeEntry() {
            this.entryOpen = false;
            this.editingKey = null;
        },

        /** Bulk-add weekdays of the visible month at a full 8-hour day. */
        addWeekdays() {
            this.grid.forEach((cell) => {
                if (!cell.disabled && !cell.weekend && !this.days.includes(cell.key)) {
                    this.days.push(cell.key);
                    this.hours[cell.key] = 8;
                }
            });
        },

        clear() {
            this.days = [];
            this.hours = {};
        },
    };
};
