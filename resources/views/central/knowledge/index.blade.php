<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-bold text-xl text-gray-800 leading-tight">
                    AI Central Knowledge Base
                </h2>
                <p class="text-xs text-gray-500 mt-1">Manage global manuals, policies, and training vectors for tenant chatbots.</p>
            </div>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                Ollama RAG Active
            </span>
        </div>
    </x-slot>

    <div class="py-8" x-data="{ openDoc: null }">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <!-- Success Alert -->
            @if(session('success'))
                <div class="flex items-center gap-3 p-4 text-sm text-emerald-800 bg-emerald-50 border border-emerald-200 rounded-xl shadow-sm">
                    <svg class="w-5 h-5 flex-shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="font-medium">{{ session('success') }}</span>
                </div>
            @endif

            <!-- Error Alert -->
            @if(session('error'))
                <div class="flex items-center gap-3 p-4 text-sm text-rose-800 bg-rose-50 border border-rose-200 rounded-xl shadow-sm">
                    <svg class="w-5 h-5 flex-shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="font-medium">{{ session('error') }}</span>
                </div>
            @endif

            <!-- Validation Errors -->
            @if($errors->any())
                <div class="p-4 text-sm text-rose-800 bg-rose-50 border border-rose-200 rounded-xl shadow-sm space-y-1">
                    <div class="font-semibold flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        Upload Failed:
                    </div>
                    <ul class="list-disc list-inside text-xs pl-2 space-y-0.5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Upload Section -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200">
                <div class="mb-4">
                    <h3 class="text-base font-bold text-gray-900">Upload Global Training Document</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Vectorize business policies, product specs, or manuals to empower AI responses across all stores.</p>
                </div>

                <form action="/knowledge" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div class="flex flex-col sm:flex-row gap-3 items-center">
                        <div class="relative flex-1 w-full">
                            <input type="file" name="document" accept=".pdf,.txt" required
                                class="block w-full text-xs text-gray-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 file:cursor-pointer border border-gray-300 rounded-xl bg-gray-50/50 cursor-pointer focus:outline-none">
                        </div>
                        <button type="submit"
                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-xs font-semibold px-6 py-3 rounded-xl shadow-sm transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                            </svg>
                            Process & Index
                        </button>
                    </div>
                    <div class="flex items-center gap-2 text-[11px] text-gray-400">
                        <span>Supported formats: <strong>PDF, TXT</strong></span>
                        <span>•</span>
                        <span>Max file size: <strong>10MB</strong></span>
                    </div>
                </form>
            </div>

            <!-- Documents & Chunks Explorer -->
            <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-200">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">Indexed Knowledge Documents</h3>
                        <p class="text-xs text-gray-500 mt-0.5">Inspect the segmented chunks and embeddings stored in the central database.</p>
                    </div>
                    <span class="text-xs font-semibold bg-gray-100 text-gray-600 px-3 py-1 rounded-lg">
                        {{ $documents->count() }} {{ Str::plural('Document', $documents->count()) }}
                    </span>
                </div>

                <div class="space-y-4">
                    @forelse($documents as $doc)
                        <div class="border border-gray-200 rounded-xl transition-all duration-200 hover:border-gray-300 bg-white">
                            
                            <!-- Card Header -->
                            <div class="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-gray-50/75 rounded-t-xl">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0 font-bold text-sm border border-blue-100">
                                        {{ strtoupper(pathinfo($doc->document_name, PATHINFO_EXTENSION) ?: 'DOC') }}
                                    </div>
                                    <div>
                                        <h4 class="text-sm font-bold text-gray-900 leading-tight">{{ $doc->document_name }}</h4>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-blue-50 text-blue-700 border border-blue-200/50">
                                                {{ $doc->total_chunks }} {{ Str::plural('Vector Chunk', $doc->total_chunks) }}
                                            </span>
                                            <span class="text-[11px] text-gray-400">Indexed on {{ \Carbon\Carbon::parse($doc->created_at)->format('M d, Y') }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 self-end sm:self-center">
                                    <!-- Toggle Preview Button -->
                                    <button @click="openDoc = (openDoc === '{{ $doc->document_name }}' ? null : '{{ $doc->document_name }}')"
                                        class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-lg transition border"
                                        :class="openDoc === '{{ $doc->document_name }}' 
                                            ? 'bg-blue-50 text-blue-700 border-blue-200' 
                                            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'">
                                        <svg class="w-3.5 h-3.5 transition-transform" :class="openDoc === '{{ $doc->document_name }}' ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                        <span x-text="openDoc === '{{ $doc->document_name }}' ? 'Hide Chunks' : 'Inspect Chunks'"></span>
                                    </button>
                                    <a href="/knowledge/{{ urlencode($doc->document_name) }}/download" 
                                    class="inline-flex items-center gap-1 text-xs font-semibold px-3 py-2 rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:text-emerald-800 border border-emerald-200 transition"
                                    title="Download complete text">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                        </svg>
                                        Download
                                    </a>

                                    <!-- Delete Document Form -->
                                    <form action="/knowledge/{{ urlencode($doc->document_name) }}" method="POST"
                                        onsubmit="return confirm('Are you sure you want to permanently delete this document and all its indexed vector embeddings?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                            class="inline-flex items-center gap-1 text-xs font-semibold px-3 py-2 rounded-lg bg-rose-50 text-rose-600 hover:bg-rose-100 hover:text-rose-700 border border-rose-200 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Chunk Explorer Panel -->
                            <div x-show="openDoc === '{{ $doc->document_name }}'" x-collapse class="border-t border-gray-200 bg-gray-50/50 p-4 space-y-3">
                                <div class="flex items-center justify-between text-xs text-gray-500 mb-1 px-1">
                                    <span class="font-semibold uppercase tracking-wider text-[11px]">Training Segments (RAG Chunks):</span>
                                    <span>Overlap: 100 chars | Max chunk size: 600 chars</span>
                                </div>

                                <div class="max-h-96 overflow-y-auto space-y-3 pr-1">
                                    @foreach($allChunks[$doc->document_name] ?? [] as $index => $chunk)
                                        <div class="bg-white border border-gray-200/80 rounded-xl p-4 shadow-sm hover:shadow transition">
                                            <div class="flex items-center justify-between pb-2 mb-2.5 border-b border-gray-100">
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-flex items-center justify-center px-2 py-0.5 rounded text-[11px] font-bold bg-blue-600 text-white">
                                                        Chunk #{{ $index + 1 }}
                                                    </span>
                                                    <span class="text-[11px] font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-100">
                                                        Vectorized
                                                    </span>
                                                </div>
                                                <span class="text-[11px] text-gray-400 font-mono">
                                                    {{ strlen($chunk->chunk_content) }} characters
                                                </span>
                                            </div>
                                            
                                            <!-- Pre-wrapped Chunk Content to preserve linebreaks -->
                                            <p class="text-xs text-gray-700 leading-relaxed font-normal whitespace-pre-wrap select-text bg-slate-50/60 p-3 rounded-lg border border-slate-100">{{ $chunk->chunk_content }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-12 border-2 border-dashed border-gray-200 rounded-2xl">
                            <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <h3 class="mt-2 text-sm font-bold text-gray-800">No documents indexed yet</h3>
                            <p class="mt-1 text-xs text-gray-500">Upload a TXT or PDF file above to start training the central AI model.</p>
                        </div>
                    @endforelse
                </div>
            </div>

        </div>
    </div>
</x-app-layout>