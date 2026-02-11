<?php

declare(strict_types=1);

namespace ProductResearch\Admin;

use ProductResearch\Security\Encryption;

/**
 * Settings page under WooCommerce menu.
 *
 * Manages API keys (encrypted), search/analysis configuration,
 * security controls (capability, cooldown, credit budget).
 */
final class SettingsPage
{
    private const PAGE_SLUG    = 'pr-settings';
    private const OPTION_GROUP = 'pr_settings_group';
    private const SECTION_API  = 'pr_section_api';
    private const SECTION_CFG  = 'pr_section_config';
    private const SECTION_SEC  = 'pr_section_security';

    private Encryption $encryption;

    public function __construct(Encryption $encryption)
    {
        $this->encryption = $encryption;
    }

    /**
     * Register the settings submenu page under WooCommerce.
     */
    public function registerMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Product Research Settings', 'product-research'),
            __('Product Research', 'product-research'),
            $this->getRequiredCapability(),
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Register all settings fields.
     */
    public function registerSettings(): void
    {
        $this->registerApiSection();
        $this->registerConfigSection();
        $this->registerSecuritySection();
    }

    /**
     * Render the settings page HTML.
     */
    public function renderPage(): void
    {
        if (! current_user_can($this->getRequiredCapability())) {
            wp_die(esc_html__('You do not have permission to access this page.', 'product-research'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Product Research Settings', 'product-research') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::PAGE_SLUG);
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    /**
     * Register API Keys section.
     */
    private function registerApiSection(): void
    {
        add_settings_section(
            self::SECTION_API,
            __('API Configuration', 'product-research'),
            static fn() => printf(
                '<p>%s</p>',
                esc_html__('Configure your AI and search API credentials. Keys are encrypted before storage.', 'product-research')
            ),
            self::PAGE_SLUG
        );

        $this->addEncryptedField('pr_zai_api_key', __('Z.AI API Key', 'product-research'), self::SECTION_API);
        $this->addTextField('pr_zai_model', __('Z.AI Model', 'product-research'), self::SECTION_API, 'glm-4.7');
        $this->addTextField(
            'pr_zai_endpoint',
            __('Z.AI Endpoint URL', 'product-research'),
            self::SECTION_API,
            'https://api.z.ai/api/coding/paas/v4/chat/completions'
        );
        $this->addEncryptedField('pr_tavily_api_key', __('Tavily API Key', 'product-research'), self::SECTION_API);
    }

    /**
     * Register Search & Analysis Configuration section.
     */
    private function registerConfigSection(): void
    {
        add_settings_section(
            self::SECTION_CFG,
            __('Search & Analysis', 'product-research'),
            null,
            self::PAGE_SLUG
        );

        $this->addSelectField('pr_tavily_search_depth', __('Search Depth', 'product-research'), self::SECTION_CFG, [
            'fast'     => __('Fast', 'product-research'),
            'basic'    => __('Basic', 'product-research'),
            'advanced' => __('Advanced', 'product-research'),
        ], 'fast');

        $this->addSelectField('pr_tavily_extract_depth', __('Extract Depth', 'product-research'), self::SECTION_CFG, [
            'basic'    => __('Basic', 'product-research'),
            'advanced' => __('Advanced', 'product-research'),
        ], 'basic');

        $this->addCheckboxField('pr_include_images', __('Include Images', 'product-research'), self::SECTION_CFG, true);
        $this->addNumberField('pr_max_search_results', __('Max Search Results', 'product-research'), self::SECTION_CFG, 10, 1, 20);
        $this->addNumberField('pr_max_competitors', __('Max Competitors to Analyze', 'product-research'), self::SECTION_CFG, 5, 1, 10);
        $this->addNumberField('pr_token_budget', __('Token Budget per Competitor', 'product-research'), self::SECTION_CFG, 4000, 500, 16000);
        $this->addNumberField('pr_cache_ttl', __('Cache TTL (hours)', 'product-research'), self::SECTION_CFG, 24, 1, 168);
    }

    /**
     * Register Security section.
     */
    private function registerSecuritySection(): void
    {
        add_settings_section(
            self::SECTION_SEC,
            __('Security & Limits', 'product-research'),
            null,
            self::PAGE_SLUG
        );

        $this->addSelectField('pr_capability', __('Required Capability', 'product-research'), self::SECTION_SEC, [
            'edit_products'  => __('Edit Products', 'product-research'),
            'manage_options' => __('Manage Options (Admin)', 'product-research'),
        ], 'edit_products');

        $this->addNumberField('pr_cooldown_minutes', __('Analysis Cooldown (minutes)', 'product-research'), self::SECTION_SEC, 5, 0, 60);
        $this->addNumberField('pr_daily_credit_budget', __('Daily API Credit Budget (0 = unlimited)', 'product-research'), self::SECTION_SEC, 0, 0, 10000);
    }

    /**
     * Add an encrypted password field.
     */
    private function addEncryptedField(string $id, string $label, string $section): void
    {
        register_setting(self::OPTION_GROUP, $id, [
            'type'              => 'string',
            'sanitize_callback' => fn(mixed $value): string => $this->sanitizeEncryptedField($id, $value),
        ]);

        add_settings_field($id, $label, function () use ($id): void {
            $hasValue = get_option($id, '') !== '';
            $status   = $hasValue
                ? '<span style="color:green;">● ' . esc_html__('Connected', 'product-research') . '</span>'
                : '<span style="color:red;">○ ' . esc_html__('Not set', 'product-research') . '</span>';

            printf(
                '<input type="password" id="%1$s" name="%1$s" value="" class="regular-text" placeholder="%2$s" autocomplete="off" /> %3$s',
                esc_attr($id),
                $hasValue ? esc_attr__('Enter new key to update', 'product-research') : '',
                $status
            );
        }, self::PAGE_SLUG, $section);
    }

    /**
     * Sanitize and encrypt API key fields.
     * Keeps existing value if blank submitted.
     */
    private function sanitizeEncryptedField(string $id, mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return get_option($id, '');
        }

        return $this->encryption->encrypt(sanitize_text_field($value));
    }

    /**
     * Add a text field.
     */
    private function addTextField(string $id, string $label, string $section, string $default = ''): void
    {
        register_setting(self::OPTION_GROUP, $id, [
            'type'              => 'string',
            'default'           => $default,
            'sanitize_callback' => fn(mixed $v): string => sanitize_text_field((string) ($v ?? $default)),
        ]);

        add_settings_field($id, $label, function () use ($id, $default): void {
            printf(
                '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
                esc_attr($id),
                esc_attr(get_option($id, $default))
            );
        }, self::PAGE_SLUG, $section);
    }

    /**
     * Add a select field.
     *
     * @param array<string, string> $options
     */
    private function addSelectField(string $id, string $label, string $section, array $options, string $default): void
    {
        register_setting(self::OPTION_GROUP, $id, [
            'type'              => 'string',
            'default'           => $default,
            'sanitize_callback' => fn(mixed $v): string => array_key_exists((string) $v, $options) ? (string) $v : $default,
        ]);

        add_settings_field($id, $label, function () use ($id, $options, $default): void {
            $current = get_option($id, $default);
            echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($id) . '">';
            foreach ($options as $value => $text) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr($value),
                    selected($current, $value, false),
                    esc_html($text)
                );
            }
            echo '</select>';
        }, self::PAGE_SLUG, $section);
    }

    /**
     * Add a number field.
     */
    private function addNumberField(string $id, string $label, string $section, int $default, int $min, int $max): void
    {
        register_setting(self::OPTION_GROUP, $id, [
            'type'              => 'integer',
            'default'           => $default,
            'sanitize_callback' => static fn(mixed $v): int => max($min, min($max, (int) ($v ?? $default))),
        ]);

        add_settings_field($id, $label, function () use ($id, $default, $min, $max): void {
            printf(
                '<input type="number" id="%1$s" name="%1$s" value="%2$d" min="%3$d" max="%4$d" class="small-text" />',
                esc_attr($id),
                (int) get_option($id, $default),
                $min,
                $max
            );
        }, self::PAGE_SLUG, $section);
    }

    /**
     * Add a checkbox field.
     */
    private function addCheckboxField(string $id, string $label, string $section, bool $default): void
    {
        register_setting(self::OPTION_GROUP, $id, [
            'type'              => 'boolean',
            'default'           => $default,
            'sanitize_callback' => static fn(mixed $v): bool => (bool) $v,
        ]);

        add_settings_field($id, $label, function () use ($id, $default): void {
            $checked = (bool) get_option($id, $default);
            printf(
                '<input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s />',
                esc_attr($id),
                checked($checked, true, false)
            );
        }, self::PAGE_SLUG, $section);
    }

    /**
     * Get the required capability for accessing settings and running analysis.
     */
    private function getRequiredCapability(): string
    {
        /** @var string $capability */
        $capability = apply_filters(
            'pr_required_capability',
            get_option('pr_capability', 'edit_products')
        );

        return $capability;
    }
}
