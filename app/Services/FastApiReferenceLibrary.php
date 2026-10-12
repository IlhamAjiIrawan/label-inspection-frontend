<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Client untuk Reference Library permanen di FastAPI
 * (PLAN_MYSQL_BACKED_REFERENCE_STORAGE — FastAPI stateless, data di
 *  MySQL label_inspection ref_library + ref_blobs, dedup SHA256).
 *
 * Dipakai untuk:
 * - Jalur A (Create New): push() tiap Simpan Reference — best effort,
 *   tidak pernah menggagalkan penyimpanan lokal.
 * - Jalur B (Choose): listLibrary() + getDetail() + downloadImage()
 *   untuk tab "Library Global" + import menjadi reference lokal.
 * - Monitoring (§9): health() untuk dashboard + schedule ref:library-health.
 *
 * Semua method public TIDAK PERNAH throw — return null/false
 * saat FastAPI mati/timeout agar flow lokal tetap jalan.
 */
class FastApiReferenceLibrary
{
    protected $baseUrl;
    protected $timeout;
    protected $apiKey;
    protected $client;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.reference_library.base_url', 'http://127.0.0.1:9000/api/references'), '/');
        $this->timeout = (int) config('services.reference_library.timeout', 8);
        $this->apiKey  = config('services.reference_library.api_key');
        $this->client  = new Client([
            'timeout'         => $this->timeout,
            'connect_timeout' => 3,
            'http_errors'     => false,
        ]);
    }

    protected function getHeaders()
    {
        $headers = ['Accept' => 'application/json'];
        if (!empty($this->apiKey)) {
            $headers['X-API-Key'] = $this->apiKey;
        }
        return $headers;
    }

    /**
     * Push 1 gambar ke library FastAPI (Jalur A).
     *
     * @param  string  $absolutePath  Path absolut file gambar lokal
     * @param  string  $name          Nama tampil 3..80 char (nama popup user, fallback template_name)
     * @param  string  $templateId    Kode template (S/M/L/XL/TL atau bebas)
     * @param  string  $userId        Opsional audit (dipetakan ke user_ref FastAPI, tanpa query tambahan)
     * @param  string  $side          Sisi label: 'front' | 'back' | '' (kosong = default front di FastAPI)
     * @return array|null  ['ref_id'=>..., 'duplicate'=>bool, 'ocr_status'=>...] atau null jika gagal
     */
    public function pushReference(string $absolutePath, string $name, string $templateId = '', string $userId = '', string $side = ''): ?array
    {
        try {
            if (!is_file($absolutePath) || !is_readable($absolutePath)) {
                Log::warning('[REF-LIB] push skip: file tidak ada', ['path' => $absolutePath]);
                return null;
            }

            $contents = file_get_contents($absolutePath);
            if ($contents === false || strlen($contents) === 0) {
                Log::warning('[REF-LIB] push skip: file kosong/tidak terbaca', ['path' => $absolutePath]);
                return null;
            }

            $multipart = [
                ['name' => 'image',       'contents' => $contents, 'filename' => basename($absolutePath)],
                ['name' => 'name',        'contents' => mb_substr(trim($name) ?: 'Label Reference', 0, 80)],
                ['name' => 'template_id', 'contents' => mb_substr((string) $templateId, 0, 32)],
                ['name' => 'source',      'contents' => 'label-inspection'],
            ];
            // FIX_REFERENCE_OCR_ACUAN_PER_LABEL (2026-10-07) L1: teruskan sisi
            // agar FastAPI mengisi cleaned_text_front / cleaned_text_back yang benar.
            // 'single'/kosong = default front di FastAPI (backward compat).
            $cleanSide = strtolower(trim($side));
            if (in_array($cleanSide, ['front', 'back'], true)) {
                $multipart[] = ['name' => 'side', 'contents' => $cleanSide];
            }
            if (trim($userId) !== '') {
                $multipart[] = ['name' => 'user_id', 'contents' => mb_substr(trim($userId), 0, 64)];
            }
            $response = $this->client->post($this->baseUrl, [
                'headers'   => $this->getHeaders(),
                'multipart' => $multipart,
            ]);

            $httpCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('[REF-LIB] push: respon bukan JSON', ['http_code' => $httpCode]);
                return null;
            }

            // 201 created baru, 200 duplicate (gambar identik sudah ada) — keduanya sukses
            if ($httpCode === 201 || $httpCode === 200) {
                Log::info('[REF-LIB] push ok', [
                    'http_code' => $httpCode,
                    'ref_id'    => $body['ref_id'] ?? null,
                    'duplicate' => $body['duplicate'] ?? false,
                    'side'      => $cleanSide ?? '',
                ]);
                return $body;
            }

            Log::warning('[REF-LIB] push non-2xx (best effort, diabaikan)', [
                'http_code' => $httpCode,
                'response'  => $body,
            ]);
            return null;
        } catch (ConnectException $e) {
            Log::warning('[REF-LIB] push gagal koneksi (best effort, diabaikan)', ['error' => $e->getMessage()]);
            return null;
        } catch (RequestException $e) {
            Log::warning('[REF-LIB] push HTTP error (best effort, diabaikan)', ['error' => $e->getMessage()]);
            return null;
        } catch (\Exception $e) {
            Log::warning('[REF-LIB] push exception (best effort, diabaikan)', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * List library FastAPI dengan paginasi + search server-side (Jalur B, tab Global).
     *
     * @return array|null  Envelope FastAPI ['items','total','page','per_page','total_pages'] atau null
     */
    public function listLibrary(string $q = '', string $templateId = '', int $page = 1, int $perPage = 20): ?array
    {
        try {
            $perPage = max(1, min(50, $perPage));
            $page = max(1, $page);

            $response = $this->client->get($this->baseUrl, [
                'headers' => $this->getHeaders(),
                'query'   => [
                    'q'           => $q,
                    'template_id' => $templateId,
                    'page'        => $page,
                    'per_page'    => $perPage,
                    'sort'        => 'recent_used',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::warning('[REF-LIB] list non-200', ['http_code' => $response->getStatusCode()]);
                return null;
            }

            $body = json_decode($response->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE || !($body['success'] ?? false)) {
                Log::warning('[REF-LIB] list respon invalid');
                return null;
            }

            // Rewrite thumb_url relatif -> absolut agar <img> browser bisa langsung pakai
            // (FastAPI /thumb public, tidak butuh auth, tidak kena CORS untuk img tag).
            if (!empty($body['items']) && is_array($body['items'])) {
                foreach ($body['items'] as &$item) {
                    if (!empty($item['thumb_url']) && strpos($item['thumb_url'], '/') === 0) {
                        $item['thumb_url'] = $this->baseUrl . preg_replace('#^/api/references#', '', $item['thumb_url']);
                        // baseUrl sudah berakhiran /api/references, jadi hasil = base + /{id}/thumb
                    }
                    if (!empty($item['ref_id'])) {
                        $item['image_url'] = $this->baseUrl . '/' . $item['ref_id'] . '/image';
                    }
                }
                unset($item);
            }

            return $body;
        } catch (ConnectException $e) {
            Log::warning('[REF-LIB] list gagal koneksi', ['error' => $e->getMessage()]);
            return null;
        } catch (RequestException $e) {
            Log::warning('[REF-LIB] list HTTP error', ['error' => $e->getMessage()]);
            return null;
        } catch (\Exception $e) {
            Log::warning('[REF-LIB] list exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Health check library FastAPI (DB ping, L2 plan + monitoring §9).
     * Endpoint ini SENGAJA tanpa API key di FastAPI (untuk load-balancer).
     *
     * @return array|null  ['ok'=>bool, 'store'=>..., 'latency_ms'=>...] atau null jika tak terjangkau
     */
    public function health(): ?array
    {
        try {
            $start = microtime(true);
            $response = $this->client->get($this->baseUrl . '/health', [
                'headers' => ['Accept' => 'application/json'],
            ]);
            $ms = round((microtime(true) - $start) * 1000, 1);

            $httpCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
                Log::warning('[REF-LIB] health respon bukan JSON', ['http_code' => $httpCode]);
                return null;
            }

            // FastAPI balas 200 {success, db:ok, store, latency_ms} atau 503 {db:down}
            $ok = $httpCode === 200 && ($body['success'] ?? false) && ($body['db'] ?? '') === 'ok';
            return [
                'ok'         => $ok,
                'http_code'  => $httpCode,
                'store'      => $body['store'] ?? ($body['db'] ?? 'unknown'),
                'latency_ms' => $body['latency_ms'] ?? $ms,
            ];
        } catch (ConnectException $e) {
            Log::warning('[REF-LIB] health gagal koneksi', ['error' => $e->getMessage()]);
            return null;
        } catch (RequestException $e) {
            Log::warning('[REF-LIB] health HTTP error', ['error' => $e->getMessage()]);
            return null;
        } catch (\Exception $e) {
            Log::warning('[REF-LIB] health exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Detail 1 item library (untuk import).
     *
     * @return array|null  Detail FastAPI (cleaned_text, lines, engine, ...) atau null
     */
    public function getDetail(string $refId): ?array
    {
        try {
            if (!preg_match('/^ref-[A-Za-z0-9\-_]+$/', $refId)) {
                return null;
            }

            $response = $this->client->get($this->baseUrl . '/' . $refId, [
                'headers' => $this->getHeaders(),
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $body = json_decode($response->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE || !($body['success'] ?? false)) {
                return null;
            }
            return $body;
        } catch (\Exception $e) {
            Log::warning('[REF-LIB] detail gagal', ['ref_id' => $refId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Download bytes gambar asli dari library (untuk import menjadi reference lokal).
     *
     * @return string|null  Bytes JPEG atau null
     */
    public function downloadImage(string $refId): ?string
    {
        try {
            if (!preg_match('/^ref-[A-Za-z0-9\-_]+$/', $refId)) {
                return null;
            }

            $response = $this->client->get($this->baseUrl . '/' . $refId . '/image', [
                'headers' => ['Accept' => 'image/*'],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $bytes = $response->getBody()->getContents();
            return strlen($bytes) > 0 ? $bytes : null;
        } catch (\Exception $e) {
            Log::warning('[REF-LIB] download gagal', ['ref_id' => $refId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Rename 1 item library FastAPI (sinkron nama popup lokal → global).
     * PATCH /api/references/{ref_id} {name}. Best-effort: return null bila
     * koneksi gagal; 1 request ringan (1 UPDATE indexed PK di FastAPI).
     *
     * @return array|null  ['http_code'=>int, 'body'=>?array]; null bila koneksi/exception gagal.
     */
    public function renameReference(string $refId, string $name): ?array
    {
        try {
            if (!preg_match('/^ref-[A-Za-z0-9\-_]+$/', $refId)) {
                return null;
            }
            $clean = mb_substr(trim($name), 0, 80);
            if (mb_strlen($clean) < 3) {
                return null;
            }

            $response = $this->client->patch($this->baseUrl . '/' . $refId, [
                'headers' => array_merge($this->getHeaders(), ['Content-Type' => 'application/json', 'Accept' => 'application/json']),
                'json'    => ['name' => $clean],
            ]);

            $httpCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $body = null;
            }

            Log::info('[REF-LIB] rename', ['ref_id' => $refId, 'http_code' => $httpCode]);

            return ['http_code' => $httpCode, 'body' => $body];
        } catch (ConnectException $e) {
            Log::warning('[REF-LIB] rename gagal koneksi', ['ref_id' => $refId, 'error' => $e->getMessage()]);
            return null;
        } catch (RequestException $e) {
            Log::warning('[REF-LIB] rename HTTP error', ['ref_id' => $refId, 'error' => $e->getMessage()]);
            return null;
        } catch (\Exception $e) {
            Log::warning('[REF-LIB] rename exception', ['ref_id' => $refId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Hapus 1 item dari library FastAPI (dipakai tombol Delete card Choose).
     *
     * @return array|null  ['http_code'=>int, 'body'=>?array]; null bila koneksi/exception gagal.
     *                     Tidak pernah throw. 404 = item sudah tidak ada (dianggap hilang).
     */
    public function deleteReference(string $refId): ?array
    {
        try {
            if (!preg_match('/^ref-[A-Za-z0-9\-_]+$/', $refId)) {
                return null;
            }

            $response = $this->client->delete($this->baseUrl . '/' . $refId, [
                'headers' => $this->getHeaders(),
            ]);

            $httpCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $body = null;
            }

            Log::info('[REF-LIB] delete', ['ref_id' => $refId, 'http_code' => $httpCode]);

            return ['http_code' => $httpCode, 'body' => $body];
        } catch (ConnectException $e) {
            Log::warning('[REF-LIB] delete gagal koneksi', ['ref_id' => $refId, 'error' => $e->getMessage()]);
            return null;
        } catch (RequestException $e) {
            Log::warning('[REF-LIB] delete HTTP error', ['ref_id' => $refId, 'error' => $e->getMessage()]);
            return null;
        } catch (\Exception $e) {
            Log::warning('[REF-LIB] delete exception', ['ref_id' => $refId, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
