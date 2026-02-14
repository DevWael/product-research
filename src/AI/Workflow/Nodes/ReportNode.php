<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow\Nodes;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\WorkflowState;
use ProductResearch\AI\Schema\CompetitorProfile;
use ProductResearch\AI\Workflow\Events\AnalysisCompletedEvent;
use ProductResearch\Currency\CurrencyConverter;
use ProductResearch\Report\ReportPostType;
use ProductResearch\Report\ReportRepository;

/**
 * Report node: structures analyzed data into a final report.
 *
 * Builds summary dashboard data, serializes CompetitorProfile objects
 * to arrays for JSON storage, and saves to ReportRepository.
 *
 * @package ProductResearch\AI\Workflow\Nodes
 * @since   1.0.0
 */
final class ReportNode extends Node
{
    private ReportRepository $reports;
    private CurrencyConverter $converter;

    /**
     * Create the report node.
     *
     * @since 1.0.0
     *
     * @param ReportRepository  $reports   Report persistence.
     * @param CurrencyConverter $converter Price normalization service.
     */
    public function __construct(ReportRepository $reports, CurrencyConverter $converter)
    {
        $this->reports   = $reports;
        $this->converter = $converter;
    }

    /**
     * Execute the report-building step of the workflow.
     *
     * Normalizes all prices to the store's currency, builds a summary
     * dashboard, serializes profiles, saves results, and emits a StopEvent.
     *
     * @since 1.0.0
     *
     * @param  AnalysisCompletedEvent $event Analyzed competitor profiles.
     * @param  WorkflowState          $state Shared state (report_id).
     * @return StopEvent              Terminates the workflow.
     */
    public function __invoke(AnalysisCompletedEvent $event, WorkflowState $state): StopEvent
    {
        $reportId = $state->get('report_id');

        $profiles = $event->competitorProfiles;

        // Normalize all prices to store currency before building summary.
        $storeCurrency = $this->converter->getStoreCurrency();
        $this->converter->normalizeProfileObjects($profiles, $storeCurrency);

        $summary  = $this->buildSummary($profiles, $state);

        $report = [
            'competitors' => array_map(
                static fn(CompetitorProfile $p): array => $p->toArray(),
                $profiles
            ),
            'summary' => $summary,
        ];

        $this->reports->update($reportId, [
            ReportPostType::META_ANALYSIS_RESULT => $report,
        ]);

        $this->reports->updateStatus(
            $reportId,
            ReportPostType::STATUS_COMPLETE,
            __('Analysis complete', 'product-research')
        );

        return new StopEvent($report);
    }

    /**
     * Build summary dashboard data from competitor profiles.
     *
     * Calculates lowest, highest, and average prices; builds chart data;
     * identifies common features; and generates key findings.
     *
     * @since 1.0.0
     *
     * @param  CompetitorProfile[] $profiles Analyzed competitor profiles.
     * @param  WorkflowState       $state    Shared workflow state.
     * @return array<string, mixed> Dashboard summary data.
     */
    private function buildSummary(array $profiles, WorkflowState $state): array
    {
        if (empty($profiles)) {
            return $this->emptySummary();
        }

        $prices = CurrencyConverter::extractValidPricesFromObjects($profiles);

        if (empty($prices)) {
            return $this->emptySummary();
        }

        $lowestPrice  = min($prices);
        $highestPrice = max($prices);
        $avgPrice     = array_sum($prices) / count($prices);

        return [
            'total_competitors' => count($profiles),
            'lowest_price'      => round($lowestPrice, 2),
            'highest_price'     => round($highestPrice, 2),
            'avg_price'         => round($avgPrice, 2),
            'price_range_data'  => $this->buildPriceRangeData($profiles),
            'common_features'   => $this->findCommonFeatures($profiles),
            'key_findings'      => $this->generateKeyFindings($profiles, $state),
        ];
    }

    /**
     * Build price range chart data.
     *
     * @since 1.0.0
     *
     * @param  CompetitorProfile[] $profiles Analyzed competitor profiles.
     * @return array<int, array<string, mixed>> Chart-ready price data.
     */
    private function buildPriceRangeData(array $profiles): array
    {
        return array_map(static fn(CompetitorProfile $p): array => [
            'name'              => $p->name,
            'price'             => $p->convertedPrice ?? $p->currentPrice,
            'url'               => $p->url,
            'converted_price'   => $p->convertedPrice,
            'conversion_status' => $p->conversionStatus,
        ], $profiles);
    }

