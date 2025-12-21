<?php

namespace HosseinHezami\LaravelGemini\Providers;

use HosseinHezami\LaravelGemini\Contracts\ProviderInterface;
use HosseinHezami\LaravelGemini\Responses;
use HosseinHezami\LaravelGemini\Exceptions\ApiException;
use HosseinHezami\LaravelGemini\Exceptions\StreamException;
use HosseinHezami\LaravelGemini\Exceptions\RateLimitException;
use HosseinHezami\LaravelGemini\Exceptions\ValidationException;
use Illuminate\Support\Facades\Log;

class GeminiProvider extends BaseProvider implements ProviderInterface
{
    public function __construct(array $config = [], ?string $apiKey = null)
    {
        parent::__construct($apiKey);
    }

    public function generateText(array $params): Responses\TextResponse
    {
        return $this->executeRequest($params, 'Text');
    }

    public function generateImage(array $params): Responses\ImageResponse
    {
        return $this->executeRequest($params, 'Image');
    }

    public function generateVideo(array $params): Responses\VideoResponse
    {
        return $this->executeRequest($params, 'Video');
    }

    public function generateAudio(array $params): Responses\AudioResponse
    {
        return $this->executeRequest($params, 'Audio');
    }

    public function embeddings(array $params): array
    {
        $response = $this->http->post("/v1beta/models/{$params['model']}:embedContent", $params);
        return $response->json();
    }

    public function uploadFile(array $params): string
    {
        if (!isset($params['fileType']) || !isset($params['filePath'])) {
            throw new ValidationException('File type and path are required.');
        }
        return $this->upload($params['fileType'], $params['filePath']);
    }
    
    public function listFiles(array $params = []): Responses\FileResponse
    {
        try {
            $response = $this->http->get('/v1beta/files');
            return $this->handleResponse($response, 'File');
        } catch (\Exception $e) {
            throw new ApiException("Get files list error: {$e->getMessage()}");
        }
    }

    public function getFile(string $fileName): Responses\FileResponse
    {
        if (empty($fileName)) {
            throw new ValidationException('File name is required.');
        }

        try {
            $response = $this->http->get("/v1beta/files/{$fileName}");
            return $this->handleResponse($response, 'File');
        } catch (\Exception $e) {
            throw new ApiException("Get file error: {$e->getMessage()}");
        }
    }

    public function deleteFile(string $fileName): bool
    {
        if (empty($fileName)) {
            throw new ValidationException('File name is required.');
        }

        try {
            $response = $this->http->delete("/v1beta/files/{$fileName}");
            return $response->successful();
        } catch (\Exception $e) {
            throw new ApiException("Delete file error: {$e->getMessage()}");
        }
    }

    // Create cached content
    public function createCachedContent(array $params): Responses\CacheResponse
    {
        $payload = [
            'model' => "models/{$params['model']}",
            'contents' => $params['contents'],
        ];

        if (!empty($params['systemInstruction'])) {
            $payload['systemInstruction'] = ['parts' => [['text' => $params['systemInstruction']]]];
        }
        if (!empty($params['tools'])) {
            $payload['tools'] = $params['tools'];
        }
        if (!empty($params['toolConfig'])) {
            $payload['toolConfig'] = $params['toolConfig'];
        }
        if (!empty($params['displayName'])) {
            $payload['displayName'] = $params['displayName'];
        }
        
        if (!empty($params['expireTime'])) {
            $payload['expireTime'] = $params['expireTime'];
        } elseif (!empty($params['ttl'])) {
            $payload['ttl'] = $params['ttl'] ?? config('gemini.caching.default_ttl');
        }
        
        try {
            $response = $this->http->post('/v1beta/cachedContents', $payload);
            return $this->handleResponse($response, 'Cache');
        } catch (\Exception $e) {
            throw new ApiException("Create cache error: {$e->getMessage()}");
        }
    }

    // List cached contents
    public function listCachedContents(array $params = []): Responses\CacheResponse
    {
        $queryParams = http_build_query(array_filter([
            'pageSize' => $params['pageSize'] ?? config('gemini.caching.default_page_size'),
            'pageToken' => $params['pageToken'] ?? null,
        ]));

        try {
            $response = $this->http->get("/v1beta/cachedContents?{$queryParams}");
            return $this->handleResponse($response, 'Cache');
        } catch (\Exception $e) {
            throw new ApiException("List caches error: {$e->getMessage()}");
        }
    }

