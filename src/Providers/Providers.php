<?php

declare(strict_types=1);

namespace TokenSqueezer\Providers;

use TokenSqueezer\Contracts\ProviderInterface;
use TokenSqueezer\Exceptions\TokenSqueezedException;

/**
 * Creates AI provider instances by name.
 */
class ProviderFactory
{
    protected static array $custom = [];

    public static function make(string $name, array $config, string $model = ''): ProviderInterface
    {
        // Check custom drivers first
        if (isset(static::$custom[$name])) {
            $class = static::$custom[$name];
            return new $class($config, $model);
        }

        return match ($name) {
            'openai'  => new OpenAIProvider($config, $model),
            'claude'  => new ClaudeProvider($config, $model),
            'gemini'  => new GeminiProvider($config, $model),
            'kimi'    => new KimiProvider($config, $model),
            'mimo'    => new MimoProvider($config, $model),
            'ollama'  => new OllamaProvider($config, $model),
            default   => throw new TokenSqueezedException("Unknown AI provider: [{$name}]"),
        };
    }

    /**
     * Register a custom provider driver.
     *
     * @param  string  $name   e.g. 'myai'
     * @param  string  $class  Must implement ProviderInterface
     */
    public static function extend(string $name, string $class): void
    {
        static::$custom[$name] = $class;
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Base HTTP provider
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

abstract class BaseProvider implements ProviderInterface
{
    protected \GuzzleHttp\Client $http;

    public function __construct(protected array $config, protected string $model)
    {
        $this->http = new \GuzzleHttp\Client([
            'timeout'         => $config['timeout'] ?? 15,
            'connect_timeout' => 5,
        ]);
    }

    protected function post(string $url, array $payload, array $headers = []): array
    {
        $retries  = $this->config['retries'] ?? 2;
        $apiKey   = $this->config['api_key'] ?? '';
        $attempts = 0;

        do {
            try {
                $response = $this->http->post($url, [
                    'headers' => array_merge([
                        'Content-Type'  => 'application/json',
                        'Authorization' => "Bearer {$apiKey}",
                    ], $headers),
                    'json' => $payload,
                ]);

                return json_decode((string) $response->getBody(), true);

            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                $attempts++;
                if ($attempts > $retries) {
                    throw new \TokenSqueezer\Exceptions\TokenSqueezedException(
                        "Provider [{$this->name()}] connection failed after {$retries} retries: " . $e->getMessage()
                    );
                }
                usleep(500_000 * $attempts); // exponential backoff
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                throw new \TokenSqueezer\Exceptions\TokenSqueezedException(
                    "Provider [{$this->name()}] API error: " . $e->getResponse()->getBody()
                );
            }
        } while ($attempts <= $retries);

        return [];
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// OpenAI Provider (GPT-4o, GPT-4o-mini, etc.)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

class OpenAIProvider extends BaseProvider
{
    public function name(): string { return 'openai'; }

    public function complete(array $messages, float $temperature, int $maxTokens): array
    {
        $model    = $this->model ?: ($this->config['model'] ?? 'gpt-4o-mini');
        $endpoint = $this->config['base_url'] ?? 'https://api.openai.com/v1/chat/completions';

        // Extract system message for response_format
        $hasSystem = isset($messages[0]) && $messages[0]['role'] === 'system';

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];

        $raw = $this->post($endpoint, $payload);

        return [
            'content' => $raw['choices'][0]['message']['content'] ?? '',
            'usage'   => [
                'input_tokens'  => $raw['usage']['prompt_tokens'] ?? 0,
                'output_tokens' => $raw['usage']['completion_tokens'] ?? 0,
            ],
        ];
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Claude / Anthropic Provider
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

class ClaudeProvider extends BaseProvider
{
    public function name(): string { return 'claude'; }

    public function complete(array $messages, float $temperature, int $maxTokens): array
    {
        $model    = $this->model ?: ($this->config['model'] ?? 'claude-haiku-4-5-20251001');
        $endpoint = $this->config['base_url'] ?? 'https://api.anthropic.com/v1/messages';
        $apiKey   = $this->config['api_key'] ?? '';

        // Claude separates system from user messages
        $system      = '';
        $userMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $system = $msg['content'];
            } else {
                $userMessages[] = $msg;
            }
        }

        $payload = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
            'messages'    => $userMessages,
        ];
        if ($system) {
            $payload['system'] = $system;
        }

        $raw = $this->post($endpoint, $payload, [
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Authorization'     => '', // Claude uses x-api-key not Bearer
        ]);

        return [
            'content' => $raw['content'][0]['text'] ?? '',
            'usage'   => [
                'input_tokens'  => $raw['usage']['input_tokens'] ?? 0,
                'output_tokens' => $raw['usage']['output_tokens'] ?? 0,
            ],
        ];
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Google Gemini Provider
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

class GeminiProvider extends BaseProvider
{
    public function name(): string { return 'gemini'; }

    public function complete(array $messages, float $temperature, int $maxTokens): array
    {
        $model   = $this->model ?: ($this->config['model'] ?? 'gemini-1.5-flash');
        $apiKey  = $this->config['api_key'] ?? '';
        $url     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        // Convert messages to Gemini format
        $contents = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') continue; // Merge system into first user
            $contents[] = [
                'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        $payload = [
            'contents'           => $contents,
            'generationConfig'   => [
                'temperature'    => $temperature,
                'maxOutputTokens' => $maxTokens,
            ],
        ];

        $raw = $this->post($url, $payload, ['Authorization' => '']);

        return [
            'content' => $raw['candidates'][0]['content']['parts'][0]['text'] ?? '',
            'usage'   => [
                'input_tokens'  => $raw['usageMetadata']['promptTokenCount'] ?? 0,
                'output_tokens' => $raw['usageMetadata']['candidatesTokenCount'] ?? 0,
            ],
        ];
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Kimi / Moonshot Provider (OpenAI-compatible)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

class KimiProvider extends OpenAIProvider
{
    public function name(): string { return 'kimi'; }

    public function complete(array $messages, float $temperature, int $maxTokens): array
    {
        $this->model                  = $this->model ?: ($this->config['model'] ?? 'moonshot-v1-8k');
        $this->config['base_url']     = $this->config['base_url'] ?? 'https://api.moonshot.cn/v1/chat/completions';
        return parent::complete($messages, $temperature, $maxTokens);
    }

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Xiaomi Mimo Provider (OpenAI-compatible)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

class MimoProvider extends OpenAIProvider
{
    public function name(): string { return 'mimo'; }

    public function complete(array $messages, float $temperature, int $maxTokens): array
    {
        $this->model                  = $this->model ?: ($this->config['model'] ?? 'mimo-v2.5');
        $this->config['base_url']     = $this->config['base_url'] ?? 'https://api.xiaomimimo.com/v1/chat/completions';
        return parent::complete($messages, $temperature, $maxTokens);
    }
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// Ollama Local Provider
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

class OllamaProvider extends BaseProvider
{
    public function name(): string { return 'ollama'; }

    public function complete(array $messages, float $temperature, int $maxTokens): array
    {
        $model    = $this->model ?: ($this->config['model'] ?? 'llama3');
        $endpoint = $this->config['base_url'] ?? 'http://localhost:11434/api/chat';

        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => [
                'temperature' => $temperature,
                'num_predict' => $maxTokens,
            ],
        ];

        // Ollama doesn't need an auth header
        $response = $this->http->post($endpoint, ['json' => $payload]);
        $raw      = json_decode((string) $response->getBody(), true);

        return [
            'content' => $raw['message']['content'] ?? '',
            'usage'   => [
                'input_tokens'  => $raw['prompt_eval_count'] ?? 0,
                'output_tokens' => $raw['eval_count'] ?? 0,
            ],
        ];
    }
}
