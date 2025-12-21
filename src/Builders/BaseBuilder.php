<?php

namespace HosseinHezami\LaravelGemini\Builders;

use HosseinHezami\LaravelGemini\Contracts\ProviderInterface;
use HosseinHezami\LaravelGemini\Exceptions\ValidationException;
use HosseinHezami\LaravelGemini\Responses\CacheResponse;

abstract class BaseBuilder
{
    protected ProviderInterface $provider;

    protected array $params = [];

    public function __construct(ProviderInterface $provider)
    {
        $this->provider = $provider;
        $this->params['capability'] = $this->getCapability();
        $this->params['defaultProvider'] = config('gemini.default_provider'); 
        $this->params['model'] = config('gemini.providers.' . $this->params['defaultProvider'] . '.models.' . $this->params['capability']);
        $this->params['method'] = config('gemini.providers.' . $this->params['defaultProvider'] . '.methods.' . $this->params['capability']);
        if (empty($this->params['model'])) {
            throw new ValidationException("Default model for {$this->params['capability']} not found in configuration.");
        }
        if (empty($this->params['method'])) {
            throw new ValidationException("Default method for {$this->params['capability']} not found in configuration.");
        }
    }

    abstract protected function getCapability(): string;

    public function model(string $model = null): self
    {
        $this->params['model'] = $model ?? $this->params['model'];
        return $this;
    }

    public function method(string $method): self
    {
        if (!in_array($method, ['generateContent', 'predict', 'predictLongRunning'])) {
            throw new ValidationException("Invalid method: {$method}. Supported: generateContent, predict, predictLongRunning.");
        }
        $this->params['method'] = $method ?? $this->params['method'];
        return $this;
    }

    public function prompt(string $prompt): self
    {
        $this->params['prompt'] = $prompt;
        return $this;
    }

    public function system(string $system): self
    {
        $this->params['system'] = $system;
        return $this;
    }

    public function history(array $history): self
    {
        $this->params['history'] = $history;
        return $this;
    }

    public function historyFromModel($query, $bodyColumn, $roleColumn): self
    {
        $history = $query->get()->map(function ($item) use ($bodyColumn, $roleColumn) {
            return ['role' => $item->{$roleColumn}, 'parts' => [['text' => $item->{$bodyColumn}]]];
        })->toArray();
        return $this->history($history);
    }

    public function temperature(float $value): self
    {
        $this->params['temperature'] = $value;
        return $this;
    }

    public function maxTokens(int $value): self
    {
        $this->params['maxTokens'] = $value;
        return $this;
    }

    public function safetySettings(array $settings): self
    {
        $this->params['safetySettings'] = $settings;
        return $this;
    }

    public function functionCalls(array $functions): self
    {
        $this->params['functions'] = $functions;
        return $this;
    }

    public function structuredSchema(array $schema): self
    {
        $this->params['structuredSchema'] = $schema;
        return $this;
    }

    public function upload(string $fileType, string $filePath): self
    {
        $this->params['fileType'] = $fileType;
        $this->params['filePath'] = $filePath;
        return $this;
    }

    public function file(string $fileType, string $fileUri): self
    {
        $this->params['fileType'] = $fileType;
        $this->params['fileUri'] = $fileUri;
        return $this;
    }

    public function cache(
        ?array $tools = [],
        ?array $toolConfig = [],
        ?string $displayName = null,
        ?string $ttl = null,
        ?string $expireTime = null
    ): string {
        // Build contents from existing params (like prompt, history)
        $contents = [];
        if (isset($this->params['history'])) {
            $contents = array_merge($contents, $this->params['history']);
        }
        if (isset($this->params['prompt'])) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => $this->params['prompt']]]];
        }

        // Use system if set
        $systemInstruction = isset($this->params['system']) ? $this->params['system'] : null;

        // Prepare params for provider
        $cacheParams = [
            'model' => $this->params['model'],
            'contents' => $contents,
            'systemInstruction' => $systemInstruction,
            'tools' => $tools,
            'toolConfig' => $toolConfig,
            'displayName' => $displayName,
            'ttl' => $ttl ?? config('gemini.caching.default_ttl'),
            'expireTime' => $expireTime,
        ];

        // Call provider to create
        $response = $this->provider->createCachedContent($cacheParams);
        
        // Return the cache name for chaining or use
        return $response->name();
    }
    
    public function getCache(string $name): CacheResponse
    {
        if (empty($name)) {
            throw new ValidationException('Cache name is required.');
        }
        return $this->provider->getCachedContent($name);
    }

    public function cachedContent(string $name): self
    {
        $this->params['cachedContent'] = $name;
        return $this;
    }

    abstract public function generate();

    public function stream(callable $callback): void
    {
        $this->provider->streaming($this->params, $callback);
    }
}