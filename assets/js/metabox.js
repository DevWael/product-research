/**
 * Product Research Metabox JavaScript
 *
 * Handles the research workflow UI: first-run state, search preview,
 * URL selection, progress polling, results display, and history.
 */
(function ($) {
    'use strict';

    class ProductResearchMetabox {
        constructor(rootEl) {
            this.root = rootEl;
            this.config = JSON.parse(rootEl.getAttribute('data-config') || '{}');
            this.state = {
                phase: 'idle',
                reportId: null,
                searchResults: [],
                priceChartInstance: null,
                historyChartInstance: null,
                originalDescription: null,
                selectedUrls: [],
                report: null,
                polling: null,
            };

            this.init();
        }

        init() {
            if (this.config.inProgress) {
                this.resumeInProgress(this.config.inProgress);
            } else if (this.config.latestReport) {
                this.showResults(this.config.latestReport);
            } else {
                this.showFirstRun();
            }
        }

        // ─── State Renderers ───────────────────────────────────────

        showFirstRun() {
            const s = this.config.strings;
            this.root.innerHTML = `
                <div class="pr-first-run">
                    <div class="pr-first-run__icon">🔍</div>
                    <h3 class="pr-first-run__title">${this.esc(s.firstRunTitle)}</h3>
                    <p class="pr-first-run__desc">${this.esc(s.firstRunDescription)}</p>
                    <button type="button" class="button button-primary button-hero pr-btn-start">
                        ${this.esc(s.firstRunCta)}
                    </button>
                    <p class="pr-first-run__time">${this.esc(s.firstRunTime)}</p>
                </div>
            `;
            this.root.querySelector('.pr-btn-start').addEventListener('click', () => this.startResearch());
        }

        showProgress(status, message) {
            const s = this.config.strings;
            const statusText = s[status] || message || status;
            this.root.innerHTML = `
                <div class="pr-progress">
                    <div class="pr-progress__spinner"></div>
                    <p class="pr-progress__status">${this.esc(statusText)}</p>
                    <p class="pr-progress__message">${this.esc(message || '')}</p>
                    <button type="button" class="button pr-btn-cancel">${this.esc(s.cancel || 'Cancel')}</button>
                </div>
            `;

            this.root.querySelector('.pr-btn-cancel').addEventListener('click', () => this.cancelReport());
        }

        cancelReport() {
            if (!this.state.reportId) {
                this.showFirstRun();
                return;
            }

            this.stopPolling();
            this.ajax('pr_cancel_report', { report_id: this.state.reportId })
                .then(() => {
                    this.state.reportId = null;
                    this.showFirstRun();
                })
                .catch(() => {
                    // Even on error, reset the UI so the user isn't stuck.
                    this.state.reportId = null;
                    this.showFirstRun();
                });
        }

        showPreview(searchResults, urls, query) {
            const s = this.config.strings;
            let html = `
                <div class="pr-preview">
                    <h3 class="pr-preview__title">${this.esc(s.previewing)}</h3>
                    <p class="pr-preview__query">Query: <em>${this.esc(query)}</em></p>
                    <div class="pr-preview__actions">
                        <button type="button" class="button pr-btn-select-all">${this.esc(s.selectAll)}</button>
                        <button type="button" class="button pr-btn-deselect-all">${this.esc(s.deselectAll)}</button>
                    </div>
                    <div class="pr-preview__list">
            `;

            searchResults.forEach((result, i) => {
                const url = result.url || '';
                const title = result.title || url;
                const content = (result.content || '').substring(0, 150);
                const score = result.score ? Math.round(result.score * 100) : '';

                html += `
                    <label class="pr-preview__item">
                        <input type="checkbox" class="pr-url-checkbox" value="${this.escAttr(url)}" checked />
                        <div class="pr-preview__item-content">
                            <strong>${this.esc(title)}</strong>
                            ${score ? `<span class="pr-score">${score}%</span>` : ''}
                            <span class="pr-preview__url">${this.esc(url)}</span>
                            <p class="pr-preview__snippet">${this.esc(content)}</p>
                        </div>
                    </label>
                `;
            });

            html += `
                    </div>
                    <button type="button" class="button button-primary pr-btn-confirm">
                        ${this.esc(s.confirmSelection)}
                    </button>
                </div>
            `;

            this.root.innerHTML = html;

            this.root.querySelector('.pr-btn-confirm').addEventListener('click', () => this.confirmUrls());
            this.root.querySelector('.pr-btn-select-all').addEventListener('click', () => this.toggleAll(true));
            this.root.querySelector('.pr-btn-deselect-all').addEventListener('click', () => this.toggleAll(false));
        }

        showResults(report) {
            const s = this.config.strings;
            const data = report.analysis_result || report.report || {};
            const summary = data.summary || {};
            const competitors = data.competitors || [];
            const recommendations = report.recommendations || [];

            // Update price history from server response so the chart
            // reflects the latest snapshot without a page refresh.
            if (report.price_history && Array.isArray(report.price_history)) {
                this.config.priceHistory = report.price_history;
            }
            const bookmarkedUrls = this.config.bookmarkedUrls || [];

            let html = `<div class="pr-results">`;

            // Summary Dashboard
            html += `
                <div class="pr-summary">
                    <div class="pr-summary__card">
                        <span class="pr-summary__label">${this.esc(s.competitors)}</span>
                        <span class="pr-summary__value">${summary.total_competitors || 0}</span>
                    </div>
                    <div class="pr-summary__card">
                        <span class="pr-summary__label">${this.esc(s.priceRange)}</span>
                        <span class="pr-summary__value">${summary.lowest_price || 0} – ${summary.highest_price || 0}</span>
                    </div>
                    <div class="pr-summary__card">
                        <span class="pr-summary__label">${this.esc(s.avgPrice)}</span>
                        <span class="pr-summary__value">${summary.avg_price || 0}</span>
                    </div>
                </div>
            `;

            // Key Findings
            if (summary.key_findings && summary.key_findings.length) {
                html += `<div class="pr-findings"><h4>${this.esc(s.keyFindings)}</h4><ul>`;
                summary.key_findings.forEach(f => { html += `<li>${this.esc(f)}</li>`; });
                html += `</ul></div>`;
            }

            // ─── Analytics Tabs ─────────────────────────────────────
            if (competitors.length) {
                html += `
                    <div class="pr-analytics">
                        <div class="pr-analytics__tabs">
                            <button type="button" class="pr-analytics__tab pr-analytics__tab--active" data-tab="chart">📊 ${this.esc(s.priceChart)}</button>
                            <button type="button" class="pr-analytics__tab" data-tab="history">📈 ${this.esc(s.priceHistory)}</button>
                        </div>
                        <div class="pr-analytics__panel pr-analytics__panel--active" data-panel="chart">
                            ${this.renderPriceChart(competitors, summary)}
                        </div>
                        <div class="pr-analytics__panel" data-panel="history">
                            ${this.renderPriceHistory()}
                        </div>
                    </div>
                `;
            }

            // Competitor Cards with bookmark + compare
            if (competitors.length) {
                html += `<div class="pr-competitors">`;
                competitors.forEach((comp, i) => {
                    const displayPrice = comp.converted_price ?? comp.current_price;
                    const priceClass = this.getPriceClass(displayPrice, summary.avg_price);
                    const isBookmarked = bookmarkedUrls.includes(comp.url);
                    const bmClass = isBookmarked ? 'pr-bookmark--active' : '';
                    const bmLabel = isBookmarked ? s.bookmarked : s.bookmark;
                    const isFailed = comp.conversion_status === 'failed';
                    const failedIndicator = isFailed ? '<span class="pr-conversion-warn" title="Currency conversion failed"> [!]</span>' : '';
                    // Show converted price as primary; original as secondary when currencies differ
                    const showDual = comp.converted_price && comp.store_currency && comp.currency !== comp.store_currency;
                    const priceLabel = showDual
                        ? `${this.esc(comp.store_currency || '')} ${parseFloat(comp.converted_price).toFixed(2)}${failedIndicator}
                           <span class="pr-card__price-original">${this.esc(comp.currency || '')} ${this.esc(String(comp.current_price || ''))}</span>`
                        : `${this.esc(comp.currency || '')} ${this.esc(String(comp.current_price || ''))}${failedIndicator}`;
                    html += `
                        <div class="pr-card pr-card--collapsed" data-index="${i}">
                            <div class="pr-card__header" role="button">
                                <label class="pr-compare-check" title="${this.esc(s.compare)}" onclick="event.stopPropagation()">
                                    <input type="checkbox" class="pr-compare-cb" data-index="${i}">
                                </label>
                                <span class="pr-card__name">${this.esc(comp.name)}</span>
                                <span class="pr-card__price ${priceClass}">
                                    ${priceLabel}
                                </span>
                                <button type="button" class="pr-bookmark ${bmClass}" data-url="${this.escAttr(comp.url)}" title="${this.esc(bmLabel)}" onclick="event.stopPropagation()">
                                    ${isBookmarked ? '★' : '☆'}
                                </button>
                                <span class="pr-card__toggle">▼</span>
                            </div>
                            <div class="pr-card__body">
                                <div class="pr-card__details">
                                    <p><strong>URL:</strong> <a href="${this.escAttr(comp.url)}" target="_blank">${this.esc(comp.url)}</a></p>
                                    ${comp.availability ? `<p><strong>Availability:</strong> ${this.esc(comp.availability)}</p>` : ''}
                                    ${comp.shipping_info ? `<p><strong>Shipping:</strong> ${this.esc(comp.shipping_info)}</p>` : ''}
                                    ${comp.seller_name ? `<p><strong>Seller:</strong> ${this.esc(comp.seller_name)}</p>` : ''}
                                    ${comp.rating ? `<p><strong>Rating:</strong> ${comp.rating}/5</p>` : ''}
                                    ${comp.original_price ? `<p><strong>Original Price:</strong> ${comp.currency} ${comp.original_price}</p>` : ''}
                                    ${showDual ? `<p><strong>Original Currency Price:</strong> ${comp.currency} ${comp.current_price}</p>` : ''}
                                </div>
                                ${this.renderVariations(comp.variations || [])}
                                ${this.renderFeatures(comp.features || [])}
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;

                // Floating compare bar
                html += `<div class="pr-compare-bar" style="display:none">
                    <span class="pr-compare-bar__count"></span>
                    <button type="button" class="button button-primary pr-btn-compare">${this.esc(s.compareSelected)}</button>
                </div>`;
            } else {
                html += `<p class="pr-no-results">${this.esc(s.noResults)}</p>`;
            }

            // Recommendations panel
            html += `<div class="pr-recommendations" id="pr-recommendations-panel">`;
            if (recommendations.length) {
                html += this.renderRecommendations(recommendations);
            } else {
                html += `
                    <button type="button" class="button pr-btn-recommendations">
                        💡 ${this.esc(s.getRecommendations)}
                    </button>
                `;
            }
            html += `</div>`;

            // Copywriter panel
            html += `
                <div class="pr-copywriter" id="pr-copywriter-panel">
                    <div class="pr-copywriter__controls">
                        <select class="pr-copywriter__tone">
                            <option value="professional">${this.esc(s.toneProfessional)}</option>
                            <option value="casual">${this.esc(s.toneCasual)}</option>
                            <option value="luxury">${this.esc(s.toneLuxury)}</option>
                            <option value="discount">${this.esc(s.toneDiscount)}</option>
                        </select>
                        <button type="button" class="button pr-btn-copywriter">
                            ✍️ ${this.esc(s.generateCopy)}
                        </button>
                    </div>
                    <div class="pr-copywriter__output"></div>
                </div>
            `;

            // Actions bar
            html += `
                <div class="pr-actions">
                    <button type="button" class="button button-primary pr-btn-new">${this.esc(s.newAnalysis)}</button>
                    <button type="button" class="button pr-btn-export-csv">${this.esc(s.exportCsv)}</button>
                    <button type="button" class="button pr-btn-export-pdf">${this.esc(s.exportPdf)}</button>
                </div>
            `;

            // History toggle
            if (this.config.history && this.config.history.length > 1) {
                html += this.renderHistory();
            }

            html += `</div>`;
            this.root.innerHTML = html;

            this.bindResultEvents(report, competitors, summary);
        }

        showError(message) {
            const s = this.config.strings;
            this.root.innerHTML = `
                <div class="pr-error">
                    <div class="pr-error__icon">⚠️</div>
                    <p class="pr-error__message">${this.esc(message)}</p>
                    <button type="button" class="button button-primary pr-btn-retry">${this.esc(s.retry)}</button>
                </div>
            `;
            this.root.querySelector('.pr-btn-retry').addEventListener('click', () => this.startResearch());
        }

        // ─── AJAX Actions ──────────────────────────────────────────

        startResearch() {
            this.showProgress('searching', '');

            this.ajax('pr_start_research', {
                product_id: this.config.productId,
            }).then(data => {
                if (data.resuming) {
                    this.state.reportId = data.report_id;
                    this.resumeInProgress(data);
                    return;
                }

                this.state.reportId = data.report_id;
                this.state.searchResults = data.search_results || [];
                this.showPreview(data.search_results, data.urls, data.query);
            }).catch(err => {
                this.showError(err.message || 'Search failed');
            });
        }

        async confirmUrls() {
            const checkboxes = this.root.querySelectorAll('.pr-url-checkbox:checked');
            const urls = Array.from(checkboxes).map(cb => cb.value);

            if (urls.length === 0) {
                alert('Please select at least one competitor to analyze.');
                return;
            }

            this.showProgress('extracting', '');

            try {
                // Step 1: Extract content from URLs (fast)
                const extractData = await this.ajax('pr_confirm_urls', {
                    report_id: this.state.reportId,
                    selected_urls: JSON.stringify(urls),
                });

                const totalUrls = extractData.total_urls || 0;
                if (totalUrls === 0) {
                    this.showError('No content could be extracted from the selected URLs.');
                    return;
                }

                // Step 2: Analyze each URL one at a time (retry once on failure)
                for (let i = 0; i < totalUrls; i++) {
                    this.showProgress('analyzing', `Analyzing competitor ${i + 1} of ${totalUrls}...`);

                    try {
                        await this.ajax('pr_analyze_url', {
                            report_id: this.state.reportId,
                            url_index: i,
                        });
                    } catch (firstErr) {
                        // The server may still be processing (ignore_user_abort).
                        // Wait a few seconds then retry — the PHP check-before-analyze
                        // will return the cached result if the background process finished.
                        this.showProgress('analyzing', `Retrying competitor ${i + 1} of ${totalUrls}...`);
                        await new Promise(r => setTimeout(r, 5000));
                        try {
                            await this.ajax('pr_analyze_url', {
                                report_id: this.state.reportId,
                                url_index: i,
                            });
                        } catch (retryErr) {
                            // Let the per-URL error recording on the server handle it
                            console.warn(`URL ${i} failed after retry:`, retryErr.message);
                        }
                    }
                }

                // Step 3: Finalize the report
                this.showProgress('analyzing', 'Finalizing report...');

                const finalData = await this.ajax('pr_finalize_report', {
                    report_id: this.state.reportId,
                });

                this.showResults(finalData);
            } catch (err) {
                this.showError(err.message || 'Analysis failed');
            }
        }

        resumeInProgress(data) {
            this.state.reportId = data.report_id || data.id;
            const status = data.status;

            // formatReport() uses `competitor_data`; live AJAX uses `search_results`.
            const results = data.search_results || data.competitor_data;
            const query = data.query || data.search_query || '';

            if (status === 'previewing' && results) {
                this.showPreview(results, data.urls || data.selected_urls || [], query);
            } else if (status === 'complete') {
                this.loadReport(this.state.reportId);
            } else if (status === 'failed') {
                this.showError(data.progress_message || 'Previous analysis failed');
            } else {
                this.showProgress(status, data.progress_message);
                this.startPolling();
            }
        }

        loadReport(reportId) {
            this.ajax('pr_get_report', { report_id: reportId }, 'GET')
                .then(data => this.showResults(data))
                .catch(err => this.showError(err.message));
        }

        startPolling() {
            this.stopPolling();
            this.state.polling = setInterval(() => {
                this.ajax('pr_get_status', { report_id: this.state.reportId }, 'GET')
                    .then(data => {
                        if (data.status === 'complete') {
                            this.stopPolling();
                            this.loadReport(data.report_id);
                        } else if (data.status === 'failed') {
                            this.stopPolling();
                            this.showError(data.message || 'Analysis failed');
                        } else {
                            this.showProgress(data.status, data.message);
                        }
                    });
            }, 3000);
        }

        stopPolling() {
            if (this.state.polling) {
                clearInterval(this.state.polling);
                this.state.polling = null;
            }
        }

        // ─── Helpers ───────────────────────────────────────────────

        ajax(action, data = {}, method = 'POST') {
            const payload = { ...data, action, nonce: this.config.nonce };

            const options = {
                method,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            };

            if (method === 'POST') {
                options.body = new URLSearchParams(payload).toString();
            }

            const url = method === 'GET'
                ? `${this.config.ajaxUrl}?${new URLSearchParams(payload).toString()}`
                : this.config.ajaxUrl;

            return fetch(url, options)
                .then(r => r.json())
                .then(json => {
                    if (json.success) return json.data;
                    throw new Error(json.data?.message || 'Request failed');
                });
        }

        toggleAll(checked) {
            this.root.querySelectorAll('.pr-url-checkbox').forEach(cb => { cb.checked = checked; });
        }

        bindResultEvents(report, competitors = [], summary = {}) {
            const reportId = report.report_id || report.id;

            // Card toggle
            this.root.querySelectorAll('.pr-card__header').forEach(header => {
                header.addEventListener('click', (e) => {
                    // Don't toggle if clicking bookmark or compare
                    if (e.target.closest('.pr-bookmark') || e.target.closest('.pr-compare-check')) return;
                    header.parentElement.classList.toggle('pr-card--collapsed');
                });
            });

            // New analysis
            const btnNew = this.root.querySelector('.pr-btn-new');
            if (btnNew) btnNew.addEventListener('click', () => this.startResearch());

            // Export CSV
            const btnCsv = this.root.querySelector('.pr-btn-export-csv');
            if (btnCsv) {
                btnCsv.addEventListener('click', () => {
                    window.open(`${this.config.ajaxUrl}?action=pr_export_csv&report_id=${reportId}&nonce=${this.config.nonce}`, '_blank');
                });
            }

            // Export PDF
            const btnPdf = this.root.querySelector('.pr-btn-export-pdf');
            if (btnPdf) {
                btnPdf.addEventListener('click', () => {
                    window.open(`${this.config.ajaxUrl}?action=pr_export_pdf&report_id=${reportId}&nonce=${this.config.nonce}`, '_blank');
                });
            }

            // History items
            this.root.querySelectorAll('.pr-history__item').forEach(item => {
                item.addEventListener('click', () => {
                    const id = parseInt(item.dataset.reportId, 10);
                    this.loadReport(id);
                });
            });

            // Recommendations button
            const btnRecs = this.root.querySelector('.pr-btn-recommendations');
            if (btnRecs) {
                btnRecs.addEventListener('click', () => this.fetchRecommendations(reportId));
            }

            // ─── Chart.js Initialization ────────────────────────────
            this.initPriceChart(competitors, summary);

            // ─── Tab Switching ──────────────────────────────────────
            this.root.querySelectorAll('.pr-analytics__tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    this.root.querySelectorAll('.pr-analytics__tab').forEach(t => t.classList.remove('pr-analytics__tab--active'));
                    this.root.querySelectorAll('.pr-analytics__panel').forEach(p => p.classList.remove('pr-analytics__panel--active'));
                    tab.classList.add('pr-analytics__tab--active');
                    const panel = this.root.querySelector(`[data-panel="${tab.dataset.tab}"]`);
                    if (panel) panel.classList.add('pr-analytics__panel--active');

                    // Lazy init history chart
                    if (tab.dataset.tab === 'history' && !this.state.historyChartInstance) {
                        this.initHistoryChart();
                    }
                });
            });

            // ─── Bookmark Events ────────────────────────────────────
            this.root.querySelectorAll('.pr-bookmark').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (btn.disabled) return;
                    btn.disabled = true;

                    const url = btn.dataset.url;
                    const isActive = btn.classList.contains('pr-bookmark--active');
                    const action = isActive ? 'pr_remove_bookmark' : 'pr_add_bookmark';

                    this.ajax(action, { product_id: this.config.productId, url })
                        .then(() => {
                            btn.classList.toggle('pr-bookmark--active');
                            btn.innerHTML = btn.classList.contains('pr-bookmark--active') ? '★' : '☆';
                            // Update local state
                            if (btn.classList.contains('pr-bookmark--active')) {
                                if (!this.config.bookmarkedUrls.includes(url)) this.config.bookmarkedUrls.push(url);
                            } else {
                                this.config.bookmarkedUrls = this.config.bookmarkedUrls.filter(u => u !== url);
                            }
                        })
                        .finally(() => { btn.disabled = false; });
                });
            });

            // ─── Compare Events ─────────────────────────────────────
            const compareBar = this.root.querySelector('.pr-compare-bar');
            const compareBtnMain = this.root.querySelector('.pr-btn-compare');
            const compareCount = this.root.querySelector('.pr-compare-bar__count');

            this.root.querySelectorAll('.pr-compare-cb').forEach(cb => {
                cb.addEventListener('change', () => {
                    const checked = this.root.querySelectorAll('.pr-compare-cb:checked');
                    if (checked.length > 3) {
                        cb.checked = false;
                        return;
                    }
                    if (compareBar) {
                        compareBar.style.display = checked.length >= 2 ? 'flex' : 'none';
                        if (compareCount) compareCount.textContent = `${checked.length} selected`;
                    }
                });
            });

            if (compareBtnMain) {
                compareBtnMain.addEventListener('click', () => {
                    const indices = Array.from(this.root.querySelectorAll('.pr-compare-cb:checked')).map(cb => parseInt(cb.dataset.index, 10));
                    const selected = indices.map(i => competitors[i]).filter(Boolean);
                    if (selected.length >= 2) this.showComparisonOverlay(selected, summary);
                });
            }

            // ─── Copywriter Events ──────────────────────────────────
            const btnCopy = this.root.querySelector('.pr-btn-copywriter');
            if (btnCopy) {
                btnCopy.addEventListener('click', () => {
                    const tone = this.root.querySelector('.pr-copywriter__tone')?.value || 'professional';
                    this.fetchCopy(reportId, tone);
                });
            }
        }

        renderVariations(variations) {
            if (!variations.length) return '';
            const s = this.config.strings;
            let html = `<div class="pr-card__variations"><h5>${this.esc(s.variations)}</h5><table><thead><tr><th>Type</th><th>Value</th><th>Price</th><th>Status</th></tr></thead><tbody>`;
            variations.forEach(v => {
                html += `<tr><td>${this.esc(v.type)}</td><td>${this.esc(v.value)}</td><td>${v.price || '—'}</td><td>${this.esc(v.availability || '—')}</td></tr>`;
            });
            html += `</tbody></table></div>`;
            return html;
        }

        renderFeatures(features) {
            if (!features.length) return '';
            const s = this.config.strings;
            let html = `<div class="pr-card__features"><h5>${this.esc(s.features)}</h5><ul>`;
            features.forEach(f => { html += `<li>${this.esc(f)}</li>`; });
            html += `</ul></div>`;
            return html;
        }

        renderHistory() {
            const s = this.config.strings;
            const history = this.config.history || [];
            let html = `<div class="pr-history"><h4>${this.esc(s.viewHistory)}</h4><div class="pr-history__list">`;
            history.forEach(h => {
                const date = new Date(h.created_at).toLocaleDateString();
                const statusBadge = h.status === 'complete' ? '✅' : h.status === 'failed' ? '❌' : '⏳';
                html += `<div class="pr-history__item" data-report-id="${h.id}" role="button">${statusBadge} ${date}</div>`;
            });
            html += `</div></div>`;
            return html;
        }

        getPriceClass(price, avgPrice) {
            if (!avgPrice) return '';
            if (price < avgPrice * 0.9) return 'pr-price--low';
            if (price > avgPrice * 1.1) return 'pr-price--high';
            return 'pr-price--mid';
        }

        renderRecommendations(recommendations) {
            const s = this.config.strings;
            let html = `<h4 class="pr-recommendations__title">💡 ${this.esc(s.recommendations)}</h4>`;
            html += `<div class="pr-recommendations__list">`;
            recommendations.forEach(rec => {
                const validPriorities = ['high', 'medium', 'low'];
                const priority = validPriorities.includes(rec.priority) ? rec.priority : 'medium';
                const priorityClass = `pr-rec--${priority}`;
                html += `
                    <div class="pr-rec ${priorityClass}">
                        <span class="pr-rec__priority">${this.esc(rec.priority || 'medium')}</span>
                        <h5 class="pr-rec__title">${this.esc(rec.title)}</h5>
                        <p class="pr-rec__desc">${this.esc(rec.description)}</p>
                    </div>
                `;
            });
            html += `</div>`;
            return html;
        }

        fetchRecommendations(reportId) {
            const s = this.config.strings;
            const panel = this.root.querySelector('#pr-recommendations-panel');
            if (!panel) return;

            panel.innerHTML = `
                <div class="pr-recommendations__loading">
                    <div class="pr-progress__spinner"></div>
                    <p>${this.esc(s.loadingRecs)}</p>
                </div>
            `;

            this.ajax('pr_get_recommendations', { report_id: reportId })
                .then(data => {
                    panel.innerHTML = this.renderRecommendations(data.recommendations || []);
                })
                .catch(() => {
                    panel.innerHTML = `
                        <p class="pr-recommendations__error">${this.esc(s.recsFailed)}</p>
                        <button type="button" class="button pr-btn-recommendations">
                            💡 ${this.esc(s.getRecommendations)}
                        </button>
                    `;
                    // Re-bind retry
                    const retryBtn = panel.querySelector('.pr-btn-recommendations');
                    if (retryBtn) {
                        retryBtn.addEventListener('click', () => this.fetchRecommendations(reportId));
                    }
                });
        }

        // ─── Price Chart (HTML container) ───────────────────────────────
        renderPriceChart(competitors, summary) {
            const s = this.config.strings;
            const prices = competitors.filter(c => (c.converted_price ?? c.current_price) > 0 && c.conversion_status !== 'failed').map(c => ({
                name: c.name,
                price: parseFloat(c.converted_price ?? c.current_price),
                currency: c.store_currency || c.currency || ''
            }));

            if (!prices.length) {
                return `<p class="pr-chart-empty">${this.esc(s.chartNoData)}</p>`;
            }

            // Add the user's product
            const productPrice = parseFloat(this.config.productPrice) || 0;
            if (productPrice > 0) {
                prices.unshift({
                    name: s.yourProduct,
                    price: productPrice,
                    currency: this.config.productCurrencyCode || ''
                });
            }

            return `<canvas id="pr-price-chart" height="300"></canvas>`;
        }

        // ─── Price History (HTML container) ──────────────────────────────
        renderPriceHistory() {
            const s = this.config.strings;
            const history = this.config.priceHistory || [];

            if (history.length < 2) {
                return `<p class="pr-chart-empty">${this.esc(s.historyNoData)}</p>`;
            }

            return `<canvas id="pr-history-chart" height="300"></canvas>`;
        }

        // ─── Initialize Price Comparison Chart (Chart.js) ───────────────
        initPriceChart(competitors, summary) {
            const canvas = this.root.querySelector('#pr-price-chart');
            if (!canvas || typeof Chart === 'undefined') return;

            const productPrice = parseFloat(this.config.productPrice) || 0;
            const s = this.config.strings;

            const items = [];
            if (productPrice > 0) {
                items.push({ label: s.yourProduct, price: productPrice, isOwn: true });
            }
            competitors.forEach(c => {
                const price = parseFloat(c.converted_price ?? c.current_price);
                if (price > 0 && c.conversion_status !== 'failed') {
                    items.push({ label: c.name?.substring(0, 20), price: price, isOwn: false });
                }
            });

            if (!items.length) return;

            const avgPrice = parseFloat(summary.avg_price) || 0;

            this.state.priceChartInstance = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: items.map(i => i.label),
                    datasets: [{
                        label: s.priceChart,
                        data: items.map(i => i.price),
                        backgroundColor: items.map(i => i.isOwn ? '#2271b1' : '#dcdcde'),
                        borderColor: items.map(i => i.isOwn ? '#135e96' : '#bbb'),
                        borderWidth: 1,
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        annotation: avgPrice > 0 ? {
                            annotations: {
                                avgLine: {
                                    type: 'line',
                                    yMin: avgPrice,
                                    yMax: avgPrice,
                                    borderColor: '#d63638',
                                    borderWidth: 2,
                                    borderDash: [5, 5],
                                    label: {
                                        display: true,
                                        content: `Avg: ${avgPrice}`,
                                        position: 'start'
                                    }
                                }
                            }
                        } : {}
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            title: { display: true, text: 'Price' }
                        }
                    }
                }
            });
        }

        // ─── Initialize Price History Chart (Chart.js) ──────────────────
        initHistoryChart() {
            const canvas = this.root.querySelector('#pr-history-chart');
            if (!canvas || typeof Chart === 'undefined') return;

            const history = this.config.priceHistory || [];
            if (history.length < 2) return;

            const labels = history.map(h => h.date || '');

            this.state.historyChartInstance = new Chart(canvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Your Price',
                            data: history.map(h => h.product_price),
                            borderColor: '#2271b1',
                            backgroundColor: 'rgba(34, 113, 177, 0.1)',
                            fill: true,
                            tension: 0.3,
                        },
                        {
                            label: 'Avg Competitor',
                            data: history.map(h => h.avg_price),
                            borderColor: '#d63638',
                            borderDash: [5, 5],
                            fill: false,
                            tension: 0.3,
                        },
                        {
                            label: 'Lowest',
                            data: history.map(h => h.lowest_price),
                            borderColor: '#00a32a',
                            borderDash: [2, 2],
                            fill: false,
                            tension: 0.3,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' }
                    },
                    scales: {
                        y: { beginAtZero: false, title: { display: true, text: 'Price' } }
                    }
                }
            });
        }

        // ─── Side-by-Side Comparison Overlay ────────────────────────────
        showComparisonOverlay(selected, summary) {
            const s = this.config.strings;
            const productPrice = parseFloat(this.config.productPrice) || 0;

            // Build comparison table
            let rows = '';
            const fields = [
                { key: 'current_price', label: 'Price' },
                { key: 'converted_price', label: 'Converted Price' },
                { key: 'store_currency', label: 'Store Currency' },
                { key: 'original_price', label: 'Original Price' },
                { key: 'currency', label: 'Currency' },
                { key: 'availability', label: 'Availability' },
                { key: 'shipping_info', label: 'Shipping' },
                { key: 'rating', label: 'Rating' },
                { key: 'seller_name', label: 'Seller' },
            ];

            // Header row
            let headerCols = `<th>Attribute</th>`;
            if (productPrice > 0) headerCols += `<th>${this.esc(s.yourProduct)}</th>`;
            selected.forEach(c => { headerCols += `<th>${this.esc(c.name?.substring(0, 25))}</th>`; });

            fields.forEach(f => {
                let row = `<td><strong>${this.esc(f.label)}</strong></td>`;
                if (productPrice > 0) {
                    if (f.key === 'current_price') {
                        row += `<td>${this.config.productCurrency} ${productPrice}</td>`;
                    } else if (f.key === 'converted_price') {
                        row += `<td>${this.config.productCurrencyCode || ''} ${productPrice}</td>`;
                    } else if (f.key === 'store_currency') {
                        row += `<td>${this.config.productCurrencyCode || ''}</td>`;
                    } else {
                        row += `<td>–</td>`;
                    }
                }
                selected.forEach(c => {
                    const val = c[f.key];
                    if (f.key === 'current_price' && val) {
                        const cls = this.getPriceClass(val, summary.avg_price);
                        row += `<td class="${cls}">${this.esc(c.currency || '')} ${this.esc(String(val))}</td>`;
                    } else if (f.key === 'converted_price' && val) {
                        const isFailed = c.conversion_status === 'failed';
                        const warn = isFailed ? ' <span class="pr-conversion-warn">[!]</span>' : '';
                        row += `<td>${this.esc(c.store_currency || '')} ${parseFloat(val).toFixed(2)}${warn}</td>`;
                    } else {
                        row += `<td>${val ? this.esc(String(val)) : '–'}</td>`;
                    }
                });
                rows += `<tr>${row}</tr>`;
            });

            // Features row
            let featRow = `<td><strong>Features</strong></td>`;
            if (productPrice > 0) featRow += `<td>–</td>`;
            selected.forEach(c => {
                const feats = (c.features || []).slice(0, 5).map(f => this.esc(f)).join('<br>');
                featRow += `<td>${feats || '–'}</td>`;
            });
            rows += `<tr>${featRow}</tr>`;

            const overlay = document.createElement('div');
            overlay.className = 'pr-comparison-overlay';
            overlay.innerHTML = `
                <div class="pr-comparison-modal">
                    <div class="pr-comparison-modal__header">
                        <h3>${this.esc(s.comparisonTitle)}</h3>
                        <button type="button" class="pr-comparison-modal__close">&times;</button>
                    </div>
                    <div class="pr-comparison-modal__body">
                        <table class="pr-comparison-table">
                            <thead><tr>${headerCols}</tr></thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);

            overlay.querySelector('.pr-comparison-modal__close').addEventListener('click', () => {
                overlay.remove();
            });
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) overlay.remove();
            });
        }

        // ─── Fetch AI Copy ──────────────────────────────────────────────
        fetchCopy(reportId, tone) {
            const s = this.config.strings;
            const output = this.root.querySelector('.pr-copywriter__output');
            const btn = this.root.querySelector('.pr-btn-copywriter');
            if (!output || !btn) return;

            btn.disabled = true;
            output.innerHTML = `
                <div class="pr-recommendations__loading">
                    <div class="pr-progress__spinner"></div>
                    <p>${this.esc(s.copyLoading)}</p>
                </div>
            `;

            this.ajax('pr_generate_copy', { report_id: reportId, tone })
                .then(data => {
                    const copy = data.copy || {};
                    output.innerHTML = `
                        <div class="pr-copy-result">
                            <h4>${this.esc(s.copyTitle)}</h4>
                            <div class="pr-copy-result__section">
                                <label>Title</label>
                                <p class="pr-copy-result__title">${this.esc(copy.title)}</p>
                            </div>
                            <div class="pr-copy-result__section">
                                <label>Short Description</label>
                                <p>${this.esc(copy.short_description)}</p>
                            </div>
                            <div class="pr-copy-result__section">
                                <label>Full Description</label>
                                <div class="pr-copy-result__full">${copy.full_description || ''}</div>
                            </div>
                            ${copy.seo_keywords?.length ? `
                                <div class="pr-copy-result__section">
                                    <label>SEO Keywords</label>
                                    <p>${copy.seo_keywords.map(k => `<span class="pr-tag">${this.esc(k)}</span>`).join(' ')}</p>
                                </div>
                            ` : ''}
                            <div class="pr-copy-result__actions">
                                <button type="button" class="button button-primary pr-btn-apply-copy">
                                    ${this.esc(s.applyCopy)}
                                </button>
                            </div>
                        </div>
                    `;

                    // Bind apply button
                    const applyBtn = output.querySelector('.pr-btn-apply-copy');
                    if (applyBtn) {
                        applyBtn.addEventListener('click', () => {
                            this.applyCopy(copy);
                        });
                    }
                })
                .catch(() => {
                    output.innerHTML = `<p class="pr-recommendations__error">${this.esc(s.copyFailed)}</p>`;
                })
                .finally(() => { btn.disabled = false; });
        }

        // ─── Apply Copy to WP editor ────────────────────────────────────
        applyCopy(copy) {
            const s = this.config.strings;

            // Apply title
            const titleField = document.getElementById('title');
            if (titleField && copy.title) {
                this.state.originalDescription = this.state.originalDescription || titleField.value;
                titleField.value = copy.title;
            }

            // Apply to excerpt
            const excerptField = document.getElementById('excerpt');
            if (excerptField && copy.short_description) {
                excerptField.value = copy.short_description;
            }

            // Apply to the content editor (TinyMCE or textarea)
            if (copy.full_description) {
                if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                    tinymce.get('content').setContent(copy.full_description);
                } else {
                    const contentArea = document.getElementById('content');
                    if (contentArea) contentArea.value = copy.full_description;
                }
            }

            // Show success notice
            const output = this.root.querySelector('.pr-copywriter__output');
            if (output) {
                const notice = document.createElement('div');
                notice.className = 'pr-notice pr-notice--success';
                notice.textContent = s.copyApplied;
                output.prepend(notice);
                setTimeout(() => notice.remove(), 3000);
            }
        }

        esc(str) {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        escAttr(str) {
            return (str || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
    }

    // Initialize on DOM ready
    $(function () {
        const root = document.getElementById('pr-metabox-root');
        if (root) {
            new ProductResearchMetabox(root);
        }
    });

})(jQuery);
