<?php

namespace HosseinHezami\LaravelGemini\Providers;

use HosseinHezami\LaravelGemini\Http\HttpClient;
use HosseinHezami\LaravelGemini\Exceptions;
use Illuminate\Support\Facades\Http;

abstract class BaseProvider
{
    protected HttpClient $http;

    public function __construct(?string $apiKey = null)
    {
        $this->http = new HttpClient(apiKey: $apiKey);
    }

    protected function handleResponse($response, string $type)
    {
        if ($response->failed()) {
            $status = $response->status();
            if ($status === 401) {
                throw new Exceptions\AuthenticationException();
            } elseif ($status === 429) {
                throw new Exceptions\RateLimitException(retryAfter: $response->header('Retry-After'));
            } elseif ($status >= 500) {
                throw new Exceptions\ApiException();
            } elseif ($status === 400) {
                throw new Exceptions\ValidationException();
            } else {
                throw new Exceptions\NetworkException();
            }
        }

        $data = $response->json();

        return new ("HosseinHezami\\LaravelGemini\\Responses\\" . $type . "Response")($data);
    }
    
    /**
     * Upload a file to Gemini API and return its URI.
     *
     * @param string $fileType Type of file (e.g., 'image', 'video', 'audio', 'document')
     * @param string $filePath Path to the file
     * @return string File URI returned by the API
     * @throws Exceptions\ValidationException If file type is invalid or file not found
     * @throws Exceptions\ApiException
     * @throws Exceptions\RateLimitException
     */
    protected function upload(string $fileType, string $filePath): string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new Exceptions\ValidationException("File does not exist or is not readable: {$filePath}");
        }

        $validTypes = ['image', 'video', 'audio', 'document'];
        if (!in_array($fileType, $validTypes)) {
            throw new Exceptions\ValidationException("Invalid file type: {$fileType}. Allowed types: " . implode(', ', $validTypes));
        }

        $mimeType = $this->getMimeType($fileType, $filePath);
        $fileSize = filesize($filePath);
        $displayName = basename($filePath);

        /**
         * Step 1: Initiate resumable upload session
         */
        $initialResponse = $this->http
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post('/upload/v1beta/files?uploadType=resumable', [
                'file' => [
                    'display_name' => $displayName,
                ],
            ]);

        $uploadUrl = $initialResponse->header('Location');

        if (!$uploadUrl) {
            throw new Exceptions\ApiException('Upload URL not received from API');
        }

        /**
         * Step 2: Upload the entire file in a single chunk and finalize
         */
        $uploadResponse = $this->http
            ->withHeaders([
                'Content-Range' => "bytes 0-" . ($fileSize - 1) . "/$fileSize",
            ])
            ->withBody(
                file_get_contents($filePath),
                $mimeType
            )
            ->post($uploadUrl);

        if (!$uploadResponse->successful()) {
            throw new Exceptions\ApiException('Upload failed: ' . $uploadResponse->body());
        }

        $json = $uploadResponse->json();

        if (!isset($json['file']['uri'])) {
            throw new Exceptions\ApiException('File URI not found in API response');
        }

        return $json['file']['uri'];
    }
    
    protected function getMimeType(string $fileType, string $filePath): string
    {
        $mimeTypes = [
            'image' => ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif'],
            'video' => ['mp4' => 'video/mp4', 'mpeg' => 'video/mpeg', 'mov' => 'video/mov', 'avi' => 'video/avi', 'flv' => 'video/x-flv', 'mpg' => 'video/mpg', 'webm' => 'video/webm', 'wmv' => 'video/wmv', '3gpp' => 'video/3gpp'],
            'audio' => ['wav' => 'audio/x-wav', 'mp3' => 'audio/mp3', 'aiff' => 'audio/aiff', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'flac' => 'audio/flac'],
            'document' => ['pdf' => 'application/pdf', 'txt' => 'text/plain', 'md' => 'text/markdown']
        ];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return $mimeTypes[$fileType][$extension] ?? throw new Exceptions\ValidationException("Unsupported {$fileType} format: {$extension}");
    }
}