import ApexCharts from 'apexcharts';

window.ApexCharts = ApexCharts;

const isDark = () => document.documentElement.classList.contains('dark');

/** Theme-aware ApexCharts overrides — grid, axes, tooltip, legend, foreground. */
const pulseChartTheme = (dark) => ({
    theme: { mode: dark ? 'dark' : 'light' },
    chart: { background: 'transparent', foreColor: dark ? '#CBD5E1' : '#6B7280' },
    grid: { borderColor: dark ? '#334155' : '#F3E8DD' },
    tooltip: { theme: dark ? 'dark' : 'light' },
    xaxis: { labels: { style: { colors: dark ? '#94A3B8' : '#9CA3AF' } } },
    yaxis: { labels: { style: { colors: dark ? '#94A3B8' : '#9CA3AF' } } },
    legend: { labels: { colors: dark ? '#CBD5E1' : '#6B7280' } },
});

/** Shallow-merge the theme overrides into a chart config before first render. */
const applyChartTheme = (config, dark) => {
    const t = pulseChartTheme(dark);
    config.theme = { ...(config.theme || {}), ...t.theme };
    config.chart = { ...(config.chart || {}), background: 'transparent', foreColor: t.chart.foreColor };
    config.grid = { ...(config.grid || {}), borderColor: t.grid.borderColor };
    config.tooltip = { ...(config.tooltip || {}), theme: t.tooltip.theme };
    return config;
};

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * Reusable, theme-aware ApexCharts wrapper.
     *   <div x-data="apexChart('att', { ...options })" x-ref="chart" wire:ignore></div>
     *
     * Live series update:
     *   window.dispatchEvent(new CustomEvent('apex-update', { detail: { id, series, categories } }));
     * Charts also re-theme automatically when `.dark` toggles on <html>.
     */
    Alpine.data('apexChart', (id = null, config = {}) => ({
        chart: null,
        _handler: null,
        _observer: null,
        init() {
            this.chart = new window.ApexCharts(this.$refs.chart, applyChartTheme(config, isDark()));
            this.chart.render();

            // Re-theme on global light/dark switch.
            this._observer = new MutationObserver(() => {
                this.chart?.updateOptions(pulseChartTheme(isDark()), false, false);
            });
            this._observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

            if (id) {
                this._handler = (e) => {
                    if (e.detail?.id !== id) return;
                    if (e.detail.categories) {
                        this.chart.updateOptions({ xaxis: { categories: e.detail.categories } }, false, true);
                    }
                    if (e.detail.series) {
                        this.chart.updateSeries(e.detail.series, true);
                    }
                };
                window.addEventListener('apex-update', this._handler);
            }
        },
        destroy() {
            if (this._handler) window.removeEventListener('apex-update', this._handler);
            this._observer?.disconnect();
            this.chart?.destroy();
        },
    }));

    /**
     * Count-up number animation, preserving prefix/suffix (₹, %, +, L, etc.).
     *   <span x-data="countUp('80%')" x-text="display"></span>
     */
    Alpine.data('countUp', (raw = '0', duration = 1100) => ({
        display: raw,
        init() {
            const m = String(raw).match(/^([^\d-]*)(-?[\d,]*\.?\d+)(.*)$/);
            if (!m) { this.display = raw; return; }
            const [, prefix, numStr, suffix] = m;
            const target = parseFloat(numStr.replace(/,/g, ''));
            const decimals = (numStr.split('.')[1] || '').length;
            const start = performance.now();
            const ease = (t) => 1 - Math.pow(1 - t, 3);
            const tick = (now) => {
                const p = Math.min((now - start) / duration, 1);
                const val = (target * ease(p)).toFixed(decimals);
                this.display = prefix + Number(val).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;
                if (p < 1) requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        },
    }));
});
