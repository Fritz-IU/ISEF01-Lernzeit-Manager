/*
 * Lernzeit-Manager – Soll-Ist-Dashboard (Story #6)
 *
 * Zeichnet die Diagramme des Dashboards mit Chart.js. Die Daten liefert index.php
 * als JSON im Element #dashboard-data. Das Dashboard ist rein lesend.
 */
(function () {
  'use strict';

  const dataElement = document.getElementById('dashboard-data');

  // Semesterauswahl: direkt beim Wechseln neu laden (ohne JS per Button im <noscript>)
  const semesterSelect = document.getElementById('semesterSelect');
  if (semesterSelect) {
    semesterSelect.addEventListener('change', function () {
      semesterSelect.form.submit();
    });
  }

  if (!dataElement || typeof window.Chart === 'undefined') {
    return;
  }

  const data = JSON.parse(dataElement.textContent || '{}');
  const Chart = window.Chart;

  // Farben nach Rolle: Soll neutral (Referenz), Ist in der Markenfarbe
  const css = getComputedStyle(document.documentElement);
  const color = (name, fallback) => (css.getPropertyValue(name) || '').trim() || fallback;
  const COLORS = {
    ist: color('--dashboard-ist', '#0d6efd'),
    istFill: 'rgba(13, 110, 253, 0.08)',
    soll: color('--dashboard-soll', '#94a3b8'),
    sollBar: color('--dashboard-soll-bar', '#cbd5e1'),
    budget: color('--dashboard-budget', '#b45309'),
    today: '#334155',
    grid: '#e9eef4',
    text: '#64748b',
  };
  const SERIES = [1, 2, 3, 4, 5, 6, 7, 8].map((i) => color('--series-' + i, '#64748b'));

  const numberFormat = new Intl.NumberFormat('de-DE', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
  });
  const hours = (value) => numberFormat.format(value ?? 0) + ' h';

  Chart.defaults.font.family =
    css.getPropertyValue('--bs-body-font-family') || 'system-ui, sans-serif';
  Chart.defaults.font.size = 12;
  Chart.defaults.color = COLORS.text;
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  Chart.defaults.plugins.legend.labels.boxHeight = 8;
  Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 6;
  Chart.defaults.plugins.tooltip.boxPadding = 4;
  Chart.defaults.plugins.tooltip.usePointStyle = true;
  Chart.defaults.maintainAspectRatio = false;

  const hourAxis = {
    beginAtZero: true,
    grid: { color: COLORS.grid },
    border: { display: false },
    ticks: {
      callback: (value) => numberFormat.format(value).replace(',0', '') + ' h',
      maxTicksLimit: 6,
    },
  };
  const categoryAxis = { grid: { display: false }, border: { color: COLORS.grid } };
  const hoursTooltip = {
    callbacks: { label: (ctx) => ' ' + ctx.dataset.label + ': ' + hours(ctx.parsed.y) },
  };

  /** Senkrechte Markierung "Heute" im Verlaufsdiagramm */
  const todayLinePlugin = {
    id: 'todayLine',
    afterDatasetsDraw(chart, _args, options) {
      if (options.index === null || options.index === undefined) {
        return;
      }
      const x = chart.scales.x.getPixelForValue(options.index);
      const { top, bottom } = chart.chartArea;
      const ctx = chart.ctx;
      ctx.save();
      ctx.strokeStyle = COLORS.today;
      ctx.lineWidth = 1;
      ctx.setLineDash([3, 3]);
      ctx.beginPath();
      ctx.moveTo(x, top);
      ctx.lineTo(x, bottom);
      ctx.stroke();
      ctx.setLineDash([]);
      ctx.fillStyle = COLORS.today;
      ctx.font = '600 11px ' + Chart.defaults.font.family;
      ctx.textAlign = x > chart.chartArea.right - 40 ? 'right' : 'left';
      ctx.fillText('Heute', x + (ctx.textAlign === 'right' ? -4 : 4), top + 10);
      ctx.restore();
    },
  };

  const dateLabel = (iso) => {
    const [y, m, d] = iso.split('-');
    return d + '.' + m + '.' + y;
  };
  const MONTHS_SHORT = [
    'Jan',
    'Feb',
    'Mär',
    'Apr',
    'Mai',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Okt',
    'Nov',
    'Dez',
  ];

  // 1. Kumulierter Soll-Ist-Verlauf
  const cumulativeCanvas = document.getElementById('chartCumulative');
  if (cumulativeCanvas && data.cumulative) {
    const c = data.cumulative;
    new Chart(cumulativeCanvas, {
      type: 'line',
      data: {
        labels: c.labels,
        datasets: [
          {
            label: 'Ist',
            data: c.ist,
            borderColor: COLORS.ist,
            backgroundColor: COLORS.istFill,
            fill: 'origin',
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 5,
            tension: 0,
            spanGaps: false,
          },
          {
            label: 'Soll',
            data: c.soll,
            borderColor: COLORS.soll,
            borderDash: [6, 4],
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 5,
            fill: false,
            tension: 0,
          },
        ],
      },
      options: {
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: {
            ...categoryAxis,
            ticks: {
              autoSkip: false,
              maxRotation: 0,
              // Nur den Monatsanfang beschriften
              callback(_value, index) {
                const iso = c.labels[index];
                if (!iso) {
                  return null;
                }
                const [y, m, d] = iso.split('-');
                return d === '01' || index === 0
                  ? MONTHS_SHORT[parseInt(m, 10) - 1] + ' ' + y.slice(2)
                  : null;
              },
            },
          },
          y: hourAxis,
        },
        plugins: {
          legend: { position: 'top', align: 'end' },
          todayLine: { index: c.today_index },
          tooltip: {
            callbacks: {
              title: (items) => dateLabel(items[0].label),
              label: (ctx) =>
                ctx.parsed.y === null ? null : ' ' + ctx.dataset.label + ': ' + hours(ctx.parsed.y),
            },
          },
        },
      },
      plugins: [todayLinePlugin],
    });
  }

  // 2. Soll und Ist je Monat (gleiche Stunden-Achse, Budget als Markierung)
  const monthlyCanvas = document.getElementById('chartMonthly');
  if (monthlyCanvas && data.monthly) {
    const m = data.monthly;
    const hasBudget = m.budget.some((b) => b !== null);
    const datasets = [
      {
        type: 'bar',
        label: 'Soll',
        data: m.soll,
        backgroundColor: COLORS.sollBar,
        borderRadius: 4,
        borderSkipped: 'start',
        maxBarThickness: 28,
        order: 2,
      },
      {
        type: 'bar',
        label: 'Ist',
        data: m.ist,
        backgroundColor: COLORS.ist,
        borderRadius: 4,
        borderSkipped: 'start',
        maxBarThickness: 28,
        order: 2,
      },
    ];
    if (hasBudget) {
      datasets.push({
        type: 'line',
        label: 'Monatsbudget',
        data: m.budget,
        showLine: false,
        pointStyle: 'line',
        pointRadius: 16,
        pointHoverRadius: 16,
        pointBorderWidth: 3,
        pointHoverBorderWidth: 3,
        borderColor: COLORS.budget,
        backgroundColor: COLORS.budget,
        order: 1,
      });
    }

    new Chart(monthlyCanvas, {
      data: { labels: m.labels, datasets },
      options: {
        interaction: { mode: 'index', intersect: false },
        datasets: { bar: { categoryPercentage: 0.7, barPercentage: 0.9 } },
        scales: { x: categoryAxis, y: hourAxis },
        plugins: {
          legend: { position: 'top', align: 'end' },
          tooltip: {
            callbacks: {
              label: (ctx) =>
                ctx.parsed.y === null ? null : ' ' + ctx.dataset.label + ': ' + hours(ctx.parsed.y),
            },
          },
        },
      },
    });
  }

  // 3. Diese Woche: eingeplant vs. erfasst je Tag
  const weekCanvas = document.getElementById('chartWeek');
  if (weekCanvas && data.week) {
    const days = data.week.days;
    new Chart(weekCanvas, {
      type: 'bar',
      data: {
        labels: days.map((d) => d.label.split(' ')[0]),
        datasets: [
          {
            label: 'Soll',
            data: days.map((d) => d.scheduled_hours),
            backgroundColor: COLORS.sollBar,
            borderRadius: 4,
            borderSkipped: 'start',
            maxBarThickness: 22,
          },
          {
            label: 'Ist',
            data: days.map((d) => d.tracked_hours),
            backgroundColor: COLORS.ist,
            borderRadius: 4,
            borderSkipped: 'start',
            maxBarThickness: 22,
          },
        ],
      },
      options: {
        interaction: { mode: 'index', intersect: false },
        datasets: { bar: { categoryPercentage: 0.7, barPercentage: 0.9 } },
        scales: {
          x: {
            ...categoryAxis,
            ticks: {
              // Heutigen Tag hervorheben
              font: (ctx) => (days[ctx.index] && days[ctx.index].is_today ? { weight: '700' } : {}),
              color: (ctx) =>
                days[ctx.index] && days[ctx.index].is_today ? COLORS.today : COLORS.text,
            },
          },
          y: hourAxis,
        },
        plugins: {
          legend: { position: 'top', align: 'end' },
          tooltip: {
            ...hoursTooltip,
            callbacks: {
              ...hoursTooltip.callbacks,
              title: (items) =>
                days[items[0].dataIndex].label +
                (days[items[0].dataIndex].is_today ? ' (heute)' : ''),
            },
          },
        },
      },
    });
  }

  // 4. Erfasste Lernzeit je Modul (Farbe folgt dem Modul, nicht dem Rang)
  const modulesCanvas = document.getElementById('chartModules');
  if (modulesCanvas && data.modules && data.modules.length) {
    const modules = data.modules;
    const total = modules.reduce((sum, mod) => sum + mod.tracked_hours, 0);
    new Chart(modulesCanvas, {
      type: 'doughnut',
      data: {
        labels: modules.map((mod) => mod.title),
        datasets: [
          {
            data: modules.map((mod) => mod.tracked_hours),
            backgroundColor: modules.map((mod) => SERIES[mod.color_index % SERIES.length]),
            borderColor: '#ffffff',
            borderWidth: 2,
            hoverOffset: 4,
          },
        ],
      },
      options: {
        cutout: '68%',
        plugins: {
          // Legende als HTML unter dem Diagramm (index.php), damit die Mitte frei bleibt
          legend: { display: false },
          tooltip: {
            callbacks: {
              label(ctx) {
                const mod = modules[ctx.dataIndex];
                const share = total > 0 ? Math.round((mod.tracked_hours / total) * 100) : 0;
                return (
                  ' ' +
                  hours(mod.tracked_hours) +
                  ' (' +
                  share +
                  ' %) · Soll ' +
                  hours(mod.planned_hours)
                );
              },
            },
          },
        },
      },
    });
  }
})();
