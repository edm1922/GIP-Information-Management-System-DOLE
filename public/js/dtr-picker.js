/**
 * GIMS working-day picker (Blade UI).
 *
 * Interns only select the dates they worked; working hours are assigned by an
 * Admin during final approval. There is deliberately no hours input here.
 */
function dtrPicker(config = {}) {
    const pad = (value) => String(value).padStart(2, '0');
    const keyOf = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

    return {
        days: Array.isArray(config.days) ? [...config.days] : [],
        taken: Array.isArray(config.taken) ? [...config.taken] : [],
        contractStart: config.contractStart || null,
        contractEnd: config.contractEnd || null,
        todayKey: keyOf(new Date()),
        cursor: (() => {
            const today = new Date();
            return new Date(today.getFullYear(), today.getMonth(), 1);
        })(),

        init() {
            this.days = [...new Set(this.days)].filter((day) => !this.taken.includes(day));
        },

        get monthLabel() {
            return this.cursor.toLocaleString('en-US', { month: 'long', year: 'numeric' });
        },

        get count() {
            return this.days.length;
        },

        get contractLabel() {
            return this.contractStart || this.contractEnd
                ? `${this.contractStart || '—'} to ${this.contractEnd || '—'}`
                : '';
        },

        get grid() {
            const first = new Date(this.cursor.getFullYear(), this.cursor.getMonth(), 1);
            const startOffset = first.getDay();
            const selected = new Set(this.days);
            const taken = new Set(this.taken);

            return Array.from({ length: 42 }, (_value, index) => {
                const date = new Date(this.cursor.getFullYear(), this.cursor.getMonth(), 1 - startOffset + index);
                const key = keyOf(date);
                const inMonth = date.getMonth() === this.cursor.getMonth();
                const future = key > this.todayKey;
                const outsideContract = Boolean(
                    (this.contractStart && key < this.contractStart) || (this.contractEnd && key > this.contractEnd)
                );

                return {
                    key,
                    day: date.getDate(),
                    inMonth,
                    weekend: date.getDay() === 0 || date.getDay() === 6,
                    today: key === this.todayKey,
                    selected: selected.has(key),
                    taken: taken.has(key),
                    outsideContract,
                    disabled: !inMonth || future || taken.has(key),
                };
            });
        },

        get monthHasSelectable() {
            return this.grid.some((cell) => cell.inMonth && !cell.disabled);
        },

        get monthStats() {
            const weekdays = this.grid.filter((cell) => cell.inMonth && !cell.weekend && !cell.taken).map((cell) => cell.key);
            return {
                weekdays: weekdays.length,
                covered: weekdays.filter((key) => this.days.includes(key)).length,
            };
        },

        get summary() {
            if (this.days.length === 0) return '';
            if (this.days.length === 1) return this.prettyDay(this.days[0]);
            const sorted = [...this.days].sort();
            return `${this.prettyDay(sorted[0])} → ${this.prettyDay(sorted[sorted.length - 1])}`;
        },

        prettyDay(day) {
            const [year, month, date] = day.split('-').map(Number);
            return new Date(year, month - 1, date).toLocaleString('en-US', {
                weekday: 'long',
                month: 'short',
                day: '2-digit',
                year: 'numeric',
            });
        },

        toggle(cell) {
            if (cell.disabled) return;
            if (this.days.includes(cell.key)) {
                this.days = this.days.filter((day) => day !== cell.key);
            } else {
                this.days = [...this.days, cell.key];
            }
        },

        addWeekdays() {
            const next = new Set(this.days);
            this.grid.forEach((cell) => {
                if (!cell.disabled && !cell.weekend) next.add(cell.key);
            });
            this.days = [...next];
        },

        clear() {
            this.days = [];
        },

        prev() {
            this.cursor = new Date(this.cursor.getFullYear(), this.cursor.getMonth() - 1, 1);
        },

        next() {
            this.cursor = new Date(this.cursor.getFullYear(), this.cursor.getMonth() + 1, 1);
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('dtrPicker', dtrPicker);
});
