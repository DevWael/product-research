<?php

declare(strict_types=1);

namespace ProductResearch\AI\Workflow\Nodes;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use ProductResearch\AI\Agent\ProductAnalysisAgent;
use ProductResearch\AI\Schema\CompetitorProfile;
use ProductResearch\AI\Workflow\Events\AnalysisCompletedEvent;
use ProductResearch\AI\Workflow\Events\ExtractionCompletedEvent;
use ProductResearch\Report\ReportPostType;
use ProductResearch\Report\ReportRepository;
use ProductResearch\Security\Logger;

/**
 * Analyze node: uses AI to extract structured product data from sanitized content.
 *
 * Calls ProductAnalysisAgent with Neuron structured output to get typed
 * CompetitorProfile objects. Validates URL domains and flags suspicious uniformity.
 *
 * @package ProductResearch\AI\Workflow\Nodes
 * @since   1.0.0
 */
final class AnalyzeNode extends Node
{
    private const MAX_RETRIES = 3;

    private ReportRepository $reports;
    private Logger $logger;

    /**
     * Create the analysis node.
     *
     * @since 1.0.0
     *
     * @param ReportRepository $reports Report persistence.
     * @param Logger           $logger  Sanitized logging.
     */
    public function __construct(
        ReportRepository $reports,
        Logger $logger
    ) {
        $this->reports = $reports;
        $this->logger  = $logger;
    }

    /**
     * Execute the AI analysis step of the workflow.
     *
     * Iterates over extracted competitor data, invokes the AI agent for
     * each, validates URL domains, and flags suspiciously uniform results.
     *
     * @since 1.0.0
     *
     * @param  ExtractionCompletedEvent $event Extracted competitor content.
     * @param  WorkflowState            $state Shared state (report_id).
     * @return AnalysisCompletedEvent
     */
    public function __invoke(ExtractionCompletedEvent $event, WorkflowState $state): AnalysisCompletedEvent
    {
        $reportId = $state->get('report_id');
        $total    = count($event->extractedData);
        $profiles = [];
        $skipped  = 0;

        $this->reports->updateStatus(
            $reportId,
            ReportPostType::STATUS_ANALYZING,
            sprintf(__('Analyzing %d competitors with AI...', 'product-research'), $total)
        );

        foreach ($event->extractedData as $index => $competitor) {
            $url     = $competitor['url'] ?? '';
            $content = $competitor['content'] ?? '';

            if ($content === '') {
                $skipped++;
                continue;
            }

            try {
                $profile = $this->analyzeCompetitor($content, $url);

                if ($profile !== null) {
                    $profiles[] = $profile;
                }
            } catch (\Throwable $e) {
                $this->logger->log(
                    sprintf('AI analysis failed for %s: %s', $url, $e->getMessage()),
                    'warning'
                );
                $skipped++;
            }

            $this->reports->updateStatus(
                $reportId,
                ReportPostType::STATUS_ANALYZING,
                sprintf(
                    __('Analyzing competitor %d/%d...', 'product-research'),
                    $index + 1,
                    $total
                )
            );
        }

        // Flag if all profiles look suspiciously uniform
        if (count($profiles) > 2) {
            $this->checkForUniformity($profiles);
        }

        if ($skipped > 0) {
            $this->logger->log(
                sprintf('Skipped %d/%d competitors during analysis', $skipped, $total),
                'warning'
            );
        }

        return new AnalysisCompletedEvent($profiles);
    }

    /**
     * Analyze a single competitor using Neuron structured output.
     *
     * Invokes {@see ProductAnalysisAgent} with the sanitized content and
     * validates that the returned URL domain matches the source.
     *
     * @since 1.0.0
     *
     * @param  string $content   Sanitized page content.
     * @param  string $sourceUrl Original URL the content was extracted from.
     * @return CompetitorProfile|null Null if the agent returned an unexpected type.
     */
    private function analyzeCompetitor(string $content, string $sourceUrl): ?CompetitorProfile
    {
        $prompt = sprintf(
            "Analyze the following product page content and extract all product details.\n\n" .
            "Source URL: %s\n\n" .
            "Content:\n%s",
            $sourceUrl,
            $content
        );

        $profile = ProductAnalysisAgent::make()
            ->structured(CompetitorProfile::class)
            ->chat(new UserMessage($prompt));

        if (! $profile instanceof CompetitorProfile) {
            return null;
        }

        // Additional domain validation: URL domain must match source
        if (! $this->validateUrlDomain($profile->url, $sourceUrl)) {
            $this->logger->log(
                sprintf('Profile URL domain mismatch: expected domain of %s, got %s', $sourceUrl, $profile->url),
                'warning'
            );
            // Override with the known source URL
            $profile->url = $sourceUrl;
        }

        return $profile;
    }

    /**
     * Validate that the profile URL domain matches the source domain.
     *
     * @since 1.0.0
     *
     * @param  string $profileUrl URL returned by the AI agent.
     * @param  string $sourceUrl  Original competitor URL.
     * @return bool   True if the domains match.
     */
    private function validateUrlDomain(string $profileUrl, string $sourceUrl): bool
    {
        $profileHost = parse_url($profileUrl, PHP_URL_HOST);
        $sourceHost  = parse_url($sourceUrl, PHP_URL_HOST);

        if ($profileHost === null || $sourceHost === null) {
            return false;
        }

        return $profileHost === $sourceHost;
    }

    /**
     * Check for suspiciously uniform competitor profiles.
     *
     * @param CompetitorProfile[] $profiles
     */
    private function checkForUniformity(array $profiles): void
    {
        $names  = array_map(fn(CompetitorProfile $p): string => $p->name, $profiles);
        $prices = array_map(fn(CompetitorProfile $p): float => $p->currentPrice, $profiles);

        $uniqueNames  = count(array_unique($names));
        $uniquePrices = count(array_unique($prices));

        if ($uniqueNames === 1 || $uniquePrices === 1) {
            $this->logger->log(
                'All competitor profiles appear suspiciously uniform — may indicate extraction or analysis issue',
                'warning'
            );
        }
    }
}