    // Get cached content
    public function getCachedContent(string $name): Responses\CacheResponse
    {
        try {
            $response = $this->http->get("/v1beta/$name");
            return $this->handleResponse($response, 'Cache');
        } catch (\Exception $e) {
            throw new ApiException("Get cache error: {$e->getMessage()}");
        }
    }

    // Update cached content (expiration only)
    public function updateCachedContent(string $name, array $expiration): Responses\CacheResponse
    {
        $payload = [];

        if (!empty($expiration['ttl']) || !empty($expiration['expireTime'])) {
            $payload = [];
            if (!empty($expiration['expireTime'])) {
                $payload['expireTime'] = $expiration['expireTime'];
            } elseif (!empty($expiration['ttl'])) {
                $payload['ttl'] = $expiration['ttl'];
            } else {
                $payload['ttl'] = config('gemini.caching.default_ttl');
            }
        } else {
            throw new ValidationException('TTL or expireTime is required for update.');
        }

        try {
            $response = $this->http->patch("/v1beta/{$name}", $payload);
            return $this->handleResponse($response, 'Cache');
        } catch (\Exception $e) {
            throw new ApiException("Update cache error: {$e->getMessage()}");
        }
    }

    // Delete cached content
    public function deleteCachedContent(string $name): bool
    {
        try {
            $response = $this->http->delete("/v1beta/$name");
            return $response->successful();
        } catch (\Exception $e) {
            throw new ApiException("Delete cache error: {$e->getMessage()}");
        }
    }

    public function models(): array
    {
        $response = $this->http->get('/v1beta/models');
        return $response->json()['models'];
    }

