<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow\Nodes;

use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\StopEvent;
use NeuronAI\Workflow\WorkflowState;
use ProductResearch\AI\Schema\CompetitorProfile;
use ProductResearch\AI\Workflow\Events\AnalysisCompletedEvent;
use ProductResearch\Report\ReportPostType;
use ProductResearch\Report\ReportRepository;

/**
 * Report node: structures analyzed data into a final report.
 *
 * Builds summary dashboard data, serializes CompetitorProfile objects
 * to arrays for JSON storage, and saves to ReportRepository.
 */
final class ReportNode extends Node
{
    private ReportRepository $reports;

    public function __construct(ReportRepository $reports)
    {
        $this->reports = $reports;
    }

    public function __invoke(AnalysisCompletedEvent $event, WorkflowState $state): StopEvent
    {
        $reportId = $state->get('report_id');

        $profiles = $event->competitorProfiles;
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
     * @param CompetitorProfile[] $profiles
     * @return array<string, mixed>
     */
    private function buildSummary(array $profiles, WorkflowState $state): array
    {
        if (empty($profiles)) {
            return $this->emptySummary();
        }

        $prices = array_map(
            static fn(CompetitorProfile $p): float => $p->currentPrice,
            $profiles
        );

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
     * @param CompetitorProfile[] $profiles
     * @return array<int, array<string, mixed>>
     */
    private function buildPriceRangeData(array $profiles): array
    {
        return array_map(static fn(CompetitorProfile $p): array => [
            'name'  => $p->name,
            'price' => $p->currentPrice,
            'url'   => $p->url,
        ], $profiles);
    }

    /**
     * Find features that appear across multiple competitors.
     *
     * @param CompetitorProfile[] $profiles
     * @return array<string>
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
     * @param CompetitorProfile[] $profiles
     * @return array<string>
     */
    private function generateKeyFindings(array $profiles, WorkflowState $state): array
    {
        $findings = [];
        $prices   = array_map(fn(CompetitorProfile $p): float => $p->currentPrice, $profiles);

        $findings[] = sprintf(
            __('Price range: %s – %s across %d competitors', 'product-research'),
            $this->formatPrice(min($prices), $profiles[0]->currency),
            $this->formatPrice(max($prices), $profiles[0]->currency),
            count($profiles)
        );

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
     * Format a price with currency.
     */
    private function formatPrice(float $price, string $currency): string
    {
        return sprintf('%s %.2f', $currency, $price);
    }

    /**
     * Return an empty summary structure.
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