    /**
     * Find features that appear across multiple competitors.
     *
     * Returns up to 10 features that appear in 2+ competitor profiles,
     * sorted by frequency (descending).
     *
     * @since 1.0.0
     *
     * @param  CompetitorProfile[] $profiles Analyzed competitor profiles.
     * @return array<string> Normalized feature strings.
     */
    private function findCommonFeatures(array $profiles): array
    {
        $featureCounts = [];

        foreach ($profiles as $profile) {
            foreach ($profile->features as $feature) {
                $normalized = mb_strtolower(trim($feature));
                $featureCounts[$normalized] = ($featureCounts[$normalized] ?? 0) + 1;
            }
        }

        // Keep features appearing in 2+ competitors
        $common = array_filter($featureCounts, static fn(int $count): bool => $count >= 2);
        arsort($common);

        return array_slice(array_keys($common), 0, 10);
    }

    /**
     * Generate key findings text from analysis.
     *
     * Produces human-readable sentences about price ranges, discounts,
     * availability, variations, and conversion warnings.
     *
     * @since 1.0.0
     *
     * @param  CompetitorProfile[] $profiles Analyzed competitor profiles.
     * @param  WorkflowState       $state    Shared workflow state.
     * @return array<string>       Finding sentences for the dashboard.
     */
    private function generateKeyFindings(array $profiles, WorkflowState $state): array
    {
        $findings = [];

        $prices = CurrencyConverter::extractValidPricesFromObjects($profiles);

        if (! empty($prices)) {
            $storeCurrency = $profiles[0]->storeCurrency ?? $profiles[0]->currency;
            $findings[]    = sprintf(
                __('Price range: %s – %s across %d competitors', 'product-research'),
                $this->formatPrice(min($prices), $storeCurrency),
                $this->formatPrice(max($prices), $storeCurrency),
                count($profiles)
            );
        }

        // Failed conversion warning
        $failed = array_filter($profiles, static fn(CompetitorProfile $p): bool => $p->conversionStatus === 'failed');
        if (! empty($failed)) {
            $findings[] = sprintf(
                __('[!] %d competitor(s) could not have their prices converted — excluded from averages', 'product-research'),
                count($failed)
            );
        }

        // Check for discounted products
        $discounted = array_filter($profiles, static fn(CompetitorProfile $p): bool => $p->originalPrice !== null);
        if (! empty($discounted)) {
            $findings[] = sprintf(
                __('%d competitor(s) currently offering discounts', 'product-research'),
                count($discounted)
            );
        }

        // Check availability
        $outOfStock = array_filter(
            $profiles,
            static fn(CompetitorProfile $p): bool => $p->availability !== null && stripos($p->availability, 'out') !== false
        );
        if (! empty($outOfStock)) {
            $findings[] = sprintf(
                __('%d competitor(s) currently out of stock', 'product-research'),
                count($outOfStock)
            );
        }

        // Variation count
        $withVariations = array_filter($profiles, static fn(CompetitorProfile $p): bool => ! empty($p->variations));
        if (! empty($withVariations)) {
            $findings[] = sprintf(
                __('%d competitor(s) offer product variations', 'product-research'),
                count($withVariations)
            );
        }

        return $findings;
    }

    /**
     * Format a price with currency code prefix.
     *
     * @since 1.0.0
     *
     * @param  float  $price    The numeric price value.
     * @param  string $currency Currency code (e.g. 'USD').
     * @return string Formatted price string, e.g. "USD 29.99".
     */
    private function formatPrice(float $price, string $currency): string
    {
        return sprintf('%s %.2f', $currency, $price);
    }

    /**
     * Return an empty summary structure.
     *
     * Used when no competitor profiles were successfully analyzed.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'total_competitors' => 0,
            'lowest_price'      => 0,
            'highest_price'     => 0,
            'avg_price'         => 0,
            'price_range_data'  => [],
            'common_features'   => [],
            'key_findings'      => [__('No competitor data found', 'product-research')],
        ];
    }
}
