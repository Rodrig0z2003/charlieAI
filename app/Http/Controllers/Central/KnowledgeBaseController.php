<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\KnowledgeChunk;
use App\Services\OllamaService;
use Smalot\PdfParser\Parser as PdfParser;
use Illuminate\Support\Facades\Log;

class KnowledgeBaseController extends Controller
{
    public function index()
    {
        $documents = KnowledgeChunk::select('document_name')
            ->selectRaw('count(*) as total_chunks, min(created_at) as created_at')
            ->groupBy('document_name')
            ->orderBy('created_at', 'desc')
            ->get();

        $allChunks = KnowledgeChunk::orderBy('id', 'asc')->get()->groupBy('document_name');

        return view('central.knowledge.index', compact('documents', 'allChunks'));
    }

    public function store(Request $request, OllamaService $ollama)
    {
        if (!$request->hasFile('document')) {
            return back()->with('error', 'No file selected.');
        }

        $file = $request->file('document');
        
        if (!$file->isValid()) {
            return back()->with('error', 'Invalid file upload or exceeded max file size.');
        }

        $fileName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        $text = '';

        try {
            if ($extension === 'pdf') {
                $parser = new PdfParser();
                $pdf = $parser->parseFile($file->getPathname());
                $text = $pdf->getText();
            } else {
                $text = file_get_contents($file->getPathname());
            }
        } catch (\Exception $e) {
            Log::error("Error reading {$fileName}: " . $e->getMessage());
            return back()->with('error', 'File read error: ' . $e->getMessage());
        }

        // Limpieza y normalización de texto
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        if (empty($text)) {
            return back()->with('error', 'The document is empty or contains no readable text.');
        }

        $chunks = $this->createChunks($text, 700);
        $savedCount = 0;

        // Detectar si el documento pertenece a reglas del sistema
        $behaviorKeywords = ['academy', 'constitution', 'rules', 'behavior', 'patron', 'protocol', 'reglas'];
        $isSystemRule = false;
        foreach ($behaviorKeywords as $keyword) {
            if (str_contains(strtolower($fileName), $keyword)) {
                $isSystemRule = true;
                break;
            }
        }

        foreach ($chunks as $chunk) {
            $embedding = $ollama->generateEmbedding($chunk);

            if (!empty($embedding)) {
                KnowledgeChunk::create([
                    'document_name' => $fileName,
                    'is_system_rule' => $isSystemRule, // <-- Añadido aquí
                    'chunk_content' => $chunk,
                    'embedding' => $embedding,
                ]);
                $savedCount++;
            }
        }

        if ($savedCount === 0) {
            return back()->with('error', 'Ollama failed to generate embeddings.');
        }

        return back()->with('success', "Document \"$fileName\" indexed successfully ($savedCount chunks created).");
    }

    public function download($documentName)
    {
        $decodedName = urldecode($documentName);

        $chunks = KnowledgeChunk::where('document_name', $decodedName)
            ->orderBy('id', 'asc')
            ->pluck('chunk_content');

        if ($chunks->isEmpty()) {
            return back()->with('error', "No content found for document: {$decodedName}");
        }

        $fullText = $chunks->implode("\n\n");
        $cleanFileName = pathinfo($decodedName, PATHINFO_FILENAME) . '_content.txt';

        return response($fullText, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $cleanFileName . '"',
        ]);
    }

    public function destroy($documentName)
    {
        KnowledgeChunk::where('document_name', urldecode($documentName))->delete();
        return back()->with('success', "Document deleted successfully.");
    }

    private function createChunks(string $text, int $maxChunkSize = 800): array
    {
        $pattern = '/(?=\n(?:\d+[\.\)]|\#{1,3}|[A-Z\s]{4,}:)\s+)/';
        $rawSections = preg_split($pattern, "\n" . $text, -1, PREG_SPLIT_NO_EMPTY);

        $chunks = [];
        $currentChunk = '';

        foreach ($rawSections as $section) {
            $section = trim($section);
            if (empty($section)) {
                continue;
            }

            if (preg_match('/(?:^|\n)Q:/i', $section)) {
                $qaItems = preg_split('/(?=\nQ:)/i', "\n" . $section, -1, PREG_SPLIT_NO_EMPTY);
                
                foreach ($qaItems as $qa) {
                    $qa = trim($qa);
                    if (empty($qa)) continue;

                    if (mb_strlen($currentChunk . "\n\n" . $qa, 'UTF-8') > $maxChunkSize && !empty($currentChunk)) {
                        $chunks[] = trim($currentChunk);
                        $currentChunk = $qa;
                    } else {
                        $currentChunk = empty($currentChunk) ? $qa : $currentChunk . "\n\n" . $qa;
                    }
                }
                continue;
            }

            if (mb_strlen($currentChunk . "\n\n" . $section, 'UTF-8') > $maxChunkSize) {
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                }
                $currentChunk = $section;
            } else {
                $currentChunk = empty($currentChunk) ? $section : $currentChunk . "\n\n" . $section;
            }
        }

        if (!empty(trim($currentChunk))) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }
}	