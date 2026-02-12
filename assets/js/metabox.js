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

            // Competitor Cards
            if (competitors.length) {
                html += `<div class="pr-competitors">`;
                competitors.forEach((comp, i) => {
                    const priceClass = this.getPriceClass(comp.current_price, summary.avg_price);
                    html += `
                        <div class="pr-card pr-card--collapsed" data-index="${i}">
                            <div class="pr-card__header" role="button">
                                <span class="pr-card__name">${this.esc(comp.name)}</span>
                                <span class="pr-card__price ${priceClass}">
                                    ${comp.currency} ${comp.current_price}
                                </span>
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
                                </div>
                                ${this.renderVariations(comp.variations || [])}
                                ${this.renderFeatures(comp.features || [])}
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            } else {
                html += `<p class="pr-no-results">${this.esc(s.noResults)}</p>`;
            }

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

            this.bindResultEvents(report);
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

        bindResultEvents(report) {
            // Card toggle
            this.root.querySelectorAll('.pr-card__header').forEach(header => {
                header.addEventListener('click', () => {
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
                    const reportId = report.report_id || report.id;
                    window.open(`${this.config.ajaxUrl}?action=pr_export_csv&report_id=${reportId}&nonce=${this.config.nonce}`, '_blank');
                });
            }

            // Export PDF
            const btnPdf = this.root.querySelector('.pr-btn-export-pdf');
            if (btnPdf) {
                btnPdf.addEventListener('click', () => {
                    const reportId = report.report_id || report.id;
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
