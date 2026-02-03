<?php
declare(strict_types=1);
/**
 *  This file is part of the Markdown Lernmodul Repository Object plugin for ILIAS
 *  
 */

use ILIAS\UI\Factory;
use ILIAS\UI\Component\Input\Field\Group;
use ILIAS\UI\Renderer;
use platform\ilMarkdownLernmodulConfig;
use platform\ilMarkdownLernmodulException;

require_once __DIR__ . '/platform/class.ilMarkdownLernmodulConfig.php';
require_once __DIR__ . '/platform/class.ilMarkdownLernmodulException.php';

/**
 * Configuration GUI for MarkdownLernmodul plugin administration.
 * 
 * Provides tabbed interface for configuring:
 * - General settings (AI enable/disable, service selection, system prompt)
 * - GWDG Academic Cloud (API key, model selection, streaming)
 * - Google Gemini (API key, model selection)
 * - OpenAI ChatGPT (API key, model selection)
 * 
 * Uses ILIAS UI Factory for form generation and validation.
 * All API keys are automatically encrypted via ilMarkdownLernmodulConfig.
 * 
 * @ilCtrl_IsCalledBy ilMarkdownLernmodulConfigGUI: ilObjComponentSettingsGUI
 */
class ilMarkdownLernmodulConfigGUI extends ilPluginConfigGUI
{
    protected Factory $factory;
    protected Renderer $renderer;
    protected \ILIAS\Refinery\Factory $refinery;
    protected ilCtrl $control;
    protected ilGlobalTemplateInterface $tpl;
    protected ilTabsGUI $tabs;
    protected $request;

    /**
     * Main controller - routes to appropriate configuration section.
     * 
     * Available commands:
     * - configure/configureGeneral: AI enable/disable, service selection, system prompt
     * - configureGWDG: GWDG Academic Cloud settings
     * - configureGoogle: Google Gemini settings
     * - configureOpenAI: OpenAI ChatGPT settings
     * 
     * Checks if xmdl_config table exists before proceeding.
     * Initializes tabs and renders appropriate form.
     * 
     * @param string $cmd Command to execute
     */
    public function performCommand($cmd): void
    {
        global $DIC;
        
        // Check if config table exists (plugin might not be activated yet)
        if (!$DIC->database()->tableExists('xmdl_config')) {
            // If table doesn't exist, show a message and return early
            $this->tpl = $DIC->ui()->mainTemplate();
            $this->tpl->setOnScreenMessage('info', 'Plugin configuration is not available until the plugin is activated.');
            $this->tpl->setContent('');
            return;
        }
        
        $this->factory = $DIC->ui()->factory();
        $this->renderer = $DIC->ui()->renderer();
        $this->refinery = $DIC->refinery();
        $this->control = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->request = $DIC->http()->request();
        $this->tabs = $DIC->tabs();

        switch ($cmd) {
            case "configure":
            case "configureGeneral":
            case "configureGWDG":
            case "configureGoogle":
            case "configureOpenAI":
                ilMarkdownLernmodulConfig::load();
                $this->initTabs();
                $this->control->setParameterByClass('ilMarkdownLernmodulConfigGUI', 'cmd', $cmd);
                $form_action = $this->control->getLinkTargetByClass("ilMarkdownLernmodulConfigGUI", $cmd);
                $rendered = $this->renderForm($form_action, $this->buildForm($cmd));
                break;
            default:
                throw new ilException("command not defined");
        }

     
   $this->tpl->setContent($rendered);
    }

    /**
     * Initialize admin configuration tabs.
     * 
     * Creates 4 tabs: General, GWDG, Google Gemini, OpenAI ChatGPT.
     * Sets active tab based on current command from control flow.
     */
    protected function initTabs(): void
    {
        $this->tabs->addTab(
            "general",
            $this->plugin_object->txt("config_general"),
            $this->control->getLinkTargetByClass("ilMarkdownLernmodulConfigGUI", "configureGeneral")
        );

        $this->tabs->addTab(
            "gwdg",
            "GWDG",
            $this->control->getLinkTargetByClass("ilMarkdownLernmodulConfigGUI", "configureGWDG")
        );

        $this->tabs->addTab(
            "google",
            "Google Gemini",
            $this->control->getLinkTargetByClass("ilMarkdownLernmodulConfigGUI", "configureGoogle")
        );

        $this->tabs->addTab(
            "openai",
            "OpenAI ChatGPT",
            $this->control->getLinkTargetByClass("ilMarkdownLernmodulConfigGUI", "configureOpenAI")
        );

        switch($this->control->getCmd()) {
            case "configureGeneral":
                $this->tabs->activateTab("general");
                break;
            case "configureGWDG":
                $this->tabs->activateTab("gwdg");
                break;
            case "configureGoogle":
                $this->tabs->activateTab("google");
                break;
            case "configureOpenAI":
                $this->tabs->activateTab("openai");
                break;
            default:
                $this->tabs->activateTab("general");
        }
    /**
     * @throws ilMarkdownLernmodulException
     */
    }

