<?php

declare(strict_types=1);

namespace ProductResearch\Admin;

use ProductResearch\Security\Encryption;

/**
 * Settings page under WooCommerce menu.
 *
 * Manages API keys (encrypted), search/analysis configuration,
 * security controls (capability, cooldown, credit budget).
 *
 * @package ProductResearch\Admin
 * @since   1.0.0
 */
final class SettingsPage
{
    private const PAGE_SLUG    = 'pr-settings';
    private const OPTION_GROUP = 'pr_settings_group';
    private const SECTION_API  = 'pr_section_api';
    private const SECTION_CFG  = 'pr_section_config';
    private const SECTION_SEC  = 'pr_section_security';

    private Encryption $encryption;

    /**
     * Create the settings page.
     *
     * @since 1.0.0
     *
     * @param Encryption $encryption AES-256 encryption for API key storage.
     */
    public function __construct(Encryption $encryption)
    {
        $this->encryption = $encryption;
    }

    /**
     * Register the settings submenu page under WooCommerce.
     *
     * @since 1.0.0
     *
     * @return void
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
     *
     * Delegates to per-section registration methods.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function registerSettings(): void
    {
        $this->registerApiSection();
        $this->registerConfigSection();
        $this->registerSecuritySection();
    }

    /**
     * Render the settings page HTML.
     *
     * Outputs the form, settings fields, and inline JS for
     * toggling provider-specific rows.
     *
     * @since 1.0.0
     *
     * @return void
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

        // Toggle provider-specific field visibility.
        ?>
        <script>
        (function() {
            var sel = document.getElementById('pr_ai_provider');
            if (!sel) return;
            function toggle() {
                var val = sel.value;
                document.querySelectorAll('.pr-provider-zai').forEach(function(tr) {
                    tr.style.display = val === 'zai' ? '' : 'none';
                });
                document.querySelectorAll('.pr-provider-anthropic').forEach(function(tr) {
                    tr.style.display = val === 'anthropic' ? '' : 'none';
                });
                document.querySelectorAll('.pr-provider-gemini').forEach(function(tr) {
                    tr.style.display = val === 'gemini' ? '' : 'none';
                });
            }
            sel.addEventListener('change', toggle);
            toggle();
        })();
        </script>
        <?php
    }

    /**
     * Register API Keys section.
     *
     * @since 1.0.0
     *
     * @return void
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

        // ── AI Provider selector ────────────────────────────────────
        $this->addSelectField('pr_ai_provider', __('AI Provider', 'product-research'), self::SECTION_API, [
            'zai'       => __('Z.AI (OpenAI-compatible)', 'product-research'),
            'anthropic' => __('Anthropic Claude', 'product-research'),
            'gemini'    => __('Google Gemini', 'product-research'),
        ], 'zai');

        // ── Z.AI fields ─────────────────────────────────────────────
        $this->addEncryptedField('pr_zai_api_key', __('Z.AI API Key', 'product-research'), self::SECTION_API, 'pr-provider-zai');
        $this->addTextField('pr_zai_model', __('Z.AI Model', 'product-research'), self::SECTION_API, 'glm-4.7', 'pr-provider-zai');
        $this->addTextField(
            'pr_zai_endpoint',
            __('Z.AI Endpoint URL', 'product-research'),
            self::SECTION_API,
            'https://api.z.ai/api/coding/paas/v4',
            'pr-provider-zai'
        );

        // ── Anthropic Claude fields ─────────────────────────────────
        $this->addEncryptedField('pr_anthropic_api_key', __('Claude API Key', 'product-research'), self::SECTION_API, 'pr-provider-anthropic');
        $this->addTextField('pr_anthropic_model', __('Claude Model', 'product-research'), self::SECTION_API, 'claude-sonnet-4-20250514', 'pr-provider-anthropic');

        // ── Google Gemini fields ────────────────────────────────────
        $this->addEncryptedField('pr_gemini_api_key', __('Gemini API Key', 'product-research'), self::SECTION_API, 'pr-provider-gemini');
        $this->addTextField('pr_gemini_model', __('Gemini Model', 'product-research'), self::SECTION_API, 'gemini-2.0-flash', 'pr-provider-gemini');

        // ── Tavily (always visible) ─────────────────────────────────
        $this->addEncryptedField('pr_tavily_api_key', __('Tavily API Key', 'product-research'), self::SECTION_API);
    }

    /**
     * Register Search & Analysis Configuration section.
     *
     * @since 1.0.0
     *
     * @return void
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

        $this->addTextareaField(
            'pr_exclude_domains',
            __('Exclude Domains', 'product-research'),
            self::SECTION_CFG,
            implode("\n", \ProductResearch\API\TavilyClient::DEFAULT_EXCLUDE_DOMAINS)
        );

        $this->addCheckboxField('pr_auto_recommendations', __('Auto-generate AI Recommendations', 'product-research'), self::SECTION_CFG, false);
    }

    /**
     * Register Security section.
     *
     * @since 1.0.0
     *
     * @return void
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
     *
     * Registers a setting with a custom sanitize callback that
     * encrypts the value before storage.
     *
     * @since 1.0.0
     *
     * @param  string $id       Option name / HTML id.
     * @param  string $label    Field label.
     * @param  string $section  Settings section slug.
     * @param  string $rowClass Optional CSS class for the row (provider toggle).
     * @return void
     */
    private function addEncryptedField(string $id, string $label, string $section, string $rowClass = ''): void
    {
        register_setting(self::OPTION_GROUP, $id, [
            'type'              => 'string',
            'sanitize_callback' => fn(mixed $value): string => $this->sanitizeEncryptedField($id, $value),
        ]);

        $args = $rowClass !== '' ? ['class' => $rowClass] : [];

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
        }, self::PAGE_SLUG, $section, $args);
    }

    /**
     * Sanitize and encrypt API key fields.
     *
     * Keeps existing value if blank submitted.
     *
     * @since 1.0.0
     *
     * @param  string $id    Option name.
     * @param  mixed  $value Submitted value.
     * @return string Encrypted API key or existing value.
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
     *
     * @since 1.0.0
     *
     * @param  string $id       Option name / HTML id.
     * @param  string $label    Field label.
     * @param  string $section  Settings section slug.
     * @param  string $default  Default value.
     * @param  string $rowClass Optional CSS class for the row.
     * @return void
     */
    private function addTextField(string $id, string $label, string $section, string $default = '', string $rowClass = ''): void
    {
        register_setting(self::OPTION_GROUP, $id, [
            'type'              => 'string',
            'default'           => $default,
            'sanitize_callback' => fn(mixed $v): string => sanitize_text_field((string) ($v ?? $default)),
        ]);

        $args = $rowClass !== '' ? ['class' => $rowClass] : [];

        add_settings_field($id, $label, function () use ($id, $default): void {
            printf(
                '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" />',
                esc_attr($id),
                esc_attr(get_option($id, $default))
            );
        }, self::PAGE_SLUG, $section, $args);
    }

    /**
     * Add a select field.
     *
     * @since 1.0.0
     *
     * @param  string                 $id      Option name / HTML id.
     * @param  string                 $label   Field label.
     * @param  string                 $section Settings section slug.
     * @param  array<string, string>  $options Value => display-text map.
     * @param  string                 $default Default value.
     * @return void
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
     *
     * @since 1.0.0
     *
     * @param  string $id      Option name / HTML id.
     * @param  string $label   Field label.
     * @param  string $section Settings section slug.
     * @param  int    $default Default value.
     * @param  int    $min     Minimum allowed value.
     * @param  int    $max     Maximum allowed value.
     * @return void
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
     *
     * @since 1.0.0
     *
     * @param  string $id      Option name / HTML id.
     * @param  string $label   Field label.
     * @param  string $section Settings section slug.
     * @param  bool   $default Default checked state.
     * @return void
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
     * Add a textarea field (one value per line, e.g. domain list).
     *
     * @since 1.0.0
     *
     * @param  string $id      Option name / HTML id.
     * @param  string $label   Field label.
     * @param  string $section Settings section slug.
     * @param  string $default Default value.
     * @return void
     */
    private function addTextareaField(string $id, string $label, string $section, string $default = ''): void
    {
        register_setting(self::PAGE_SLUG, $id, [
            'type'              => 'string',
            'sanitize_callback' => static function ($value) use ($default): string {
                if (! is_string($value) || trim($value) === '') {
                    return $default;
                }
                $lines = array_filter(array_map('trim', explode("\n", $value)));
                $lines = array_map('sanitize_text_field', $lines);
                return implode("\n", $lines);
            },
            'default'           => $default,
        ]);

        add_settings_field($id, $label, static function () use ($id, $default): void {
            $value = get_option($id, $default);
            printf(
                '<textarea id="%1$s" name="%1$s" rows="6" cols="50" class="large-text code">%2$s</textarea>'
                . '<p class="description">%3$s</p>',
                esc_attr($id),
                esc_textarea((string) $value),
                esc_html__('One domain per line (e.g. amazon.com)', 'product-research')
            );
        }, self::PAGE_SLUG, $section);
    }

    /**
     * Get the required capability for accessing settings and running analysis.
     *
     * Value is filterable via the `pr_required_capability` filter.
     *
     * @since 1.0.0
     *
     * @return string WordPress capability slug.
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
