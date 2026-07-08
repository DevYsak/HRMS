import ApexCharts from 'apexcharts';
import gsap from 'gsap';

window.ApexCharts = ApexCharts;
window.gsap = gsap;

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
     * Enterprise punch timeline: grows the connecting rail left→right, fades
     * nodes in with a scale pop, and — when the employee is still inside —
     * keeps a live "currently working" timer ticking every second.
     *
     *   <div x-data="punchTimeline({ live: true, startedAtMs: 1720…, base: '02:14' })">
     */
    Alpine.data('punchTimeline', (opts = {}) => ({
        live: !!opts.live,
        startedAtMs: opts.startedAtMs || null,
        elapsed: '00h 00m 00s',
        init() {
            this.$nextTick(() => this.animateIn());
            if (this.live && this.startedAtMs) {
                this.tickTimer();
                this._timer = setInterval(() => this.tickTimer(), 1000);
            }
        },
        destroy() { if (this._timer) clearInterval(this._timer); },
        animateIn() {
            const g = window.gsap;
            const rail = this.$root.querySelector('[data-tl-progress]');
            const nodes = this.$root.querySelectorAll('[data-tl-node]');
            const live = this.$root.querySelector('[data-tl-live]');
            const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (!g || prefersReduced) {
                if (rail) rail.style.width = '100%';
                nodes.forEach((n) => { n.style.opacity = 1; n.style.transform = 'none'; });
                if (live) this.pulse(live);
                return;
            }

            g.set(nodes, { opacity: 0, scale: 0.4 });
            const tl = g.timeline();
            if (rail) {
                tl.fromTo(rail, { width: '0%' }, { width: '100%', duration: 0.9, ease: 'power2.inOut' }, 0);
            }
            tl.to(nodes, { opacity: 1, scale: 1, duration: 0.4, ease: 'back.out(2)', stagger: 0.08 }, 0.15);
            // Approved-regularization punches land with a distinct elastic pop so the
            // employee sees the correction arrive in the timeline.
            const regs = this.$root.querySelectorAll('[data-tl-reg] .pa-hz-dot');
            if (regs.length) {
                tl.fromTo(regs, { scale: 0.3 }, { scale: 1, duration: 0.9, ease: 'elastic.out(1, 0.45)' }, '>-0.1');
            }
            tl.add(() => { if (live) this.pulse(live); });
        },
        pulse(el) {
            const g = window.gsap;
            if (!g) return;
            g.to(el, { scale: 1.18, duration: 0.9, repeat: -1, yoyo: true, ease: 'sine.inOut', transformOrigin: 'center' });
        },
        tickTimer() {
            const diff = Math.max(0, Math.floor((Date.now() - this.startedAtMs) / 1000));
            const h = String(Math.floor(diff / 3600)).padStart(2, '0');
            const m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
            const s = String(diff % 60).padStart(2, '0');
            this.elapsed = `${h}h ${m}m ${s}s`;
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