    /**
     * Build configuration form sections for specific command.
     * 
     * Routes to appropriate section builder:
     * - buildGeneralSection(): AI toggle, service selection, system prompt
     * - buildGWDGSection(): GWDG API key, models, streaming
     * - buildGoogleSection(): Google API key, model selection
     * - buildOpenAISection(): OpenAI API key, model selection
     * 
     * @param string $cmd Command name determining which section to build
     * @return array Form sections with input fields
     * @throws ilMarkdownLernmodulException
     */
    private function buildForm(string $cmd): array
    {
        switch($cmd) {
            case "configureGWDG":
                return $this->buildGWDGSection();
            case "configureGoogle":
                return $this->buildGoogleSection();
            case "configureOpenAI":
                return $this->buildOpenAISection();
            default:
                return $this->buildGeneralSection();
        }
    }

    /**
     * Build General settings section.
     * 
     * Contains:
     * - AI enabled checkbox (enable/disable AI features globally)
     * - Service checkboxes (GWDG, Google Gemini, OpenAI)
     * - System prompt textarea (AI flashcard generation instructions)
     * 
     * Service selections are stored as JSON in available_services config.
     * System prompt uses default template if not customized.
     * 
     * @return array Form section with general configuration inputs
     * @throws ilMarkdownLernmodulException
     */
    private function buildGeneralSection(): array {
        // AI Enable/Disable Checkbox - convert to bool for checkbox
        $ai_enabled_value = ilMarkdownLernmodulConfig::get('ai_enabled', true);
        $ai_enabled_bool = filter_var($ai_enabled_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        
        $ai_enabled = $this->factory->input()->field()->checkbox(
            $this->plugin_object->txt("config_ai_enabled_label"),
            $this->plugin_object->txt("config_ai_enabled_info")
        )->withValue($ai_enabled_bool)->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownLernmodulConfig::set('ai_enabled', $v);
            }
        ));

        $available_services = ilMarkdownLernmodulConfig::get("available_services");
        if (!is_array($available_services) || $available_services === null) {
            $available_services = [
                'gwdg' => false,
                'google' => false,
                'openai' => false
            ];
        }

        $gwdg_service = $this->factory->input()->field()->checkbox(
            "GWDG",
        )->withValue((isset($available_services["gwdg"]) && $available_services["gwdg"] == "1") ? true : false)->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Reload config to avoid stale/null reference
                $services = ilMarkdownLernmodulConfig::get('available_services');
                if (!is_array($services)) {
                    $services = [];
                }
                $services["gwdg"] = $v;
                ilMarkdownLernmodulConfig::set('available_services', $services);
            }
        ));

        $google_service = $this->factory->input()->field()->checkbox(
            "Google Gemini",
        )->withValue((isset($available_services["google"]) && $available_services["google"] == "1") ? true : false)->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Reload config to avoid stale/null reference
                $services = ilMarkdownLernmodulConfig::get('available_services');
                if (!is_array($services)) {
                    $services = [];
                }
                $services["google"] = $v;
                ilMarkdownLernmodulConfig::set('available_services', $services);
            }
        ));

        $openai_service = $this->factory->input()->field()->checkbox(
            "OpenAI ChatGPT",
        )->withValue((isset($available_services["openai"]) && $available_services["openai"] == "1") ? true : false)->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Reload config to avoid stale/null reference
                $services = ilMarkdownLernmodulConfig::get('available_services');
                if (!is_array($services)) {
                    $services = [];
                }
                $services["openai"] = $v;
                ilMarkdownLernmodulConfig::set('available_services', $services);
            }
        ));

        $system_prompt = $this->factory->input()->field()->textarea(
            $this->plugin_object->txt("config_system_prompt_label"),
            $this->plugin_object->txt("config_system_prompt_info")
        )->withValue(ilMarkdownLernmodulConfig::get("system_prompt") ?: $this->getDefaultSystemPrompt())->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownLernmodulConfig::set('system_prompt', $v);
            }
        ))->withRequired(true);

        return [
            "ai_settings" => $this->factory->input()->field()->section([
                $ai_enabled
            ], $this->plugin_object->txt("config_ai_settings")),
            "available_services" => $this->factory->input()->field()->section([
                $gwdg_service,
                $google_service,
                $openai_service
            ], $this->plugin_object->txt("config_available_services")),
            "general" => $this->factory->input()->field()->section([
                $system_prompt
            ], $this->plugin_object->txt("config_general"))
        ];
    }

    /**
     * Build GWDG Academic Cloud settings section.
     * 
     * Contains:
     * - Model multiselect (fetched dynamically from GWDG API if key is set)
     * - API key password field (auto-encrypted on save)
     * - Streaming checkbox (enable/disable response streaming)
     * 
     * Models are loaded via getGWDGModels() when API key is already configured.
     * 
     * @return array Form section with GWDG configuration inputs
     * @throws ilMarkdownLernmodulException
     */
    private function buildGWDGSection(): array {
        $inputs = [];

        if (!empty(ilMarkdownLernmodulConfig::get("gwdg_api_key"))) {
            $models = $this->getGWDGModels(ilMarkdownLernmodulConfig::get("gwdg_api_key"));

            $values = ilMarkdownLernmodulConfig::get("gwdg_models");

            if (empty($values)) {
                $values = [];
            } else {
                $values = array_keys($values);
            }

            if (!empty($models)) {
                $inputs[] = $this->factory->input()->field()->multiSelect(
                    $this->plugin_object->txt("config_gwdg_models_label"),
                    $models
                )->withValue($values)->withAdditionalTransformation($this->refinery->custom()->transformation(
                    function ($v) use ($models) {
                        $models_to_save = [];

                        foreach ($v as $model) {
                            $models_to_save[$model] = $models[$model];
                        }

                        ilMarkdownLernmodulConfig::set('gwdg_models', $models_to_save);
                    }
                ))->withRequired(true);
            } else {
                $this->tpl->setOnScreenMessage("failure", $this->plugin_object->txt("config_gwdg_models_error"));
            }
        }

        $inputs[] = $this->factory->input()->field()->password(
            $this->plugin_object->txt("config_gwdg_key_label"),
            $this->plugin_object->txt("config_gwdg_key_info")
        )->withValue(ilMarkdownLernmodulConfig::get("gwdg_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Convert Password object to string
                $api_key = ($v instanceof \ILIAS\Data\Password) ? $v->toString() : $v;
                ilMarkdownLernmodulConfig::set('gwdg_api_key', $api_key);
            }
        ))->withRequired(true);

        $inputs[] = $this->factory->input()->field()->checkbox(
            $this->plugin_object->txt("config_gwdg_stream_label"),
            $this->plugin_object->txt("config_gwdg_stream_info")
        )->withValue(ilMarkdownLernmodulConfig::get("gwdg_streaming") == "1")->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownLernmodulConfig::set('gwdg_streaming', $v);
            }
        ));

        return [
            "gwdg" => $this->factory->input()->field()->section($inputs, "GWDG")
        ];
    }

    /**
     * Build Google Gemini settings section.
     * 
     * Contains:
     * - API key password field (auto-encrypted on save)
     * - Note: Model selection removed - using default gemini-2.0-flash-exp
     * 
     * @return array Form section with Google Gemini configuration inputs
     */
    private function buildGoogleSection(): array {
        $inputs = [];

        $inputs[] = $this->factory->input()->field()->password(
            $this->plugin_object->txt("config_google_key_label"),
            $this->plugin_object->txt("config_google_key_info")
        )->withValue(ilMarkdownLernmodulConfig::get("google_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Convert Password object to string
                $api_key = ($v instanceof \ILIAS\Data\Password) ? $v->toString() : $v;
                ilMarkdownLernmodulConfig::set('google_api_key', $api_key);
            }
        ))->withRequired(true);

        return [
            "google" => $this->factory->input()->field()->section($inputs, "Google Gemini")
        ];
    }

    /**
     * Build OpenAI ChatGPT settings section.
     * 
     * Contains:
     * - Model selection (gpt-4o, gpt-4o-mini, gpt-4-turbo, gpt-4, gpt-3.5-turbo)
     * - API key password field (auto-encrypted on save)
     * 
     * @return array Form section with OpenAI configuration inputs
     */
    private function buildOpenAISection(): array {
        $inputs = [];

        $models = [
            'gpt-4o' => 'GPT-4o',
            'gpt-4o-mini' => 'GPT-4o Mini',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-4' => 'GPT-4',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo'
        ];

        $inputs[] = $this->factory->input()->field()->select(
            $this->plugin_object->txt("config_openai_model_label"),
            $models
        )->withValue(ilMarkdownLernmodulConfig::get("openai_model") ?: 'gpt-4o-mini')->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                ilMarkdownLernmodulConfig::set('openai_model', $v);
            }
        ))->withRequired(true);

        $inputs[] = $this->factory->input()->field()->password(
            $this->plugin_object->txt("config_openai_key_label"),
            $this->plugin_object->txt("config_openai_key_info")
        )->withValue(ilMarkdownLernmodulConfig::get("openai_api_key"))->withAdditionalTransformation($this->refinery->custom()->transformation(
            function ($v) {
                // Convert Password object to string
                $api_key = ($v instanceof \ILIAS\Data\Password) ? $v->toString() : $v;
                ilMarkdownLernmodulConfig::set('openai_api_key', $api_key);
            }
        ))->withRequired(true);

        return [
            "openai" => $this->factory->input()->field()->section($inputs, "OpenAI ChatGPT")
        ];
    }

    /**
     * Render form with given sections.
     * 
     * Creates ILIAS standard form, handles POST submission,
     * triggers save() on successful validation.
     * 
     * @param string $form_action Form submit URL
     * @param array $sections Form sections from build methods
     * @return string Rendered HTML form
     */
    private function renderForm(string $form_action, array $sections): string
    {
        $form = $this->factory->input()->container()->form()->standard(
            $form_action,
            $sections
        );

        if ($this->request->getMethod() == "POST") {
            $form = $form->withRequest($this->request);
            $result = $form->getData();
            if ($result) {
                $this->save();
            }
        }

        return $this->renderer->render($form);
    }

    /**
     * Save configuration to database.
     * 
     * Triggers ilMarkdownLernmodulConfig::save() which persists all
     * pending changes to xmdl_config table.
     * API keys are automatically encrypted before storage.
     * 
     * Displays success message after save.
     */
    public function save(): void
    {
        ilMarkdownLernmodulConfig::save();

        $this->tpl->setOnScreenMessage("success", $this->plugin_object->txt('config_msg_success'));
    }

    /**
     * Fetch available models from GWDG Academic Cloud API.
     * 
     * Makes GET request to https://chat-ai.academiccloud.de/v1/models
     * with Bearer token authentication. Respects ILIAS proxy settings.
     * 
     * Timeout: 10 seconds
     * 
     * @param string $api_key GWDG API key for authentication
     * @return array Associative array of model_id => model_name
     */
    private function getGWDGModels(string $api_key): array
    {
        $curlSession = curl_init();
        curl_setopt($curlSession, CURLOPT_URL, "https://chat-ai.academiccloud.de/v1/models");
        curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlSession, CURLOPT_TIMEOUT, 10);
        curl_setopt($curlSession, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ]);

        if (\ilProxySettings::_getInstance()->isActive()) {
            $proxyHost = \ilProxySettings::_getInstance()->getHost();
            $proxyPort = \ilProxySettings::_getInstance()->getPort();
            $proxyURL = $proxyHost . ":" . $proxyPort;
            curl_setopt($curlSession, CURLOPT_PROXY, $proxyURL);
        }

        $response = curl_exec($curlSession);

        $models = [];

        if (!curl_errno($curlSession)) {
            $response = json_decode($response, true);

            if (isset($response["data"])) {
                foreach ($response["data"] as $model) {
                    $models[$model['id']] = $model['name'];
                }
            }
        }

        curl_close($curlSession);

        return $models;
    }

    /**
     * 
     * @return string Default system prompt template
     */
    private function getDefaultSystemPrompt(): string
    {
        // Use [PLACEHOLDER] format to avoid ILIAS template processing
        return "You are an educational content expert. Generate learning module pages in markdown format.\n\n" .
            "EXACT FORMAT REQUIRED:\n" .
            "## Title\n" .
            "Your Title Here\n\n" .
            "## Content\n" .
            "Your content here...\n\n" .
            "---\n\n" .
            "COMPLETE EXAMPLE:\n" .
            "## Title\n" .
            "Introduction to Photosynthesis\n\n" .
            "## Content\n" .
            "Photosynthesis is the process by which plants convert light energy into chemical energy. This fundamental biological process occurs in the chloroplasts of plant cells.\n\n" .
            "**Key Components:**\n" .
            "- Light (usually sunlight)\n" .
            "- Carbon dioxide (CO2)\n" .
            "- Water (H2O)\n" .
            "- Chlorophyll (green pigment)\n\n" .
            "---\n\n" .
            "## Title\n" .
            "The Light Reactions\n\n" .
            "## Content\n" .
            "The light-dependent reactions take place in the thylakoid membranes. During these reactions:\n\n" .
            "1. Light energy is absorbed by chlorophyll\n" .
            "2. Water molecules are split (photolysis)\n" .
            "3. ATP and NADPH are produced\n" .
            "4. Oxygen is released as a byproduct\n\n" .
            "---\n\n" .
            "RULES:\n" .
            "- Each page MUST start with '## Title'\n" .
            "- The title text MUST be on the next line after '## Title'\n" .
            "- Then MUST have '## Content'\n" .
            "- Only '## Title' and '## Content' are allowed as H2 headings\n" .
            "- Do NOT use headings like '## Overview' or '## Introduction'\n" .
            "- Separate pages with '---'\n" .
            "- Create 3-10 pages depending on topic complexity\n" .
            "- Use markdown: **bold**, *italic*, lists, code blocks\n" .
            "- NO explanatory text before first page\n" .
            "- Start immediately with '## Title'";
    }
}

