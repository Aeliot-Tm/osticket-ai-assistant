<?php

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.forms.php');
require_once(__DIR__ . '/class.api-client.php');

/**
 * Custom field for Model selection that switches between dropdown and textbox
 */
class AIAssistantModelField extends TextboxField {
    static $widget = 'AIAssistantModelWidget';
}

class AIAssistantModelWidget extends Widget {
    function render($options=array()) {
        $name = $this->name;
        $value = $this->value;
        $config = $this->field->getConfiguration();
        
        // OpenAI models list
        $models = AIAssistantModelCapabilities::MODELS;
        ?>
        <input type="hidden" name="<?php echo $name; ?>" id="model_value" value="<?php echo Format::htmlchars($value); ?>" />
        
        <!-- Dropdown for OpenAI -->
        <select id="model_select" class="model-select-dropdown" style="width: 350px;">
            <?php foreach ($models as $model_id => $model_name): ?>
                <option value="<?php echo $model_id; ?>" <?php if ($value === $model_id) echo 'selected="selected"'; ?> <?php if (AIAssistantModelCapabilities::responsesOnly($model_id)) echo 'disabled="disabled"'; ?>>
                    <?php echo Format::htmlchars($model_name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <!-- Textbox for Custom -->
        <input type="text" id="model_text" class="model-text-input" 
               value="<?php echo Format::htmlchars($value); ?>" 
               placeholder="Enter model name (e.g., gpt-6-luna)"
               style="width: 350px; padding: 5px;" />

        <script type="text/javascript"><?php readfile(__DIR__ . '/js/config-api-provider.js'); ?></script>
        <?php
    }
}

class AiAssistantConfig extends PluginConfig {
    
    function getOptions() {
        return array(
            'api_provider' => new ChoiceField(array(
                'label' => __('API Provider'),
                'default' => 'openai',
                'choices' => array(
                    'openai' => 'Open AI',
                    'custom' => 'Custom'
                ),
                'hint' => __('Choose API provider type')
            )),
            'api_key' => new TextboxField(array(
                'label' => __('API Key'),
                'required' => true,
                'configuration' => array(
                    'size' => 60,
                    'length' => 500,
                    'placeholder' => 'sk-...'
                ),
                'hint' => __('Your API key. Get it for example from https://platform.openai.com/api-keys')
            )),
            'api_url' => new TextboxField(array(
                'label' => __('API URL'),
                'required' => false,
                'configuration' => array(
                    'size' => 60,
                    'length' => 500,
                    'placeholder' => 'https://api.example.com/v1/chat/completions'
                ),
                'hint' => __('Custom API endpoint URL (compatible with OpenAI)')
            )),
            'model' => new AIAssistantModelField(array(
                'label' => __('Model Name'),
                'default' => 'gpt-6-luna',
                'required' => true,
                'hint' => __('Select or enter the model name to use for analysis')
            )),
            'reasoning_effort' => new ChoiceField(array(
                'label' => __('Reasoning Effort'),
                'default' => 'none',
                'configuration' => array(
                    'classes' => 'reasoning-effort-select',
                    'data' => array(
                        'reasoning-rules' => json_encode(AIAssistantModelCapabilities::REASONING_RULES),
                        'unavailable-hint' => __('Reasoning configuration is not available for the selected models.')
                    )
                ),
                'choices' => array(
                    'none' => __('None'),
                    'minimal' => __('Minimal'),
                    'low' => __('Low'),
                    'medium' => __('Medium'),
                    'high' => __('High'),
                    'xhigh' => __('Extra High'),
                    'max' => __('Maximum')
                ),
                'hint' => __('Reasoning level for supported models. Higher levels may increase response time and cost.')
            )),
            'auto_suggest' => new BooleanField(array(
                'label' => __('Auto-suggest on ticket view'),
                'default' => false,
                'configuration' => array(
                    'desc' => __('Automatically suggest response when viewing a ticket (may increase API costs)')
                )
            )),
            'min_confidence' => new TextboxField(array(
                'label' => __('Minimum Confidence Score'),
                'default' => '70',
                'required' => true,
                'validator' => 'number',
                'configuration' => array(
                    'size' => 10,
                    'length' => 3
                ),
                'hint' => __('Only show suggestions with confidence above this score (0-100)')
            )),
            'max_templates' => new TextboxField(array(
                'label' => __('Max Templates to Analyze'),
                'default' => '10',
                'required' => true,
                'validator' => 'number',
                'configuration' => array(
                    'size' => 10,
                    'length' => 3
                ),
                'hint' => __('Maximum number of canned responses to send to AI for analysis')
            )),
            'timeout' => new TextboxField(array(
                'label' => __('API Timeout (seconds)'),
                'default' => '30',
                'required' => true,
                'validator' => 'number',
                'configuration' => array(
                    'size' => 10,
                    'length' => 3
                ),
                'hint' => __('Maximum time to wait for OpenAI response')
            )),
            'temperature' => new TextboxField(array(
                'label' => __('Temperature'),
                'default' => '0.3',
                'required' => false,
                'configuration' => array(
                    'size' => 10,
                    'length' => 4
                ),
                'hint' => __('Advanced: Controls response randomness (0.0-2.0). Default: 0.3. For supported reasoning models, used only when Reasoning Effort is None.')
            )),
            'enable_logging' => new BooleanField(array(
                'label' => __('Enable Debug Logging'),
                'default' => false,
                'configuration' => array(
                    'desc' => __('Log AI requests and responses for debugging')
                )
            ))
        );
    }
    
    function getFormOptions() {
        return array(
            'title' => __('AI Assistant Configuration'),
            'instructions' => __('Configure AI API integration for intelligent ticket response suggestions.')
        );
    }
    
    function pre_save(&$config, &$errors) {

        $result = true;
        if ('openai' === $config['api_provider']) {
            // For OpenAI provider, set default API URL
            $config['api_url'] = 'https://api.openai.com/v1/chat/completions';
        }

        // Validate API URL
        if (empty($config['api_url'])) {
            $errors['api_url'] = __('API URL is required for Custom provider');
            $result = false;
        }

        if (isset($config['temperature'])) {
            $config['temperature'] = (float) $config['temperature'];
            if (0.0 > $config['temperature'] || $config['temperature'] > 2.0) {
                $errors['temperature'] = __('Value is out of range');
                $result = false;
            }
        }

        $efforts = AIAssistantModelCapabilities::commonReasoningEfforts(
            (string) ($config['model'] ?? ''),
            ''
        );
        $config['reasoning_effort'] = $config['reasoning_effort'] ?? ($efforts[0] ?? 'none');
        if (!in_array($config['reasoning_effort'], array('none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'), true)) {
            $errors['reasoning_effort'] = __('Invalid reasoning effort');
            $result = false;
        } elseif ($efforts && !in_array($config['reasoning_effort'], $efforts, true)) {
            $errors['reasoning_effort'] = __('This reasoning effort is not supported by the selected models.');
            $result = false;
        }

        return $result;
    }
}