    public function streaming(array $params, callable $callback): void
    {
        $method = $params['method'] ?? 'generateContent';
        if ($method !== 'generateContent') {
            throw new ValidationException('Streaming only supported for generateContent method.');
        }
        try {
            $response = $this->http->withOptions([
                'stream' => true,
            ])->post("/v1beta/models/{$params['model']}:streamGenerateContent", $this->buildRequestBody($params));
            
            $body = $response->getBody();
            $buffer = '';

            while (!$body->eof()) {
                $chunk = $body->read(config('gemini.stream.chunk_size', '1024'));
                if (!empty($chunk)) {
                    $buffer .= $chunk;
                    $lines = explode("\n", $buffer);
                    $buffer = array_pop($lines); // Keep last incomplete line

                    foreach ($lines as $line) {
                        if (strpos($line, 'data: ') === 0) {
                            $jsonStr = substr($line, 5); // Remove 'data: ' prefix
                            $data = json_decode(trim($jsonStr), true);
                            
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $part = $data['candidates'][0]['content']['parts'][0] ?? [];
                                $callback($part);
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            throw new StreamException(
                $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
    
    protected function executeRequest(array $params, string $responseType)
    {
        $method = $params['method'] ?? 'generateContent';
        $body = $this->buildRequestBody($params, $method === 'predictLongRunning', $responseType === 'Audio');
        $endpoint = "/v1beta/models/{$params['model']}:" . $method;

        $response = $this->http->post($endpoint, $body);

        if ($method === 'predictLongRunning') {
            $operation = $response->json()['name'];
            do {
                sleep(5);
                $status = $this->http->get($operation)->json();
            } while (!$status['done']);
            return $this->handleResponse($this->http->get("/v1beta/".$status['response']['generatedSamples'][0][$responseType === 'Video' ? 'video' : 'uri']), $responseType);
        }

        // Check for error response
        if (isset($response->json()['candidates'][0]['finishReason']) && $response->json()['candidates'][0]['finishReason'] != 'STOP') {
            Log::error('Gemini API error response', ['response' => $response->json()]);
            throw new ApiException("API request failed with finishReason: {$response->json()['candidates'][0]['finishReason']}");
        }
        
        return $this->handleResponse($response, $responseType);
    }

    protected function buildRequestBody(array $params, bool $forLongRunning = false, bool $forAudio = false): array
    {
        $method = $params['method'] ?? 'generateContent';
        $isPredict = $method === 'predict' || $method === 'predictLongRunning';
        if ($isPredict) {
            // Structure for predict/predictLongRunning
            $instance = ['prompt' => $params['prompt'] ?? ''];
            if (isset($params['filePath']) && isset($params['fileType'])) {
                $filePart = $params['fileType'] === 'image' ? [
                    'inlineData' => [
                        'mimeType' => $this->getMimeType($params['fileType'], $params['filePath']),
                        'data' => base64_encode(file_get_contents($params['filePath']))
                    ]
                    ] : [
                    'fileData' => [
                        'mimeType' => $this->getMimeType($params['fileType'], $params['filePath']),
                        'fileUri' => $this->upload($params['fileType'], $params['filePath'])
                    ]
                ];
                $instance = array_merge($instance, $filePart);
            } elseif (isset($params['fileUri']) && isset($params['fileType'])) {
                $filePart = [
                    'fileData' => [
                        'mimeType' => $params['fileType'],
                        'fileUri' => $params['fileUri']
                    ]
                ];
                $instance = array_merge($instance, $filePart);
            }
            $body = [
                'instances' => [$instance],
                'parameters' => [
                    'temperature' => $params['temperature'] ?? 0.7,
                    'maxOutputTokens' => $params['maxTokens'] ?? 1024,
                ],
            ];
            if (isset($params['safetySettings'])) {
                $body['parameters']['safetySettings'] = $params['safetySettings'];
            }
        } else {
            // Structure for generateContent
            if (!isset($params['prompt']) || empty($params['prompt'])) {
            throw new ValidationException('Prompt is required for audio generation (TTS).');
            }
            $body = [
                'contents' => $params['contents'] ?? [['parts' => [['text' => $params['prompt'] ?? '']]]],
                'generationConfig' => [
                    'temperature' => $params['temperature'] ?? 0.7,
                    'maxOutputTokens' => $params['maxTokens'] ?? 1024,
                ],
                'safetySettings' => $params['safetySettings'] ?? config('gemini.safety_settings'),
            ];
            if (isset($params['filePath']) && isset($params['fileType'])) {
                $filePart = $params['fileType'] === 'image' ? [
                    'inlineData' => [
                        'mimeType' => $this->getMimeType($params['fileType'], $params['filePath']),
                        'data' => base64_encode(file_get_contents($params['filePath']))
                    ]
                ] : [
                    'fileData' => [
                        'mimeType' => $this->getMimeType($params['fileType'], $params['filePath']),
                        'fileUri' => $this->upload($params['fileType'], $params['filePath'])
                    ]
                ];
                $body['contents'][0]['parts'][] = $filePart;
            } elseif (isset($params['fileUri']) && isset($params['fileType'])) {
                $filePart = [
                    'fileData' => [
                        'mimeType' => $params['fileType'],
                        'fileUri' => $params['fileUri']
                    ]
                ];
                $body['contents'][0]['parts'][] = $filePart;
            }
            if ($forAudio) {
                $body['generationConfig']['responseModalities'] = ['AUDIO'];
                $speechConfig = config('gemini.default_speech_config', []);
                if (isset($params['multiSpeaker']) && $params['multiSpeaker']) {
                    $speechConfig['multiSpeakerVoiceConfig'] = [
                        'speakerVoiceConfigs' => $params['speakerVoices'] ?? []
                    ];
                } else {
                    $speechConfig['voiceConfig'] = [
                        'prebuiltVoiceConfig' => [
                            'voiceName' => $params['voiceName'] ?? $speechConfig['voiceName'] ?? config('gemini.providers.gemini.default_speech_config.voiceName')
                        ]
                    ];
                }
                $body['generationConfig']['speechConfig'] = $speechConfig;
            }
        }

        if (isset($params['system'])) {
            $body[$isPredict ? 'parameters' : 'systemInstruction'] = ['parts' => [['text' => $params['system']]]];
        }

        if (isset($params['history'])) {
            $body['contents'] = [['role' => 'user', 'parts' => [['text' => $params['prompt'] ?? '']]]];
            $body[$isPredict ? 'instances' : 'contents'] = array_merge($params['history'], $body[$isPredict ? 'instances' : 'contents']);
        }

        if (isset($params['functions'])) {
            $body[$isPredict ? 'parameters' : 'tools'] = ['functionDeclarations' => $params['functions']];
        }

        if (isset($params['structuredSchema'])) {
            $body[$isPredict ? 'parameters' : 'generationConfig']['responseMimeType'] = 'application/json';
            $body[$isPredict ? 'parameters' : 'generationConfig']['responseSchema'] = $params['structuredSchema'];
        }

        if ($forLongRunning) {
        // For predictLongRunning, no additional changes needed as per docs
        }
        return $body;
    }
}