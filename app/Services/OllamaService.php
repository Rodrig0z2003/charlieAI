<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\KnowledgeChunk;

class OllamaService
{
    protected string $baseUrl;
    protected string $chatModel;
    protected string $embedModel;

    public function __construct()
    {
        $this->baseUrl = config('services.ollama.base_url', 'http://127.0.0.1:11434');
        $this->chatModel = config('services.ollama.chat_model', 'llama3.1');
        $this->embedModel = config('services.ollama.embed_model', 'nomic-embed-text');
    }

    public function generateEmbedding(string $text): array
    {
        try {
            $response = Http::timeout(120)->post("{$this->baseUrl}/api/embeddings", [
                'model'      => $this->embedModel,
                'prompt'     => $text,
                'keep_alive' => -1,
            ]);

            if ($response->successful()) {
                return $response->json('embedding', []);
            }
            Log::error('Ollama Embedding Response Error: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Ollama Embedding Exception: ' . $e->getMessage());
        }

        return [];
    }

    public function getGlobalBehaviorRules(): string
    {
        $chunks = KnowledgeChunk::where('is_system_rule', true)
            ->orderBy('id', 'asc')
            ->take(3)
            ->get();

        if ($chunks->isEmpty()) {
            return '';
        }

        $rulesText = "";
        foreach ($chunks as $chunk) {
            $rulesText .= "- " . trim($chunk->chunk_content) . "\n\n";
        }

        return trim($rulesText);
    }

    public function searchRelevantChunks(string $query, int $limit = 4): string
    {
        $normalized = strtolower(trim($query));
        if (strlen($normalized) <= 10 && in_array($normalized, ['hi', 'hello', 'hey', 'hola', 'thanks', 'gracias'])) {
            return '';
        }

        $queryEmbedding = $this->generateEmbedding($query);
        if (empty($queryEmbedding)) {
            return '';
        }

        $searchTerms = preg_replace('/[+\-><\(\)~*\"@]+/', ' ', $query);
        $searchTerms = trim(preg_replace('/\s+/', ' ', $searchTerms));

        $queryBuilder = KnowledgeChunk::where('is_system_rule', false);

        if (!empty($searchTerms)) {
            $candidates = $queryBuilder->whereRaw("MATCH(chunk_content) AGAINST(? IN NATURAL LANGUAGE MODE)", [$searchTerms])
                ->take(25)
                ->get();
        } else {
            $candidates = collect();
        }

        if ($candidates->isEmpty()) {
            $candidates = KnowledgeChunk::where('is_system_rule', false)
                ->latest('id')
                ->take(30)
                ->get();
        }

        $scoredChunks = [];
        foreach ($candidates as $chunk) {
            $embedding = is_string($chunk->embedding) ? json_decode($chunk->embedding, true) : $chunk->embedding;
            if (is_array($embedding)) {
                $similarity = $this->cosineSimilarity($queryEmbedding, $embedding);
                if ($similarity >= 0.25) {
                    $scoredChunks[] = [
                        'doc'     => $chunk->document_name,
                        'content' => $chunk->chunk_content,
                        'score'   => $similarity,
                    ];
                }
            }
        }

        if (empty($scoredChunks)) {
            return '';
        }

        usort($scoredChunks, fn($a, $b) => $b['score'] <=> $a['score']);

        $context = "";
        foreach (array_slice($scoredChunks, 0, $limit) as $item) {
            $context .= "• " . trim($item['content']) . "\n\n";
        }

        return trim($context);
    }

    public function chat(array $messages, ?string $overrideModel = null): array
    {
        try {
            $response = Http::timeout(120)->post("{$this->baseUrl}/api/chat", [
                'model'      => $overrideModel ?: $this->chatModel,
                'messages'   => $messages,
                'stream'     => false,
                'keep_alive' => -1,
                'options'    => [
                    'temperature' => 0.1,
                    'num_ctx'     => 4096,
                    'num_thread'  => 4,
                    'num_predict' => 700,
                ]
            ]);

            if ($response->successful()) {
                return $response->json('message', []);
            }
            Log::error('Ollama Chat Error: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Ollama Chat Exception: ' . $e->getMessage());
        }

        return [];
    }

    private function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $count = count($vecA);

        if ($count === 0 || count($vecB) !== $count) {
            return 0.0;
        }

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $vecA[$i] * $vecB[$i];
            $normA += $vecA[$i] ** 2;
            $normB += $vecB[$i] ** 2;
        }

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    public function chatStream(
        array $messages, 
        callable $callback, 
        array $customOptions = [], 
        ?string $overrideModel = null
    ): void {
        $defaultOptions = [
            'temperature' => 0.1,
            'num_ctx'     => 4096,
            'num_thread'  => 4,
            'num_predict' => 700,
        ];

        $options = array_merge($defaultOptions, $customOptions);

        $body = json_encode([
            'model'      => $overrideModel ?: $this->chatModel,
            'messages'   => $messages,
            'stream'     => true,
            'keep_alive' => -1,
            'options'    => $options,
        ]);

        $buffer = ''; // Acumulador para reconstruir líneas fragmentadas

        $ch = curl_init("{$this->baseUrl}/api/chat");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) use ($callback, &$buffer) {
                $buffer .= $data;

                // Procesa únicamente líneas completas que terminen en \n
                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newlinePos);
                    $buffer = substr($buffer, $newlinePos + 1);

                    $trimmedLine = trim($line);
                    if (!empty($trimmedLine)) {
                        $callback($trimmedLine);
                    }
                }

                return strlen($data);
            }
        ]);

        curl_exec($ch);

        if (curl_errno($ch)) {
            Log::error('Ollama cURL Stream Error: ' . curl_error($ch));
        }

        curl_close($ch);
    }

    public function chatWithTools(array $messages, array $tools): array
    {
        $body = [
            'model'    => $this->chatModel,
            'messages' => $messages,
            'tools'    => $tools,
            'stream'   => false,
            'options'  => [
                'temperature' => 0.1,
                'num_ctx'     => 4096,
            ]
        ];

        try {
            $response = Http::timeout(60)->post("{$this->baseUrl}/api/chat", $body);
            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::error('Ollama Tool Call Exception: ' . $e->getMessage());
        }

        return [];
    }
}