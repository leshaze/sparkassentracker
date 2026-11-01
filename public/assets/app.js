/* Sparkassentracker – kleine Helfer, kein Framework */
(function () {
    'use strict';

    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));

    // ---------- Filter: bei Änderung automatisch absenden ----------
    $$('form[data-autosubmit]').forEach((form) => {
        const preset = $('[data-preset]', form);
        const toggleRange = () => {
            if (!preset) return;
            $$('.custom-range', form).forEach((el) => (el.hidden = preset.value !== 'frei'));
        };
        form.addEventListener('change', (e) => {
            const t = e.target;
            if (t.matches('[data-nosubmit]')) return;
            if (t === preset) {
                toggleRange();
                if (preset.value === 'frei') return; // erst Datum wählen
            }
            form.requestSubmit ? form.requestSubmit() : form.submit();
        });
    });

    // ---------- Sicherheitsabfragen ----------
    $$('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (!confirm(form.dataset.confirm)) e.preventDefault();
        });
    });

    // ---------- Umsatz bearbeiten ----------
    const dlg = $('#tx-dialog');
    if (dlg) {
        const form = $('form', dlg);
        const ruleToggle = $('[data-rule-toggle]', dlg);
        const ruleKw = $('.rule-kw', dlg);
        const open = (row) => {
            const d = JSON.parse(row.dataset.edit);
            form.hash.value = d.hash;
            const sel = form.kategorie;
            sel.value = d.kategorie || 'Unbekannt';
            if (sel.value !== (d.kategorie || 'Unbekannt')) sel.value = 'Unbekannt';
            form.notiz.value = d.notiz || '';
            form.uebertrag.checked = !!d.ue;
            ruleToggle.checked = false;
            ruleKw.hidden = true;
            form.regel_kw.value = (d.empf || '').toLowerCase().trim();
            $('.d-empf', dlg).textContent = d.empf || d.btext || '—';
            $('.d-meta', dlg).textContent = [d.datum, d.konto, d.btext].filter(Boolean).join(' · ');
            const b = $('.d-betrag', dlg);
            b.textContent = d.betrag;
            b.className = 'd-betrag num ' + (d.betrag.startsWith('−') ? 'neg' : 'pos');
            $('.d-zweck', dlg).textContent = d.zweck || '';
            dlg.showModal();
            sel.focus();
        };
        $$('tr[data-edit]').forEach((row) => {
            row.addEventListener('click', (e) => {
                if (e.target.closest('a, button, input')) return;
                open(row);
            });
            row.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    open(row);
                }
            });
        });
        ruleToggle.addEventListener('change', () => {
            ruleKw.hidden = !ruleToggle.checked;
            if (ruleToggle.checked) form.regel_kw.focus();
        });
        $('[data-close]', dlg).addEventListener('click', () => dlg.close());
        dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    }

    // ---------- Wiederkehrend: als beendet markieren ----------
    const endDlg = $('#end-dialog');
    if (endDlg) {
        const f = $('form', endDlg);
        $$('[data-end]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const d = JSON.parse(btn.dataset.end);
                f.key.value = d.key;
                f.empfaenger.value = d.empf;
                f.betrag.value = d.betrag;
                f.frequenz.value = d.freq;
                f.datum.value = d.default;
                $('.d-empf', endDlg).textContent = d.empf;
                $('.d-meta', endDlg).textContent = d.freq + (d.letzte ? ' · zuletzt ' + d.letzte : '');
                $('.d-betrag', endDlg).textContent = d.betrag_fmt;
                endDlg.showModal();
                f.datum.focus();
            });
        });
        $('[data-close]', endDlg).addEventListener('click', () => endDlg.close());
        endDlg.addEventListener('click', (e) => { if (e.target === endDlg) endDlg.close(); });
    }

    // ---------- Notizen (Wiederkehrend): beim Verlassen speichern ----------
    $$('.inline-note input').forEach((inp) => {
        const initial = inp.value;
        inp.addEventListener('change', () => { if (inp.value !== initial) inp.form.submit(); });
    });

    // ---------- Aufgeklappte Kategorien merken ----------
    const KEY = 'spk-open-rules';
    const ruleCats = $$('details.rule-cat');
    if (ruleCats.length) {
        let openSet = [];
        try { openSet = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { /* ignorieren */ }
        ruleCats.forEach((d) => {
            const name = $('summary strong', d).textContent;
            if (openSet.includes(name)) d.open = true;
            d.addEventListener('toggle', () => {
                const s = new Set(openSet);
                d.open ? s.add(name) : s.delete(name);
                openSet = [...s];
                try { sessionStorage.setItem(KEY, JSON.stringify(openSet)); } catch (e) { /* ignorieren */ }
            });
        });
    }

    // ---------- Upload-Feld ----------
    $$('.dropzone').forEach((dz) => {
        const input = $('input[type=file]', dz);
        const text = $('.dz-text', dz);
        const orig = text.textContent;
        input.addEventListener('change', () => {
            const names = Array.from(input.files).map((f) => f.name);
            text.textContent = names.length ? names.join(', ') : orig;
        });
        ['dragenter', 'dragover'].forEach((ev) => dz.addEventListener(ev, () => dz.classList.add('over')));
        ['dragleave', 'drop'].forEach((ev) => dz.addEventListener(ev, () => dz.classList.remove('over')));
    });

    // ---------- Charts ----------
    if (!window.Chart) return;
    const css = getComputedStyle(document.documentElement);
    const v = (n) => css.getPropertyValue(n).trim();
    const dark = matchMedia('(prefers-color-scheme: dark)').matches;
    const ink2 = v('--ink-2'), muted = v('--muted'), line = v('--line-2'), surface = v('--surface');
    const POS = '#2ecc71', NEG = '#e74c3c';
    // Kategoriale Reihenfolge (fest, nie gecycelt) – hell/dunkel validiert
    const CAT = dark
        ? ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9']
        : ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6'];
    const OTHER = dark ? '#5b6270' : '#b4bac4';

    const eur = new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' });
    const eurShort = (n) => new Intl.NumberFormat('de-DE', { notation: 'compact', maximumFractionDigits: 1 }).format(n) + ' €';

    Chart.defaults.font.family = v('--font') || 'system-ui, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = ink2;
    Chart.defaults.borderColor = line;
    Chart.defaults.plugins.legend.labels.boxWidth = 10;
    Chart.defaults.plugins.legend.labels.boxHeight = 10;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.tooltip.callbacks.label = (c) =>
        `${c.dataset.label ? c.dataset.label + ': ' : ''}${eur.format(c.parsed.y ?? c.parsed)}`;

    const yAxis = {
        grid: { color: line, drawTicks: false },
        border: { display: false },
        ticks: { color: muted, padding: 8, callback: (n) => eurShort(n) },
    };
    const xAxis = { grid: { display: false }, border: { color: line }, ticks: { color: muted } };

    $$('canvas[data-chart]').forEach((cv) => {
        const data = JSON.parse(cv.dataset.series);
        const kind = cv.dataset.chart;

        if (kind === 'monthly') {
            new Chart(cv, {
                data: {
                    labels: data.map((d) => d.label),
                    datasets: [
                        { type: 'bar', label: 'Einnahmen', data: data.map((d) => d.ein), backgroundColor: POS,
                          borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22, categoryPercentage: .7, barPercentage: .9 },
                        { type: 'bar', label: 'Ausgaben', data: data.map((d) => d.aus), backgroundColor: NEG,
                          borderRadius: 4, borderSkipped: 'start', maxBarThickness: 22, categoryPercentage: .7, barPercentage: .9 },
                        { type: 'line', label: 'Saldo', data: data.map((d) => d.saldo), borderColor: v('--ink'),
                          backgroundColor: v('--ink'), borderWidth: 2, pointRadius: 3, pointHoverRadius: 5,
                          pointBorderColor: surface, pointBorderWidth: 2, tension: .25 },
                    ],
                },
                options: {
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'top', align: 'end' } },
                    scales: { x: xAxis, y: yAxis },
                },
            });
        }

        if (kind === 'donut') {
            const top = data.slice(0, CAT.length);
            const rest = data.slice(CAT.length).reduce((s, d) => s + d.value, 0);
            const items = rest > 0 ? [...top, { label: 'Sonstige', value: rest }] : top;
            new Chart(cv, {
                type: 'doughnut',
                data: {
                    labels: items.map((d) => d.label),
                    datasets: [{
                        data: items.map((d) => d.value),
                        backgroundColor: items.map((d, i) => (i < CAT.length && d.label !== 'Sonstige' ? CAT[i] : OTHER)),
                        borderColor: surface, borderWidth: 2, hoverOffset: 4,
                    }],
                },
                options: {
                    maintainAspectRatio: false, cutout: '62%',
                    plugins: {
                        legend: { position: 'right', labels: { color: ink2, padding: 10 } },
                        tooltip: { callbacks: { label: (c) => `${c.label}: ${eur.format(c.parsed)}` } },
                    },
                },
            });
        }

        if (kind === 'yoy') {
            new Chart(cv, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [
                        { label: data.prev, data: data.vj, backgroundColor: OTHER, borderRadius: 4,
                          borderSkipped: 'start', maxBarThickness: 20, categoryPercentage: .7, barPercentage: .9 },
                        { label: data.year, data: data.now, backgroundColor: CAT[0], borderRadius: 4,
                          borderSkipped: 'start', maxBarThickness: 20, categoryPercentage: .7, barPercentage: .9 },
                    ],
                },
                options: {
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'top', align: 'end' } },
                    scales: { x: xAxis, y: yAxis },
                },
            });
        }
    });
})();
