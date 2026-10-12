<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class ExternalImageClient
{
    protected $baseUrl;
    protected $timeout;
    protected $client;

    public function __construct()
    {
        $this->baseUrl = config('services.test_signal.base_url');
        $this->timeout = config('services.test_signal.download_timeout', 15);
        $this->client = new Client([
            'timeout'         => $this->timeout,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);
    }

    /**
     * Download image dari external service berdasarkan filename.
     *
     * @param  string  $filename
     * @return array{success: bool, content?: string, error?: string}
     */
    public function downloadImage(string $filename)
    {
        $imageUrl = rtrim($this->baseUrl, '/') . '/image/' . rawurlencode($filename);

        Log::info('[ExternalImageClient] Downloading image', [
            'url'      => $imageUrl,
            'filename' => $filename,
        ]);

        try {
            $response = $this->client->get($imageUrl, [
                'headers' => [
                    'Accept' => 'image/png, application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $body = json_decode($response->getBody()->getContents(), true);

                Log::warning('[ExternalImageClient] Non-200 response', [
                    'status_code' => $statusCode,
                    'response'    => $body,
                ]);

                return [
                    'success' => false,
                    'error'   => 'External service returned status ' . $statusCode,
                ];
            }

            $content = $response->getBody()->getContents();

            Log::info('[ExternalImageClient] Image downloaded', [
                'filename' => $filename,
                'size'     => strlen($content),
            ]);

            return [
                'success' => true,
                'content' => $content,
            ];

        } catch (ConnectException $e) {
            Log::error('[ExternalImageClient] Connection failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error'   => 'Gagal terhubung ke external service: ' . $e->getMessage(),
            ];

        } catch (RequestException $e) {
            Log::error('[ExternalImageClient] Request failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error'   => 'Request error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * List semua file yang tersedia di external service.
     *
     * @return array{success: bool, files?: array, error?: string}
     */
    public function listFiles()
    {
        $listUrl = rtrim($this->baseUrl, '/') . '/files';

        try {
            $response = $this->client->get($listUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($statusCode !== 200 || empty($body['success'])) {
                return [
                    'success' => false,
                    'error'   => 'Failed to list files from external service',
                ];
            }

            return [
                'success' => true,
                'files'   => $body['files'] ?? [],
            ];

        } catch (ConnectException $e) {
            Log::error('[ExternalImageClient] List files connection failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error'   => 'Gagal terhubung ke external service: ' . $e->getMessage(),
            ];

        } catch (RequestException $e) {
            Log::error('[ExternalImageClient] List files request failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error'   => 'Request error: ' . $e->getMessage(),
            ];
        }
    }
}
