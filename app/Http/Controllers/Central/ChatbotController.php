<?php
namespace App\Http\Controllers\Central;

use Zxing\QrReader;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\OllamaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ChatbotController extends Controller
{
    private array $endpoints = [
        'dev' => [
            'name'  => 'Dev Store',
            'url'   => 'https://dev.gangsheet-builders.com/api/chatbot/query',
            'token' => 'MI_TOKEN_SECRETO_ULTRA_SUPER_SEGURO_123',
        ],
        'dtt' => [
            'name'  => 'DTT Orders',
            'url'   => 'https://dttorders.gangsheet-builders.com/api/chatbot/query',
            'token' => '362cf5e6af509d6f0adbb4409c885987fe3e97e11de007a1f5d17d15168acaa1',
        ],
    ];

    private array $monthMap = [
        'enero' => 'january', 'febrero' => 'february', 'marzo' => 'march',
        'abril' => 'april', 'mayo' => 'may', 'junio' => 'june',
        'julio' => 'july', 'agosto' => 'august', 'septiembre' => 'september',
        'setiembre' => 'september', 'octubre' => 'october', 'noviembre' => 'november',
        'diciembre' => 'december'
    ];

    /**
     * Valida si el usuario en sesión o petición tiene rol de Administrador.
     */
    private function canAccessAccounting(Request $request): bool
    {
        if (auth()->check()) {
            return auth()->user()->role === 'admin';
        }

        $role = strtolower(trim((string) $request->input('user_role', 'user')));
        return $role === 'admin';
    }

    /**
     * Emite una respuesta de bloqueo en formato Server-Sent Events (SSE).
     */
    private function streamAccessDenied(string $reason = 'You do not have permission to access financial or accounting records.')
    {
        return response()->stream(function () use ($reason) {
            $msg = "⛔ **Access Denied**: {$reason} Please contact an Administrator.";
            echo "data: " . json_encode(['text' => $msg]) . "\n\n";
            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function extractMonthAndYear(string $text): ?array
    {
        $allMonths = array_merge(array_keys($this->monthMap), array_values($this->monthMap));
        $pattern = '/\b(' . implode('|', array_unique($allMonths)) . ')\b/i';

        if (preg_match($pattern, $text, $match)) {
            $rawMonth = strtolower($match[1]);
            $englishMonth = $this->monthMap[$rawMonth] ?? $rawMonth;

            $year = date('Y');
            if (preg_match('/\b(202\d)\b/', $text, $yMatch)) {
                $year = $yMatch[1];
            }

            return ['month' => $englishMonth, 'year' => $year];
        }

        return null;
    }

    /**
     * Plantillas predefinidas en inglés para correos a clientes
     */
    private function getEmailTemplates(string $customerName, string $customBody = ''): array
    {
        return [
            1 => [
                'name'    => 'Artwork / Low Resolution Issue',
                'subject' => 'Action Required: Artwork resolution issue with your DTF order',
                'body'    => "Hello {$customerName},\n\nWe are currently reviewing your gang sheet files, but the uploaded artwork resolution is too low or blurry for high-quality printing. Please reply to this email with a high-resolution file (300 DPI, transparent PNG or vector PDF) or contact our design office so we can proceed with printing.\n\nBest regards,\nDTF Transfers Affordable Team",
            ],
            2 => [
                'name'    => 'Production / Print Delay',
                'subject' => 'Update regarding your DTF order schedule',
                'body'    => "Hello {$customerName},\n\nWe wanted to inform you that your DTF print order is experiencing a slight production delay due to scheduled machine maintenance and a high queue volume. Our printing team is actively working on it, and we will notify you the moment it is completed.\n\nBest regards,\nDTF Transfers Affordable Team",
            ],
            3 => [
                'name'    => 'Sizing & Gang Sheet Clarification',
                'subject' => 'Verification Required: Dimensions or overlap detected',
                'body'    => "Hello {$customerName},\n\nWhile setting up your file on the RIP software, our prepress team noticed that some designs are either overlapping or exceeding the printable canvas area. Please reply to this email or call our shop to verify the exact dimensions before we send it to print.\n\nBest regards,\nDTF Transfers Affordable Team",
            ],
            4 => [
                'name'    => 'Pending Payment Reminder',
                'subject' => 'Payment Reminder: Pending invoice for your DTF order',
                'body'    => "Hello {$customerName},\n\nYour order is fully prepared and queued, but payment is currently pending or awaiting invoice clearance. Please finalize your balance so our operators can immediately release your transfer to the printers.\n\nBest regards,\nDTF Transfers Affordable Team",
            ],
            5 => [
                'name'    => 'Order Ready for Pickup Reminder',
                'subject' => 'Friendly Reminder: Your DTF order is ready for pickup',
                'body'    => "Hello {$customerName},\n\nThis is a friendly reminder that your DTF transfer order has been printed, cured, and is waiting for you at our shop. You can pick it up anytime during our regular business hours.\n\nBest regards,\nDTF Transfers Affordable Team",
            ],
            6 => [
                'name'    => 'Custom / User Specified Message',
                'subject' => 'Important update regarding your DTF order',
                'body'    => "Hello {$customerName},\n\n" . (!empty(trim($customBody)) ? trim($customBody) : "Please contact our office regarding an important update on your print order.") . "\n\nBest regards,\nDTF Transfers Affordable Team",
            ],
        ];
    }

    /**
     * Genera el texto del menú de selección de plantillas
     */
    private function buildTemplateSelectionMenu(string $customerName, string $email): string
    {
        $templates = $this->getEmailTemplates($customerName);

        $menu = "📋 **Select an Email Template for {$customerName}** (`{$email}`):\n\n";
        $menu .= "1️⃣ **Artwork / Low Resolution**: Asks customer for 300 DPI transparent file.\n";
        $menu .= "2️⃣ **Production Delay**: Informs about print queue/maintenance delay.\n";
        $menu .= "3️⃣ **Sizing / Overlap Issue**: Asks for size verification before printing.\n";
        $menu .= "4️⃣ **Pending Payment Reminder**: Unpaid order blocking print release.\n";
        $menu .= "5️⃣ **Pickup Reminder**: Notifies that prints are ready at the store.\n";
        $menu .= "6️⃣ **Custom Message**: Uses your custom provided text.\n\n";
        $menu .= "👉 Reply with the option number (**1** to **6**) or **CANCEL**.";

        return $menu;
    }

    private function resolveTargetEndpoint(Request $request): array
    {
        $origin = $request->input('origin') 
               ?? $request->header('Origin') 
               ?? $request->header('Referer') 
               ?? '';

        if (str_contains($origin, 'dttorders')) {
            return ['dtt' => $this->endpoints['dtt']];
        }

        if (str_contains($origin, 'dev.')) {
            return ['dev' => $this->endpoints['dev']];
        }

        return ['dtt' => $this->endpoints['dtt']];
    }

    private function queryActiveStore(Request $request, array $payload, int $timeout = 15): array
    {
        $target = $this->resolveTargetEndpoint($request);
        $server = reset($target);

        try {
            $res = Http::timeout($timeout)
                ->withToken($server['token'])
                ->post($server['url'], $payload);

            if ($res->successful()) {
                return $res->json();
            }
            Log::error("API Error [{$server['name']}]: " . $res->body());
        } catch (\Throwable $e) {
            Log::error("Connection Error [{$server['name']}]: " . $e->getMessage());
        }

        return ['status' => 'error', 'message' => 'Unable to connect to the store database.'];
    }

    public function handlePublicStream(Request $request, OllamaService $ollama)
    {
        set_time_limit(180);

        $request->validate([
            'message'   => 'nullable|string|max:1000',
            'image'     => 'nullable|string',
            'user_role' => 'nullable|string',
        ]);

        $userMessage = trim($request->input('message') ?? '');
        $rawImage    = $request->input('image');

        $imageBase64 = !empty($rawImage) ? preg_replace('#^data:image/\w+;base64,#i', '', $rawImage) : null;

        if (empty($userMessage) && empty($imageBase64)) {
            return response()->json(['error' => 'Message or image required'], 422);
        }

        $qrDetectedRef = null;

        if (!empty($imageBase64)) {
            $decodedText = $this->decodeQrCode($imageBase64);

            if (!empty($decodedText)) {
                Log::info("QR decodificado con éxito: " . $decodedText);

                if (str_contains($decodedText, 'http') || str_contains($decodedText, 'verify')) {
                    $path = parse_url($decodedText, PHP_URL_PATH) ?? $decodedText;
                    $segments = explode('/', trim($path, '/'));
                    $lastSegment = end($segments);
                    if (!empty($lastSegment)) {
                        $qrDetectedRef = strtoupper(trim($lastSegment));
                    }
                } else {
                    $qrDetectedRef = strtoupper(trim($decodedText));
                }
            }
        }

        if (!empty($imageBase64) && !$qrDetectedRef) {
            $isScanCommand = empty($userMessage) || preg_match('/(?:consultar|ver|check|qr|orden|order|status|imprim|list[oa]|termin|pickup)/i', $userMessage);

            if ($isScanCommand) {
                return response()->stream(function () {
                    $msg = "⚠️ No se pudo leer el código QR de la imagen. Asegúrate de enfocar bien la etiqueta con buena iluminación.";
                    echo "data: " . json_encode(['text' => $msg]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }, 200, [
                    'Content-Type'  => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                    'Connection'    => 'keep-alive',
                ]);
            }
        }

        $qrOrderData = null;
        if ($qrDetectedRef) {
            $checkRes = $this->queryActiveStore($request, [
                'action' => 'search_order',
                'query'  => $qrDetectedRef
            ], 10);

            if (isset($checkRes['status']) && $checkRes['status'] === 'success') {
                $qrOrderData = $checkRes;
            } else {
                return response()->stream(function () use ($qrDetectedRef) {
                    $msg = "🛑 **QR No Válido**: La orden `{$qrDetectedRef}` extraída del código QR no existe en el sistema. No se realizó ninguna acción.";
                    echo "data: " . json_encode(['text' => $msg]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }, 200, [
                    'Content-Type'  => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                    'Connection'    => 'keep-alive',
                ]);
            }
        }

        $lowerMsg = strtolower($userMessage);

        $tz = config('app.timezone') ?: env('APP_TIMEZONE', 'America/Los_Angeles');
        $now = Carbon::now($tz);
        $todayFormatted = $now->format('l, F j, Y');
        $yesterdayFormatted = $now->copy()->subDay()->format('l, F j, Y');

        $filterToday = str_contains($lowerMsg, 'hoy') || str_contains($lowerMsg, 'today');
        $filterYesterday = str_contains($lowerMsg, 'ayer') || str_contains($lowerMsg, 'yesterday');

        $targetQuery = $qrDetectedRef;

        if (!$targetQuery) {
            if (preg_match('/#([A-Za-z0-9_-]+)/', $userMessage, $hashMatches)) {
                $targetQuery = trim($hashMatches[1]);
            } elseif (preg_match('/\b([A-Za-z]{2,5}-\d+(?:-[A-Za-z0-9]+)?)\b/i', $userMessage, $prefixMatches)) {
                $targetQuery = strtoupper(trim($prefixMatches[1]));
            } elseif (preg_match('/\b([A-Z]{8,10})\b/', $userMessage, $psMatches)) {
                $reservedWords = ['CONFIRM', 'SHIPPING', 'PRINTING', 'DASHBOARD', 'CANCELLED', 'TODAY', 'YESTERDAY', 'AVERAGE', 'REVENUE', 'EXPENSES', 'DETAILS', 'STATUS', 'METRICS'];
                if (!in_array($psMatches[1], $reservedWords)) {
                    $targetQuery = $psMatches[1];
                }
            } elseif (preg_match('/(?:order|orden)\s*#?\s*(\d+)/i', $userMessage, $orderNumMatch)) {
                $targetQuery = trim($orderNumMatch[1]);
            } elseif (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $userMessage, $emailMatches)) {
                $targetQuery = $emailMatches[0];
            } elseif (preg_match('/(?:customer|client|order of|order for|orden de|cliente)\s+([a-zA-Z0-9\s]{2,30})/i', $userMessage, $nameMatches)) {
                $cleanName = preg_replace('/\b(hoy|today|ayer|yesterday|now|ahora|recent|reciente)\b/i', '', $nameMatches[1]);
                $targetQuery = trim($cleanName);
            }
        }

        $toolOutput = "";
        $instructionExtra = "";
        $isActionCommand = false;

        $sessionKey = 'pending_action_' . ($request->input('session_id') ?? $request->ip());

        // CASO 0: Cancelación Rápida
        if (preg_match('/^(cancel|abort|stop|nevermind|never mind|cancelar)[\.!]?$/i', $userMessage) && Cache::has($sessionKey)) {
            Cache::forget($sessionKey);
            $isActionCommand = true;
            $toolOutput = json_encode(['status' => 'cancelled']);
            $instructionExtra = "State clearly: 'Action cancelled. No changes were made.'";
        }

        // CASO 0.1: Confirmación de Pickup
        elseif (Cache::has($sessionKey) && (Cache::get($sessionKey)['type'] ?? '') === 'pickup_confirm') {
            $pending = Cache::pull($sessionKey);
            $isActionCommand = true;
            $targetRef = $pending['query'];
            
            $wantsEmail = (bool) preg_match('/^(yes|si|sí|yep|yeah|sure|confirm|send|con correo|with email)[\.!]?$/i', $userMessage);
            $noEmail    = (bool) preg_match('/^(no|nop|sin correo|without email)[\.!]?$/i', $userMessage);

            if ($wantsEmail || $noEmail) {
                $res = $this->queryActiveStore($request, [
                    'action'     => 'update_status',
                    'query'      => $targetRef,
                    'status'     => 'Ready for Pickup',
                    'send_email' => $wantsEmail,
                    'confirmed'  => true,
                ], 15);

                $toolOutput = json_encode($res);
                $emailStatusText = $wantsEmail ? "and the confirmation email was sent to the customer." : "WITHOUT sending notification email.";
                $instructionExtra = "Confirm clearly that order {$targetRef} has been updated to 'Ready for Pickup' {$emailStatusText}";
            } else {
                $instructionExtra = "State: 'Action not understood. Status was not updated. Please request the status change again.'";
            }
        }

        // CASO 0.2: Confirmación Estándar para otros estados
        elseif (Cache::has($sessionKey) && (Cache::get($sessionKey)['type'] ?? '') === 'update_status' && preg_match('/^(ok|okay|confirm|yes|si|yep|sure|proceed|do it)[\.!]?$/i', $userMessage)) {
            $pending = Cache::pull($sessionKey);
            $isActionCommand = true;
            $targetRef = $pending['query'];
            $targetStatus = $pending['status'];

            $res = $this->queryActiveStore($request, [
                'action'    => 'update_status',
                'query'     => $targetRef,
                'status'    => $targetStatus,
                'confirmed' => true,
            ], 15);

            $toolOutput = json_encode($res);

            if (isset($res['status']) && $res['status'] === 'success') {
                $instructionExtra = "Confirm clearly that order {$targetRef} has been successfully updated to '{$targetStatus}' in the database.";
            } else {
                $err = $res['message'] ?? 'Could not update status.';
                $instructionExtra = "State firmly that the status could NOT be updated. Explain reason: {$err}";
            }
        }

        // CASO 0.25: Confirmación de Creación de Gasto (PROTEGIDO)
        elseif (Cache::has($sessionKey) && (Cache::get($sessionKey)['type'] ?? '') === 'add_expense_confirm') {
            if (!$this->canAccessAccounting($request)) {
                Cache::forget($sessionKey);
                return $this->streamAccessDenied('Only Administrators can record business expenses.');
            }

            $pending = Cache::pull($sessionKey);
            $isConfirmed = (bool) preg_match('/^(yes|si|sí|yep|sure|confirm|confirmar|ok|okay|proceed|proceder|do it)[\.!]?$/i', $userMessage);

            if ($isConfirmed) {
                $payload = array_merge($pending['expense_data'], ['confirmed' => true]);
                $res = $this->queryActiveStore($request, array_merge(['action' => 'add_expense'], $payload), 15);

                if (isset($res['status']) && $res['status'] === 'success') {
                    $d = $res['data'] ?? [];
                    $msg = "✅ **Expense Successfully Recorded!**\n\n" .
                           "- **Amount:** $" . number_format($d['amount'] ?? 0, 2) . "\n" .
                           "- **Company / Supplier:** " . ($d['company_name'] ?? 'N/A') . "\n" .
                           "- **Category:** " . ($d['category'] ?? 'N/A') . "\n" .
                           "- **Subcategory:** " . ($d['subcategory'] ?? 'N/A') . "\n" .
                           "- **Date:** " . ($d['date'] ?? now()->toFormattedDateString());
                } else {
                    $err = $res['message'] ?? 'Could not create expense.';
                    $msg = "❌ **Failed to record expense**: {$err}";
                }
            } else {
                $msg = "🛑 **Expense Cancelled**. No changes were made.";
            }

            return response()->stream(function () use ($msg) {
                echo "data: " . json_encode(['text' => $msg]) . "\n\n";
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }, 200, [
                'Content-Type'      => 'text/event-stream',
                'Cache-Control'     => 'no-cache',
                'Connection'        => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        // CASO 0.3: Confirmación Final de Envío de Correo
        elseif (Cache::has($sessionKey) && (Cache::get($sessionKey)['type'] ?? '') === 'send_email_confirm') {
            $pending = Cache::pull($sessionKey);
            $isConfirmed = (bool) preg_match('/^(yes|si|sí|yep|sure|confirm|confirmar|enviar|send|proceed|proceder|ok|okay)[\.!]?$/i', $userMessage);

            if ($isConfirmed) {
                $res = $this->queryActiveStore($request, [
                    'action'    => 'send_custom_email',
                    'query'     => $pending['target_ref'] ?? '',
                    'email'     => $pending['email'],
                    'subject'   => $pending['subject'],
                    'body'      => $pending['body'],
                    'confirmed' => true,
                ], 20);

                if (isset($res['status']) && $res['status'] === 'success') {
                    $msg = "✅ **Email Sent Successfully!**\n\nThe message has been delivered to **{$pending['email']}**.";
                } else {
                    $err = $res['message'] ?? 'Failed to send email.';
                    $msg = "❌ **Error Sending Email**: {$err}";
                }
            } else {
                $msg = "🛑 **Email Cancelled**. No message was sent.";
            }

            return response()->stream(function () use ($msg) {
                echo "data: " . json_encode(['text' => $msg]) . "\n\n";
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }, 200, [
                'Content-Type'      => 'text/event-stream',
                'Cache-Control'     => 'no-cache',
                'Connection'        => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        // CASO 0.35: Selección de Plantilla de Correo
        elseif (Cache::has($sessionKey) && (Cache::get($sessionKey)['type'] ?? '') === 'choose_email_template') {
            $pending = Cache::pull($sessionKey);
            $trimmedMsg = trim($userMessage);
            $templates = $this->getEmailTemplates($pending['name'], $pending['raw_body'] ?? '');

            $chosenTemplate = null;
            if (is_numeric($trimmedMsg) && isset($templates[(int)$trimmedMsg])) {
                $chosenTemplate = $templates[(int)$trimmedMsg];
            } else {
                foreach ($templates as $idx => $tmpl) {
                    if (str_contains(strtolower($tmpl['name']), strtolower($trimmedMsg))) {
                        $chosenTemplate = $tmpl;
                        break;
                    }
                }
            }

            if ($chosenTemplate) {
                Cache::put($sessionKey, [
                    'type'       => 'send_email_confirm',
                    'target_ref' => $pending['target_ref'],
                    'email'      => $pending['email'],
                    'subject'    => $chosenTemplate['subject'],
                    'body'       => $chosenTemplate['body'],
                ], now()->addMinutes(5));

                $previewMsg = "✉️ **Email Preview ({$chosenTemplate['name']})**\n\n" .
                    "- **To**: {$pending['name']} (`{$pending['email']}`)\n" .
                    "- **Subject**: {$chosenTemplate['subject']}\n" .
                    "- **Message Preview**:\n> " . str_replace("\n", "\n> ", $chosenTemplate['body']) . "\n\n" .
                    "⚠️ **Do you want to send this email?** Reply **CONFIRM** / **YES** to dispatch or **CANCEL**.";

                return response()->stream(function () use ($previewMsg) {
                    echo "data: " . json_encode(['text' => $previewMsg]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }, 200, [
                    'Content-Type'      => 'text/event-stream',
                    'Cache-Control'     => 'no-cache',
                    'Connection'        => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ]);
            } else {
                $errorMsg = "⚠️ Option not recognized. Email dispatch cancelled. Please restart by saying: *Send email to [name]*";
                return response()->stream(function () use ($errorMsg) {
                    echo "data: " . json_encode(['text' => $errorMsg]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }, 200, [
                    'Content-Type'      => 'text/event-stream',
                    'Cache-Control'     => 'no-cache',
                    'Connection'        => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ]);
            }
        }

        // CASO 0.4: Resolución de Destinatario duplicado
        elseif (Cache::has($sessionKey) && Cache::get($sessionKey)['type'] === 'choose_email_recipient') {
            $pending = Cache::pull($sessionKey);
            $candidates = $pending['candidates'] ?? [];
            $rawBody = $pending['raw_body'] ?? '';
            $selectedCandidate = null;

            $trimmedMsg = trim($userMessage);

            if (is_numeric($trimmedMsg) && isset($candidates[(int)$trimmedMsg - 1])) {
                $selectedCandidate = $candidates[(int)$trimmedMsg - 1];
            } else {
                foreach ($candidates as $cand) {
                    if (str_contains(strtolower($cand['email']), strtolower($trimmedMsg)) || str_contains(strtolower($cand['name']), strtolower($trimmedMsg))) {
                        $selectedCandidate = $cand;
                        break;
                    }
                }
            }

            if ($selectedCandidate) {
                $customerName = $selectedCandidate['name'];
                $customerEmail = $selectedCandidate['email'];
                $targetRef = $selectedCandidate['ref'] ?? 'DIRECT';

                Cache::put($sessionKey, [
                    'type'       => 'choose_email_template',
                    'target_ref' => $targetRef,
                    'name'       => $customerName,
                    'email'      => $customerEmail,
                    'raw_body'   => $rawBody,
                ], now()->addMinutes(5));

                $menuText = $this->buildTemplateSelectionMenu($customerName, $customerEmail);

                return response()->stream(function () use ($menuText) {
                    echo "data: " . json_encode(['text' => $menuText]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }, 200, [
                    'Content-Type'      => 'text/event-stream',
                    'Cache-Control'     => 'no-cache',
                    'Connection'        => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ]);
            } else {
                $errorMsg = "⚠️ Option not recognized. Email dispatch cancelled. Please start again by saying: *Send email to [name]*";
                return response()->stream(function () use ($errorMsg) {
                    echo "data: " . json_encode(['text' => $errorMsg]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }, 200, [
                    'Content-Type'      => 'text/event-stream',
                    'Cache-Control'     => 'no-cache',
                    'Connection'        => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ]);
            }
        }

        // CASO 1: Confirmación de Compra de Etiqueta
        elseif (preg_match('/(?:confirm(?:ar)?\s*(?:label|etiqueta|ship(?:ping)?))\s*([A-Za-z0-9#-]+)\s*(?:with|con|via)?\s*([a-zA-Z0-9\s_]+)/i', $userMessage, $labelConfirmMatch)) {
            $isActionCommand = true;
            $targetRef = ltrim(trim($labelConfirmMatch[1]), '#');
            $selectedService = trim($labelConfirmMatch[2]);

            $res = $this->queryActiveStore($request, [
                'action'  => 'buy_shipping_label',
                'query'   => $targetRef,
                'service' => $selectedService
            ], 25);

            $toolOutput = json_encode($res);
            $data = $res['data'] ?? $res;

            if (!empty($data['label_url'])) {
                $instructionExtra = "Confirm that the shipping label was purchased successfully. Display:\n- **Reference**: {$data['reference']}\n- **Carrier**: {$data['carrier']}\n- Direct button: [Download Shipping Label Here]({$data['label_url']})\nDo NOT display tracking numbers. NEVER modify URLs.";
            } else {
                $instructionExtra = "Explain the error returned clearly: " . ($data['message'] ?? 'Unknown error');
            }
        }

        // CASO 2: Confirmación de Cambio de Estado directo
        elseif (preg_match('/(?:confirm(?:ar)?\s*(?:status|estado))\s*([A-Za-z0-9#-]+)\s*(?:to|a)\s*([a-zA-Z\s]+)/i', $userMessage, $statusConfirmMatch)) {
            $isActionCommand = true;
            $targetRef = ltrim(trim($statusConfirmMatch[1]), '#');
            $targetStatus = trim($statusConfirmMatch[2]);

            $res = $this->queryActiveStore($request, [
                'action'    => 'update_status',
                'query'     => $targetRef,
                'status'    => $targetStatus,
                'confirmed' => true,
            ], 15);

            $toolOutput = json_encode($res);

            if (isset($res['status']) && $res['status'] === 'success') {
                $instructionExtra = "Confirm clearly that order {$targetRef} has been updated to '{$targetStatus}'.";
            } else {
                $err = $res['message'] ?? 'Unknown error';
                $instructionExtra = "Explain firmly that the status update failed: {$err}";
            }
        }

        // DETECCIÓN: Enviar correo personalizado al cliente -> Muestra menú de plantillas
        elseif (preg_match('/(?:send|enviar|mandar|notificar|escribir)\s+(?:an?\s+|un\s+|the\s+|el\s+)?(?:email|correo|mail|message|mensaje)\s+(?:to|a|al cliente)\s*(.*)/i', $userMessage, $emailIntentMatch)) {
            $content = trim($emailIntentMatch[1] ?? '');

            $splitParts = preg_split('/\s+(?:saying\s+that|saying|diciendo\s+que|diciendo|with\s+message:|con\s+el\s+mensaje:|message:|mensaje:|that|que)\s+/i', $content, 2);
            $lookupQuery = trim($splitParts[0] ?? '');
            $rawBody = isset($splitParts[1]) && !empty(trim($splitParts[1])) ? trim($splitParts[1]) : "";

            if (empty($lookupQuery)) {
                $msg = "⚠️ Please specify a customer name, email, or order reference (e.g., *Send email to John Doe*).";
                return response()->stream(function () use ($msg) {
                    echo "data: " . json_encode(['text' => $msg]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }, 200, [
                    'Content-Type'      => 'text/event-stream',
                    'Cache-Control'     => 'no-cache',
                    'Connection'        => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ]);
            }

            $searchRes = $this->queryActiveStore($request, [
                'action' => 'find_recipients',
                'query'  => $lookupQuery
            ], 10);

            if (!isset($searchRes['status']) || $searchRes['status'] === 'not_found') {
                $msg = "❌ No customer or order found matching **{$lookupQuery}**. Please verify the spelling or provide an exact email.";
                return response()->stream(function () use ($msg) {
                    echo "data: " . json_encode(['text' => $msg]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }, 200, [
                    'Content-Type'      => 'text/event-stream',
                    'Cache-Control'     => 'no-cache',
                    'Connection'        => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ]);
            }

            if (isset($searchRes['type']) && $searchRes['type'] === 'multiple') {
                Cache::put($sessionKey, [
                    'type'       => 'choose_email_recipient',
                    'candidates' => $searchRes['data'],
                    'raw_body'   => $rawBody,
                ], now()->addMinutes(5));

                $listText = "Here is the list of customers matching the name **\"{$lookupQuery}\"**:\n\n";
                foreach ($searchRes['data'] as $idx => $cand) {
                    $num = $idx + 1;
                    $listText .= "{$num}. **{$cand['name']}** — `{$cand['email']}`\n";
                }
                $listText .= "\nWhich customer would you like to email? Reply with the number (e.g. **1** or **2**) or the email.";

                return response()->stream(function () use ($listText) {
                    echo "data: " . json_encode(['text' => $listText]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }, 200, [
                    'Content-Type'      => 'text/event-stream',
                    'Cache-Control'     => 'no-cache',
                    'Connection'        => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ]);
            }

            $recipientData = $searchRes['data'] ?? [];
            $customerName  = $recipientData['name'] ?? $lookupQuery;
            $customerEmail = $recipientData['email'] ?? null;
            $targetRef     = $recipientData['ref'] ?? 'DIRECT';

            if (empty($customerEmail)) {
                $msg = "⚠️ Customer **{$customerName}** does not have an email address on file.";
                return response()->stream(function () use ($msg) {
                    echo "data: " . json_encode(['text' => $msg]) . "\n\n";
                    echo "data: [DONE]\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }, 200, [
                    'Content-Type'      => 'text/event-stream',
                    'Cache-Control'     => 'no-cache',
                    'Connection'        => 'keep-alive',
                    'X-Accel-Buffering' => 'no',
                ]);
            }

            Cache::put($sessionKey, [
                'type'       => 'choose_email_template',
                'target_ref' => $targetRef,
                'name'       => $customerName,
                'email'      => $customerEmail,
                'raw_body'   => $rawBody,
            ], now()->addMinutes(5));

            $menuText = $this->buildTemplateSelectionMenu($customerName, $customerEmail);

            return response()->stream(function () use ($menuText) {
                echo "data: " . json_encode(['text' => $menuText]) . "\n\n";
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }, 200, [
                'Content-Type'      => 'text/event-stream',
                'Cache-Control'     => 'no-cache',
                'Connection'        => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        // CASO 3: Cotizar Tarifas de Envío
        elseif (str_contains($lowerMsg, 'label') || str_contains($lowerMsg, 'etiqueta') || str_contains($lowerMsg, 'shippo') || (str_contains($lowerMsg, 'ship') && $targetQuery)) {
            $isActionCommand = true;
            $resData = $this->queryActiveStore($request, [
                'action' => 'get_shipping_rates',
                'query'  => $targetQuery ?? $userMessage
            ], 20);

            $toolOutput = json_encode($resData);

            $existingNote = "";
            if (!empty($resData['already_shipped']) && !empty($resData['existing_label_url'])) {
                $existingNote = "Note: This order was already shipped. Download current label: [Download Shipping Label Here]({$resData['existing_label_url']}).\n\n";
            }

            $instructionExtra = "{$existingNote}Present available shipping rates in a clean Markdown table (Carrier | Service | Price | Est. Delivery).\nInstruct: 'Reply: **CONFIRM LABEL {$targetQuery} WITH [Carrier Service]**'.";
        }

        // CASO DIRECTO: Pickup en 1 solo clic (con o sin correo)
        elseif ($targetQuery && 
            (str_contains($lowerMsg, 'pickup') || str_contains($lowerMsg, 'recog') || str_contains($lowerMsg, 'termin') || str_contains($lowerMsg, 'list')) && 
            (str_contains($lowerMsg, 'con correo') || str_contains($lowerMsg, 'with email') || str_contains($lowerMsg, 'sin correo') || str_contains($lowerMsg, 'without email'))
        ) {
            $isActionCommand = true;
            $wantsEmail = str_contains($lowerMsg, 'con correo') || str_contains($lowerMsg, 'with email');

            $res = $this->queryActiveStore($request, [
                'action'     => 'update_status',
                'query'      => $targetQuery,
                'status'     => 'Ready for Pickup',
                'send_email' => $wantsEmail,
                'confirmed'  => true,
            ], 15);

            $toolOutput = json_encode($res);

            // Si la API retornó el bloqueo por ser orden de envío:
            if (isset($res['status']) && $res['status'] === 'error') {
                $instructionExtra = "State firmly: '🛑 Order {$targetQuery} is a delivery order (UPS/USPS). It cannot be marked as Ready for Pickup, and no pickup email was sent.'";
            } else {
                $emailStatusText = $wantsEmail ? "and the confirmation email was sent to the customer." : "WITHOUT sending notification email.";
                $instructionExtra = "Confirm clearly that order {$targetQuery} has been updated to 'Ready for Pickup' {$emailStatusText}";
            }
        }

        // CASO 4: Cambio de Estado o Comandos Directos
        elseif ($targetQuery && (
            preg_match('/(?:move|change|set|pass|put|mark|print|pickup|hold|cancel|pasal[oa]|mover|cambiar|imprimir)/i', $lowerMsg) ||
            preg_match('/(?:termin[oó]|list[oa]|ready|finish|finished|ya est[aá]|complet[aoó])/i', $lowerMsg)
        )) {
            $isActionCommand = true;

            $isFinishedPrinting = preg_match('/(?:termin[oó]|list[oa]|ready|finish|ya est[aá]|complet)/i', $lowerMsg);

            if ($isFinishedPrinting) {
                $targetStatus = 'Ready for Pickup';
            } elseif (str_contains($lowerMsg, 'print') || str_contains($lowerMsg, 'imprim')) {
                $targetStatus = 'Printing';
            } elseif (str_contains($lowerMsg, 'pickup') || str_contains($lowerMsg, 'recog')) {
                $targetStatus = 'Ready for Pickup';
            } elseif (str_contains($lowerMsg, 'hold') || str_contains($lowerMsg, 'reten')) {
                $targetStatus = 'Hold';
            } elseif (str_contains($lowerMsg, 'cancel')) {
                $targetStatus = 'Cancelled';
            } elseif (str_contains($lowerMsg, 'ship') || str_contains($lowerMsg, 'envi')) {
                $targetStatus = 'Shipping';
            } else {
                $targetStatus = 'Dashboard';
            }

            $sessionKey = 'pending_action_' . ($request->input('session_id') ?? $request->ip());

            if ($targetStatus === 'Ready for Pickup') {
                Cache::put($sessionKey, [
                    'type'   => 'pickup_confirm',
                    'query'  => $targetQuery,
                    'status' => 'Ready for Pickup'
                ], now()->addMinutes(5));

                $instructionExtra = "The user indicated that order **{$targetQuery}** is finished printing and should move to **Ready for Pickup**.\n" .
                    "State: 'Order **{$targetQuery}** will be updated to **Ready for Pickup**. Do you want to send the notification email to the customer? Reply **YES** (send email) or **NO** (without email).'";
            } else {
                Cache::put($sessionKey, [
                    'type'   => 'update_status',
                    'query'  => $targetQuery,
                    'status' => $targetStatus
                ], now()->addMinutes(5));

                $instructionExtra = "The user wants to change order **{$targetQuery}** to '{$targetStatus}'. Ask for confirmation: '⚠️ Are you sure you want to change order **{$targetQuery}** to **{$targetStatus}**? Reply **OK** or **CONFIRM** to proceed.'";
            }

            $toolOutput = json_encode($qrOrderData ?? $this->queryActiveStore($request, ['action' => 'search_order', 'query' => $targetQuery]));
        }

        // CASO 4.1: Escaneo Pasivo de QR
        elseif ($qrDetectedRef && (empty($userMessage) || preg_match('/^(ver|check|info|status|order|consultar|detalles)?$/i', $userMessage))) {
            $isActionCommand = true;
            $toolOutput = json_encode($qrOrderData);
            $instructionExtra = "Order identified via QR Code.\n" .
                "1. Present order details in a clean 2-column Markdown Table (| Order Field | Details |) showing: Reference, Customer, Status, Payment Status, Total Paid, Inches, and Carrier.\n" .
                "2. Suggest next actions: 'You can say: *This order finished printing*, *Move to Hold*, or *Get shipping label*.'";
        }

        // CASO 5: Cola de Impresión
        elseif (
            str_contains($lowerMsg, 'printing') || 
            str_contains($lowerMsg, 'print queue') || 
            str_contains($lowerMsg, 'imprimiendo') || 
            (str_contains($lowerMsg, 'queue') && !str_contains($lowerMsg, 'confirm'))
        ) {
            $isActionCommand = true;
            $res = $this->queryActiveStore($request, [
                'action' => 'orders_by_status',
                'status' => 'Printing'
            ]);

            $rawOrders = $res['data'] ?? [];
            $flatOrders = [];

            if (is_array($rawOrders)) {
                foreach ($rawOrders as $o) {
                    if (isset($o['reference'])) {
                        $flatOrders[] = [
                            'ref'      => $o['reference'],
                            'customer' => $o['customer'] ?? 'Unknown',
                            'printer'  => $o['printer'] ?? 'Unassigned'
                        ];
                    }
                }
            }

            $toolOutput = json_encode([
                'total_orders' => count($flatOrders),
                'orders'       => $flatOrders
            ]);

            if (empty($flatOrders)) {
                $instructionExtra = "State clearly: 'There are currently no orders in the printing queue.' Do NOT print bullet points or sample data.";
            } else {
                $instructionExtra = "Present the active printing queue in a concise list in English.\n" .
                    "- State directly: 'Currently, there are **" . count($flatOrders) . "** orders in the printing queue:'\n" .
                    "- List every order strictly as: • **[ref]** — [customer] ([printer])\n" .
                    "- Do NOT add dates, payment status, inches, or store names.";
            }
        }

        // =========================================================================
        // COMANDO: AGREGAR GASTO (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (
            preg_match('/(?:add|agregar|registrar|gasto|expense)\s+(?:a\s+)?(?:\$)?(\d+(?:\.\d{1,2})?)\s*(?:dollars?|dolares|usd|\$)?\s*(?:in|en|for|para|expenses?|gastos?|de)?\s*(.*)/i', $userMessage, $expMatch) ||
            preg_match('/(?:gaste|gastamos|spent)\s+(?:\$)?(\d+(?:\.\d{1,2})?)\s*(?:dollars?|dolares|usd|\$)?\s*(?:en|in|on|for|para)?\s*(.*)/i', $userMessage, $expMatch)
        ) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Only Administrators can record business expenses.');
            }

            $isActionCommand = true;
            $amount = (float) $expMatch[1];
            $concept = trim($expMatch[2] ?? '');

            $cleanConcept = trim(preg_replace('/\b(dollars?|dolares|usd|expenses?|gastos?)\b/i', '', $concept));

            [$category, $subcategory, $companyName] = $this->categorizeExpense($cleanConcept);

            $expensePayload = [
                'company_name' => $companyName,
                'category'     => $category,
                'subcategory'  => $subcategory,
                'amount'       => $amount,
                'expense_date' => now()->toDateString(),
            ];

            Cache::put($sessionKey, [
                'type'         => 'add_expense_confirm',
                'expense_data' => $expensePayload,
            ], now()->addMinutes(5));

            $previewMsg = "📝 **Confirm Expense Registration**\n\n" .
                "- **Amount:** $" . number_format($amount, 2) . "\n" .
                "- **Supplier / Concept:** {$companyName} (*{$cleanConcept}*)\n" .
                "- **Category:** {$category}\n" .
                "- **Subcategory:** {$subcategory}\n\n" .
                "⚠️ **Save this expense to the database?** Reply **YES / CONFIRM** to proceed or **CANCEL**.";

            return response()->stream(function () use ($previewMsg) {
                echo "data: " . json_encode(['text' => $previewMsg]) . "\n\n";
                echo "data: [DONE]\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }, 200, [
                'Content-Type'      => 'text/event-stream',
                'Cache-Control'     => 'no-cache',
                'Connection'        => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        // =========================================================================
        // REPORTE DE GASTOS / DESGLOSE POR CATEGORÍA (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (preg_match('/(?:expense|gasto|spending|cost|egreso)/i', $lowerMsg) && !str_contains($lowerMsg, 'profit')) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Expense breakdown reports are restricted to Administrators.');
            }

            $isActionCommand = true;
            $isLastMonth = str_contains($lowerMsg, 'last month') || str_contains($lowerMsg, 'mes pasado');
            
            $res = $this->queryActiveStore($request, [
                'action' => 'expense_breakdown',
                'date'   => $isLastMonth ? 'last_month' : 'this_month'
            ]);
            
            $toolOutput = json_encode($res['data'] ?? $res);
            $instructionExtra = "Present the Expense Breakdown report in English:\n" .
                "1. Display the Period and Total Expenses prominently.\n" .
                "2. Render a 3-column Markdown Table (| Category | Total Spent | Records |).\n" .
                "3. Highlight the highest cost category in a brief follow-up sentence.";
        }

        // =========================================================================
        // REPORTE DE GANANCIAS / PROFIT REPORT (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (preg_match('/(?:profit|ganancia|net|margen|financial report|rendimiento)/i', $lowerMsg) && !str_contains($lowerMsg, 'month') && !str_contains($lowerMsg, 'mes')) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Profit and financial performance reports are restricted to Administrators.');
            }

            $isActionCommand = true;
            $isLastMonth = str_contains($lowerMsg, 'last month') || str_contains($lowerMsg, 'mes pasado');

            $res = $this->queryActiveStore($request, [
                'action' => 'profit_report',
                'date'   => $isLastMonth ? 'last_month' : 'this_month'
            ]);

            $toolOutput = json_encode($res['data'] ?? $res);
            $instructionExtra = "Present an Executive Profit & Performance Report strictly from [DATABASE RECORD] in English:\n" .
                "1. Render a clean 2-column Markdown Table (| Financial Indicator | Amount |) showing: Revenue, Total Inches, Operating Expenses, Net Profit, and Profit Margin %.\n" .
                "2. Provide an analytical sentence evaluating business health for the period.";
        }

        // =========================================================================
        // A. REPORTE DE VENTAS / RANGO PERSONALIZADO (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (
            preg_match('/(?:from|de|del|between|entre|of|desde)?\s*([0-9]{1,2}(?:st|nd|rd|th)?(?:\s+de)?\s+[a-zA-Z]+|[a-zA-Z]+\s+[0-9]{1,2}(?:st|nd|rd|th)?|[0-9]{4}-[0-9]{2}-[0-9]{2})\s+(?:to|al?|hasta|and|y|-)\s+([0-9]{1,2}(?:st|nd|rd|th)?(?:\s+de)?\s+[a-zA-Z]+|[a-zA-Z]+\s+[0-9]{1,2}(?:st|nd|rd|th)?|[0-9]{4}-[0-9]{2}-[0-9]{2})/i', $lowerMsg, $customRangeMatch)
        ) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Custom date range sales reports are restricted to Administrators.');
            }

            $isActionCommand = true;

            $rawStart = str_replace(array_keys($this->monthMap), array_values($this->monthMap), strtolower(trim($customRangeMatch[1])));
            $rawEnd   = str_replace(array_keys($this->monthMap), array_values($this->monthMap), strtolower(trim($customRangeMatch[2])));

            $cleanStart = preg_replace('/(\bde\b|st|nd|rd|th)/i', '', $rawStart);
            $cleanEnd   = preg_replace('/(\bde\b|st|nd|rd|th)/i', '', $rawEnd);

            $currentYear = date('Y');
            if (preg_match('/\b(202\d)\b/', $lowerMsg, $yearMatch)) {
                $currentYear = $yearMatch[1];
            }

            try {
                $startDate = Carbon::parse(trim($cleanStart) . " {$currentYear}")->startOfDay();
                $endDate   = Carbon::parse(trim($cleanEnd) . " {$currentYear}")->endOfDay();
            } catch (\Throwable $e) {
                $startDate = null;
                $endDate = null;
            }

            if ($startDate && $endDate) {
                $res = $this->queryActiveStore($request, [
                    'action'    => 'business_report',
                    'period'    => 'custom',
                    'date_from' => $startDate->format('Y-m-d'),
                    'date_to'   => $endDate->format('Y-m-d'),
                ]);

                $toolOutput = json_encode($res['data'] ?? $res);
                $instructionExtra = "Present the Custom Date Range Business Report strictly from [DATABASE RECORD] in English:\n" .
                    "1. Prominently display the Date Range (" . $startDate->format('M d, Y') . " - " . $endDate->format('M d, Y') . ").\n" .
                    "2. Render a 2-column Markdown Table (| Financial Indicator | Amount |) showing: Total Orders, Revenue, Inches Printed, Operating Expenses, Taxes, and Net Profit.\n" .
                    "3. Conclude with an analytical executive summary sentence on business performance during this period.";
            } else {
                $instructionExtra = "State: 'Could not parse the requested date range. Please provide valid dates like: sales from September 1 to September 30.'";
            }
        }

        // =========================================================================
        // B. COMPARACIÓN DIRECTA ENTRE DOS MESES (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (
            preg_match('/(?:compara|comparar|compare|vs|versus|entre)\s+(?:el\s+mes\s+de\s+|mes\s+de\s+|el\s+mes\s+|mes\s+|the\s+month\s+of\s+|month\s+of\s+|the\s+month\s+|month\s+|el\s+)?([a-zA-Z]+)\s+(?:y|and|vs|versus|o|or|contra)\s+(?:el\s+mes\s+de\s+|mes\s+de\s+|el\s+mes\s+|mes\s+|the\s+month\s+of\s+|month\s+of\s+|the\s+month\s+|month\s+|el\s+)?([a-zA-Z]+)/i', $lowerMsg, $compMatches) ||
            preg_match('/(?:cu[aá]l\s+mes\s+vendi\s+m[aá]s|qu[eé]\s+mes\s+fue\s+mejor|which\s+month\s+(?:sold\s+more|was\s+better|had\s+higher\s+sales|was\s+higher))\s*[,:]?\s*(?:el\s+mes\s+de\s+|mes\s+de\s+|the\s+month\s+of\s+|month\s+of\s+)?([a-zA-Z]+)\s*(?:o|or|vs|y|and)\s*(?:el\s+mes\s+de\s+|mes\s+de\s+|the\s+month\s+of\s+|month\s+of\s+)?([a-zA-Z]+)/i', $lowerMsg, $compMatches)
        ) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Monthly comparison reports are restricted to Administrators.');
            }

            $isActionCommand = true;
            $monthA = trim($compMatches[1]);
            $monthB = trim($compMatches[2]);

            $yearA = date('Y');
            $yearB = $yearA;
            if (preg_match('/(\d{4})/', $lowerMsg, $yearMatch)) {
                $yearA = (int) $yearMatch[1];
                $yearB = $yearA;
            }

            $res = $this->queryActiveStore($request, [
                'action'  => 'compare_specific_months',
                'month_a' => $monthA,
                'month_b' => $monthB,
                'year_a'  => $yearA,
                'year_b'  => $yearB,
            ]);

            $toolOutput = json_encode($res['data'] ?? $res);
            $instructionExtra = "Present a direct Head-to-Head Comparison between the two requested months strictly from [DATABASE RECORD] in English:\n" .
                "1. Declare the clear winner in sentence 1: Which month had the highest sales revenue and by what dollar difference / percentage.\n" .
                "2. Render a 4-column Markdown Table (| Financial Metric | [Month A Name] | [Month B Name] | Difference |) comparing: Total Revenue, Operating Expenses, Net Profit, Margin %, Total Orders, and Inches Printed.\n" .
                "3. Conclude with a 1-sentence executive verdict evaluating profitability (net profit margin).";
        }

        // =========================================================================
        // C. REPORTE DE UN MES COMPLETO / ESPECÍFICO (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (
            ($monthData = $this->extractMonthAndYear($lowerMsg)) &&
            (
                str_contains($lowerMsg, 'sale') ||
                str_contains($lowerMsg, 'venta') ||
                str_contains($lowerMsg, 'revenue') ||
                str_contains($lowerMsg, 'ingreso') ||
                str_contains($lowerMsg, 'total') ||
                str_contains($lowerMsg, 'how much') ||
                str_contains($lowerMsg, 'cuanto') ||
                str_contains($lowerMsg, 'month of') ||
                str_contains($lowerMsg, 'mes de')
            )
        ) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Monthly sales reports are restricted to Administrators.');
            }

            $isActionCommand = true;
            $monthName = $monthData['month'];
            $year = $monthData['year'];

            $startDate = Carbon::parse("first day of {$monthName} {$year}")->startOfDay();
            $endDate   = Carbon::parse("last day of {$monthName} {$year}")->endOfDay();

            $res = $this->queryActiveStore($request, [
                'action'    => 'business_report',
                'period'    => 'custom',
                'date_from' => $startDate->format('Y-m-d'),
                'date_to'   => $endDate->format('Y-m-d'),
            ]);

            $toolOutput = json_encode($res['data'] ?? $res);
            $monthDisplay = ucfirst($monthName) . " {$year}";

            $instructionExtra = "Present the Monthly Business Report for {$monthDisplay} strictly from [DATABASE RECORD] in English:\n" .
                "1. Prominently state the total revenue and order count for {$monthDisplay}.\n" .
                "2. Render a 2-column Markdown Table (| Indicator | Value |) showing: Total Orders, Revenue, Inches Printed, Operating Expenses, Taxes, and Net Profit.\n" .
                "3. Conclude with an executive summary sentence assessing the month's overall financial performance.";
        }

        // =========================================================================
        // 2. COMPARATIVA HISTÓRICA GENERAL / MEJOR MES (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (
            preg_match('/(?:which|what|cu[aá]l|qu[eé])\s+(?:is\s+the\s+|was\s+the\s+)?month/i', $lowerMsg) ||
            preg_match('/(?:highest|best|top|most|record)\s+(?:revenue|sales|profit|earning|income)\s+month/i', $lowerMsg) ||
            preg_match('/month\s+(?:with|had)\s+(?:the\s+)?(?:highest|best|most|top|max)/i', $lowerMsg) ||
            preg_match('/(?:mejor\s+mes|best\s+month|top\s+month|mes\s+que\s+m[aá]s|comparar?\s+mes(?:es)?|compare\s+months|historial\s+mensual|meses\s+anteriores)/i', $lowerMsg)
        ) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Historical financial comparisons are restricted to Administrators.');
            }

            $isActionCommand = true;
            $monthsToQuery = 6;
            if (preg_match('/(\d+)\s*meses?/i', $lowerMsg, $mMatches)) {
                $monthsToQuery = min(max((int)$mMatches[1], 2), 12);
            }

            $res = $this->queryActiveStore($request, [
                'action' => 'monthly_comparison',
                'months' => $monthsToQuery
            ]);

            $toolOutput = json_encode($res['data'] ?? $res);
            $instructionExtra = "Present an Executive Multi-Month Financial Comparison from [DATABASE RECORD] in English:\n" .
                "1. State prominently in the first sentence: Which month had the Highest Revenue and which had the Highest Net Profit.\n" .
                "2. Render a clean Markdown Table (| Month | Revenue | Expenses | Net Profit | Margin % | Orders | Inches | Taxes |).\n" .
                "3. Conclude with a brief analytical evaluation on business performance across these months.";
        }

        // =========================================================================
        // 3. RENDIMIENTO SEMANAL (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (
            str_contains($lowerMsg, 'this week') || 
            str_contains($lowerMsg, 'esta semana') || 
            str_contains($lowerMsg, 'semana actual') || 
            str_contains($lowerMsg, 'rendimiento semanal') || 
            (str_contains($lowerMsg, 'compare') && str_contains($lowerMsg, 'week')) || 
            (str_contains($lowerMsg, 'compar') && str_contains($lowerMsg, 'semana'))
        ) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Weekly sales performance figures are restricted to Administrators.');
            }

            $isActionCommand = true;
            $res = $this->queryActiveStore($request, ['action' => 'weekly_performance']);
            $toolOutput = json_encode($res['data'] ?? $res);

            $instructionExtra = "Present the Weekly Performance report strictly from [DATABASE RECORD] in English:\n" .
                "1. Render a clean Markdown Table comparing 'Metric' | 'This Week' | 'Last Week' (Revenue, Expenses, Net Profit, Total Orders).\n" .
                "2. State This Week's Inches Printed, Daily Average, and Progress against the $1,425 weekly goal.\n" .
                "3. State the Growth vs Last Week percentage clearly.";
        }

        // =========================================================================
        // 4. REPORTE EXCLUSIVO DE SEMANA PASADA (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (str_contains($lowerMsg, "last week") || str_contains($lowerMsg, "semana pasada")) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Past week financial reports are restricted to Administrators.');
            }

            $isActionCommand = true;
            $res = $this->queryActiveStore($request, ["action" => "business_report", "period" => "last_week"]);
            $toolOutput = json_encode($res["data"] ?? $res);
            $instructionExtra = "Present the Last Week report strictly from [DATABASE RECORD]. State the exact date range, total orders, revenue, total inches, taxes, expenses, and net profit. Never invent values.";
        }

        // =========================================================================
        // 5. REPORTE DE TAXES / IMPUESTOS (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (preg_match('/(?:impuesto|impuestos|taxes|sales\s+tax|tax\s+report|tax\s+collected)/i', $lowerMsg)) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Sales tax collection reports are restricted to Administrators.');
            }

            $isActionCommand = true;
            $isLastMonth = str_contains($lowerMsg, 'last month') || str_contains($lowerMsg, 'mes pasado');

            $res = $this->queryActiveStore($request, [
                'action' => 'tax_summary',
                'period' => $isLastMonth ? 'last_month' : 'this_month'
            ]);

            $toolOutput = json_encode($res['data'] ?? $res);
            $instructionExtra = "Present the Sales Tax Collection summary strictly from [DATABASE RECORD] in English:\n" .
                "- Period and Exact Date Range\n" .
                "- Total Tax Collected ($)\n" .
                "- Number of taxable orders with tax applied\n" .
                "State the values directly without making up percentages or totals.";
        }

        // =========================================================================
        // 6. TOP CATEGORÍAS / PRODUCTOS MÁS VENDIDOS (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (preg_match('/(?:categor[ií]a|m[aá]s\s+vendid[oa]|top\s+products?|best\s+sellers?|qu[eé]\s+vendemos\s+m[aá]s)/i', $lowerMsg)) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Top product revenue analytics are restricted to Administrators.');
            }

            $isActionCommand = true;
            $isLastMonth = str_contains($lowerMsg, 'last month') || str_contains($lowerMsg, 'mes pasado');

            $res = $this->queryActiveStore($request, [
                'action' => 'top_categories',
                'period' => $isLastMonth ? 'last_month' : 'this_month'
            ]);

            $toolOutput = json_encode($res['data'] ?? $res);
            $instructionExtra = "Present the Top Selling Categories in a clean 3-column Markdown Table (| Category | Total Revenue | Items Sold |) in English. Identify which category is the main revenue driver.";
        }

        // =========================================================================
        // 7. REIMPRESIONES Y MERMA DE PRODUCCIÓN (Operativo de Taller - Permitido)
        // =========================================================================
        elseif (preg_match('/(?:reimpresi[oó]n|reimpresiones|reprint|merma|desperdicio|wasted\s+film)/i', $lowerMsg)) {
            $isActionCommand = true;
            $isLastMonth = str_contains($lowerMsg, 'last month') || str_contains($lowerMsg, 'mes pasado');

            $res = $this->queryActiveStore($request, [
                'action' => 'reprint_stats',
                'period' => $isLastMonth ? 'last_month' : 'this_month'
            ]);

            $toolOutput = json_encode($res['data'] ?? $res);
            $instructionExtra = "Present the Reprint & Material Waste report from [DATABASE RECORD] in English:\n" .
                "1. State total reprint orders (-RP), wasted inches, and converted linear feet.\n" .
                "2. If reprints exist, list the recent orders in bullet points (Reference, Customer, Inches, Date).\n" .
                "If count is 0, state clearly that no reprints occurred in this period.";
        }

        // =========================================================================
        // CASO 6: Métricas Financieras Generales (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (
            !$filterYesterday &&
            !$filterToday &&
            (
                str_contains($lowerMsg, 'average') || 
                str_contains($lowerMsg, 'promedio') || 
                str_contains($lowerMsg, 'goal') || 
                str_contains($lowerMsg, 'meta') || 
                str_contains($lowerMsg, 'profit') || 
                str_contains($lowerMsg, 'financial') || 
                str_contains($lowerMsg, 'balance general') || 
                str_contains($lowerMsg, 'weekly') || 
                str_contains($lowerMsg, 'monthly')
            )
        ) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Executive financial metrics are restricted to Administrators.');
            }

            $isActionCommand = true;
            $res = $this->queryActiveStore($request, ['action' => 'financial_metrics']);
            $toolOutput = json_encode($res['data'] ?? $res);

            $instructionExtra = "Present an authoritative financial summary in English strictly using values from [DATABASE RECORD]:\n" .
                "1. Render a clean 2-column Markdown Table (| Metric | Value |) containing: Revenue Today, Revenue Yesterday, Revenue This Week, Daily Average This Week, Weekly Goal ($1,425), Weekly Progress %, Month Revenue, Month Expenses, and Estimated Net Profit.\n" .
                "2. Conclude with 2 concise bullet points evaluating progress against the $285/day goal.";
        }

        // =========================================================================
        // CASO 7: Deudas / Pendientes de Cobro (PROTEGIDO - Solo Admin)
        // =========================================================================
        elseif (str_contains($lowerMsg, 'unpaid') || str_contains($lowerMsg, 'debt') || str_contains($lowerMsg, 'deuda') || str_contains($lowerMsg, 'pending payment') || str_contains($lowerMsg, 'por cobrar') || str_contains($lowerMsg, 'balance')) {
            if (!$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Debt and unpaid balance summaries are restricted to Administrators.');
            }

            $isActionCommand = true;
            $res = $this->queryActiveStore($request, ['action' => 'unpaid_stats']);
            $toolOutput = json_encode($res['data'] ?? $res);
            $instructionExtra = "Present the unpaid orders summary directly from [DATABASE RECORD]: Total Unpaid Orders, Total Outstanding Balance, and debtor list in a Markdown table. Do NOT fabricate debtors.";
        }

        // =========================================================================
        // CASO 8: Métricas Diarias (Blindado contra meses + Protegido para Ventas)
        // =========================================================================
        elseif (!$targetQuery && !$this->extractMonthAndYear($lowerMsg) && (
            $filterYesterday || $filterToday ||
            str_contains($lowerMsg, 'inches') || 
            str_contains($lowerMsg, 'pulgadas') || 
            str_contains($lowerMsg, 'metrics') || 
            str_contains($lowerMsg, 'ventas') || 
            str_contains($lowerMsg, 'ganancia') || 
            str_contains($lowerMsg, 'revenue') || 
            str_contains($lowerMsg, 'sales') || 
            str_contains($lowerMsg, 'earnings')
        )) {
            $isFinancialDailyQuery = str_contains($lowerMsg, 'ventas') || 
                                     str_contains($lowerMsg, 'ganancia') || 
                                     str_contains($lowerMsg, 'revenue') || 
                                     str_contains($lowerMsg, 'sales') || 
                                     str_contains($lowerMsg, 'earnings');

            if ($isFinancialDailyQuery && !$this->canAccessAccounting($request)) {
                return $this->streamAccessDenied('Daily sales and revenue figures are restricted to Administrators.');
            }

            $isActionCommand = true;
            $targetDate = $filterYesterday ? 'yesterday' : 'today';
            $dateLabel = $filterYesterday ? "Yesterday ({$yesterdayFormatted})" : "Today ({$todayFormatted})";

            $res = $this->queryActiveStore($request, [
                'action' => 'daily_stats',
                'date'   => $targetDate
            ]);

            if (isset($res['status']) && $res['status'] === 'error' && str_contains($res['message'] ?? '', 'Invalid action')) {
                $res = $this->queryActiveStore($request, [
                    'action' => 'today_stats',
                    'date'   => $targetDate
                ]);
            }

            $toolOutput = json_encode($res['data'] ?? $res);
            $instructionExtra = "Present the production and activity summary for {$dateLabel} strictly from [DATABASE RECORD]:\n" .
                "- State the requested period and exact Date: {$dateLabel}\n" .
                "- Total Orders Completed/Active\n" .
                "- Total Revenue Collected\n" .
                "- Total Inches Printed\n" .
                "- Pending Orders (Count & Balance)\n" .
                "Answer directly about {$dateLabel}. State the exact figures provided. Do NOT invent, assume, or pull historical data from 2023.";
        }

        // CASO 9: Órdenes Recientes
        elseif (!$targetQuery && (str_contains($lowerMsg, 'recent') || str_contains($lowerMsg, 'latest') || str_contains($lowerMsg, 'ultimas') || str_contains($lowerMsg, 'last orders'))) {
            $isActionCommand = true;
            $res = $this->queryActiveStore($request, ['action' => 'recent_orders']);
            $toolOutput = json_encode($res['data'] ?? $res);
            $instructionExtra = "Format each order as an individual card separated by (---). Do NOT include tracking numbers. DO NOT use tables.";
        }

        // CASO 10: Búsqueda Normal por Identificador Escrito
        elseif ($targetQuery) {
            $isActionCommand = true;
            $dateScope = $filterToday ? 'today' : ($filterYesterday ? 'yesterday' : null);

            $res = $this->queryActiveStore($request, [
                'action' => 'search_order',
                'query'  => $targetQuery,
                'date'   => $dateScope
            ], 12);

            $toolOutput = json_encode($res);
            $instructionExtra = "If single order: format strictly as a clean 2-column Markdown Table (| Order Field | Details |).\n" .
                "CRITICAL: Do NOT include Tracking Number, Tracking URL, or Carrier Tracking fields in the table.\n" .
                "If multiple orders: format each as a card separated by (---).";
        }

        // Construcción del System Prompt
        $systemPrompt = "You are Charlie AI, an expert technical advisor and order management assistant. Speak strictly in English.\n";
        $systemPrompt .= "TEMPORAL AWARENESS CONTEXT:\n";
        $systemPrompt .= "- Reference Timezone: {$tz}\n";
        $systemPrompt .= "- Today's Current Date: {$todayFormatted}\n";
        $systemPrompt .= "- Yesterday's Date: {$yesterdayFormatted}\n";
        $systemPrompt .= "Core Directives:\n";
        $systemPrompt .= "- Be direct, technical, and concise. State facts immediately.\n";
        $systemPrompt .= "- TEMPORAL REASONING: Always anchor your answers to {$todayFormatted}. 'Today' means {$todayFormatted}. 'Yesterday' means {$yesterdayFormatted}. NEVER default to 2023 or any past training cutoff date.\n";
        $systemPrompt .= "- DEFINITIONS & CONCEPTUAL QUESTIONS: When asked 'what is X', definitions, or general questions, answer directly in MAXIMUM 2 to 3 concise sentences (under 60 words). NEVER use bullet points, headers, process steps, or component lists unless the user explicitly commands: 'explain step by step', 'list all', or 'give a complete guide'.\n";
        $systemPrompt .= "- RAG CONTEXT DISCIPLINE: Use [KNOWLEDGE CONTEXT] only to answer the exact question. NEVER copy or dump full document sections, steps, or bullet lists into the chat.\n";
        $systemPrompt .= "- RESPONSE LENGTH: Keep simple questions concise, but provide complete step-by-step answers when the task requires calculations, reasoning, troubleshooting, certification, or detailed explanation. Do not stop before completing the requested answer.\n";
        $systemPrompt .= "- DETAILED ONLY FOR DATABASE TASKS: For order searches, print queues, or financial metrics, use full Markdown tables or lists as explicitly instructed in TASK.\n";

        if ($isActionCommand) {
            $systemPrompt .= "- STRICT DATABASE ACCURACY: For database tasks, NEVER hallucinate, guess, or invent numbers, revenue, order totals, or references. Only output values explicitly provided in [DATABASE RECORD]. If a field is 0 or missing, state 0 or None.\n";
            $systemPrompt .= "- EVIDENCE DISCIPLINE: NEVER infer payment status, shipment status, completion, authorization, customer intent, or any other operational fact from a different field or status. Order status does NOT prove payment status. If the requested fact is not explicitly present in [DATABASE RECORD], say it cannot be confirmed from the available record.\n";
            $systemPrompt .= "- FINANCIAL REASONING: When comparing months or weeks, evaluate net profit (Revenue minus Expenses). Highlight whether margins are expanding or shrinking based strictly on the figures provided.\n";
            $systemPrompt .= "- CURRENCY FORMATTING: Format all monetary amounts with a dollar sign and two decimal places (e.g., $1,250.50). Never truncate or alter calculated totals.\n";
        } else {
            $systemPrompt .= "- STRICT ACCURACY: Do not hallucinate or invent facts. For general reasoning, science, mathematics, troubleshooting, or certification questions, use the user's stated premises, applicable knowledge, and provided knowledge context.\n";
        }

        $systemPrompt .= "- NO TRACKING INFORMATION: NEVER show tracking numbers or shipping carrier tracking links in order details unless specifically requested in the TASK.\n";
        $systemPrompt .= "- ALWAYS separate sections, tables, and bullet points with double newlines.\n";
        $systemPrompt .= "- NEVER mention store names or multi-branch architectures. Address queries as a unified system.\n";

        if (!$isActionCommand) {
            $behaviorRules = $ollama->getGlobalBehaviorRules();
            if (!empty($behaviorRules)) {
                $systemPrompt .= "\n[BUSINESS RULES]\n" . trim($behaviorRules) . "\n";
            }

            if (!empty($userMessage)) {
                $docContext = $ollama->searchRelevantChunks($userMessage, 2);
                if (!empty($docContext)) {
                    $systemPrompt .= "\n[KNOWLEDGE CONTEXT]\n" . trim($docContext) . "\n";
                }
            }
        }

        if (!empty($toolOutput)) {
            $systemPrompt .= "\n[DATABASE RECORD]\n" . trim($toolOutput) . "\n";
            $systemPrompt .= "TASK: {$instructionExtra}\n";
        }

        $shouldSendImageToOllama = (!empty($imageBase64) && empty($qrDetectedRef));

        $userMsgPayload = [
            'role'    => 'user',
            'content' => $userMessage ?: ($qrDetectedRef ? "Show order details for {$qrDetectedRef}" : 'Analyze this image and describe what you see.'),
        ];

        if ($shouldSendImageToOllama) {
            $userMsgPayload['images'] = [$imageBase64];
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            $userMsgPayload,
        ];

        $selectedModel = $shouldSendImageToOllama 
            ? config('services.ollama.vision_model', 'moondream') 
            : null;

        $wantsDeepExplanation = (bool) preg_match(
            '/(?:step by step|paso a paso|how to|guide|guia|list|explain in detail|manual|troubleshoot|calculate|instrucciones)/i', 
            $lowerMsg
        );

        if ($isActionCommand) {
            $maxTokens = 700;
        } elseif (!empty($imageBase64)) {
            $maxTokens = 200;
        } elseif ($wantsDeepExplanation) {
            $maxTokens = 500;
        } else {
            $maxTokens = 120;
        }

        return response()->stream(function () use ($ollama, $messages, $maxTokens, $selectedModel) {
            try {
                $ollama->chatStream($messages, function ($chunk) {
                    $json = json_decode(trim($chunk), true);
                    
                    if (isset($json['error'])) {
                        Log::error('Ollama Error Response: ' . $json['error']);
                        echo "data: " . json_encode(['text' => "⚠️ Ollama Error: " . $json['error']]) . "\n\n";
                        if (ob_get_level() > 0) ob_flush();
                        flush();
                        return;
                    }

                    if (isset($json['message']['content'])) {
                        echo "data: " . json_encode(['text' => $json['message']['content']]) . "\n\n";
                        if (ob_get_level() > 0) ob_flush();
                        flush();
                    }
                }, [
                    'num_predict' => $maxTokens
                ], $selectedModel);
            } catch (\Throwable $e) {
                Log::error('Chatbot Stream Exception: ' . $e->getMessage());
                echo "data: " . json_encode(['text' => "⚠️ Error processing request."]) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
            }

            echo "data: [DONE]\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function generateVoice(Request $request)
    {
        $request->validate(['text' => 'required|string']);
        $text = trim($request->input('text'));

        try {
            $response = Http::timeout(10)
                ->withBody($text, 'text/plain')
                ->post('http://127.0.0.1:5050/');

            if ($response->successful()) {
                return response($response->body(), 200, [
                    'Content-Type'  => 'audio/wav',
                    'Cache-Control' => 'no-cache',
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Piper TTS Error: ' . $e->getMessage());
        }

        return response()->json(['error' => 'TTS generation failed'], 500);
    }

    private function decodeQrCode(string $imageBase64): ?string
    {
        $imageBinary = base64_decode($imageBase64);
        if (!$imageBinary) {
            return null;
        }

        try {
            $qrcode = new QrReader($imageBinary, QrReader::SOURCE_TYPE_BLOB);
            $text = trim($qrcode->text() ?: '');
            if (!empty($text)) {
                return $text;
            }
        } catch (\Throwable $e) {}

        try {
            $src = @imagecreatefromstring($imageBinary);
            if ($src) {
                $w = imagesx($src);
                $h = imagesy($src);

                $pad = 60;
                $padded = imagecreatetruecolor($w + ($pad * 2), $h + ($pad * 2));
                $white = imagecolorallocate($padded, 255, 255, 255);
                imagefilledrectangle($padded, 0, 0, $w + ($pad * 2), $h + ($pad * 2), $white);
                imagecopy($padded, $src, $pad, $pad, 0, 0, $w, $h);

                $qrcodePadded = new QrReader($padded, QrReader::SOURCE_TYPE_RESOURCE);
                $textPadded = trim($qrcodePadded->text() ?: '');
                imagedestroy($padded);

                if (!empty($textPadded)) {
                    imagedestroy($src);
                    return $textPadded;
                }

                if ($w < 600) {
                    $scale = 2;
                    $scaledW = $w * $scale;
                    $scaledH = $h * $scale;
                    $scaled = imagecreatetruecolor($scaledW + ($pad * 2), $scaledH + ($pad * 2));
                    $whiteScaled = imagecolorallocate($scaled, 255, 255, 255);
                    imagefilledrectangle($scaled, 0, 0, $scaledW + ($pad * 2), $scaledH + ($pad * 2), $whiteScaled);
                    imagecopyresampled($scaled, $src, $pad, $pad, 0, 0, $scaledW, $scaledH, $w, $h);

                    $qrcodeScaled = new QrReader($scaled, QrReader::SOURCE_TYPE_RESOURCE);
                    $textScaled = trim($qrcodeScaled->text() ?: '');
                    imagedestroy($scaled);
                    if (!empty($textScaled)) {
                        imagedestroy($src);
                        return $textScaled;
                    }
                }

                imagedestroy($src);
            }
        } catch (\Throwable $e) {
            Log::warning("Error decodificando QR con GD: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Deduce la categoría y subcategoría según palabras clave en inglés o español.
     */
    private function categorizeExpense(string $concept): array
    {
        $c = strtolower(trim($concept));

        // 1. Supplies / Suministros
        if (str_contains($c, 'white ink') || str_contains($c, 'tinta blanca')) {
            return ['Supplies (Suministros)', 'DTF Ink - White', 'DTF Supplies Store'];
        }
        if (str_contains($c, 'ink') || str_contains($c, 'tinta')) {
            return ['Supplies (Suministros)', 'DTF Ink - Color', 'DTF Supplies Store'];
        }
        if (str_contains($c, 'film') || str_contains($c, 'pelicula') || str_contains($c, 'roll')) {
            return ['Supplies (Suministros)', 'DTF Film', 'Film Supplier'];
        }
        if (str_contains($c, 'powder') || str_contains($c, 'polvo') || str_contains($c, 'poliamida')) {
            return ['Supplies (Suministros)', 'DTF Powder', 'Powder Supplier'];
        }
        if (str_contains($c, 'cleaning') || str_contains($c, 'limpiador') || str_contains($c, 'solution') || str_contains($c, 'flush')) {
            return ['Supplies (Suministros)', 'Cleaning Solution / Head Cleaner', 'Maintenance Store'];
        }
        if (str_contains($c, 'box') || str_contains($c, 'caja') || str_contains($c, 'packaging') || str_contains($c, 'glove') || str_contains($c, 'guante')) {
            return ['Supplies (Suministros)', 'Boxes & Packaging', 'Packaging Supplier'];
        }
        if (str_contains($c, 'uv') || str_contains($c, 'uv-dtf')) {
            return ['Supplies (Suministros)', 'UV-DTF Sheets', 'UV Supplier'];
        }
        if (str_contains($c, 'printhead') || str_contains($c, 'cabezal') || str_contains($c, 'damper') || str_contains($c, 'wiper') || str_contains($c, 'capping')) {
            return ['Supplies (Suministros)', 'Printer Parts (heads, dampers, capping station, wipers)', 'Parts Supplier'];
        }

        // 2. Shipping / Delivery
        if (str_contains($c, 'usps')) {
            return ['Shipping / Delivery', 'USPS', 'USPS'];
        }
        if (str_contains($c, 'ups')) {
            return ['Shipping / Delivery', 'UPS', 'UPS'];
        }
        if (str_contains($c, 'fedex')) {
            return ['Shipping / Delivery', 'FedEx', 'FedEx'];
        }
        if (str_contains($c, 'gas') || str_contains($c, 'gasolina') || str_contains($c, 'fuel')) {
            return ['Shipping / Delivery', 'Gas', 'Gas Station'];
        }
        if (str_contains($c, 'delivery') || str_contains($c, 'envio') || str_contains($c, 'flete')) {
            return ['Shipping / Delivery', 'USPS', 'Post Office'];
        }

        // 3. Operational Expenses
        if (str_contains($c, 'rent') || str_contains($c, 'renta') || str_contains($c, 'alquiler')) {
            return ['Operational Expenses', 'Office Rent', 'Office Landlord'];
        }
        if (str_contains($c, 'luz') || str_contains($c, 'power') || str_contains($c, 'electric') || str_contains($c, 'water') || str_contains($c, 'agua') || str_contains($c, 'internet')) {
            return ['Operational Expenses', 'Utilities (Luz, Agua, Internet)', 'Utility Provider'];
        }
        if (str_contains($c, 'canva') || str_contains($c, 'hosting') || str_contains($c, 'server') || str_contains($c, 'gpt') || str_contains($c, 'chatgpt') || str_contains($c, 'software') || str_contains($c, 'license')) {
            return ['Operational Expenses', 'Software Licenses (Canva, Suno, GPT, Cloud, Hosting)', 'Software Service'];
        }

        // 4. Payroll / Labor
        if (str_contains($c, 'sandro')) {
            return ['Payroll / Labor', 'Sandro (Programmer)', 'Sandro'];
        }
        if (str_contains($c, 'lissete') || str_contains($c, 'lisete')) {
            return ['Payroll / Labor', 'Lissete (Customer Service)', 'Lissete'];
        }
        if (str_contains($c, 'labor') || str_contains($c, 'sueldo') || str_contains($c, 'helper') || str_contains($c, 'payroll') || str_contains($c, 'freelance')) {
            return ['Payroll / Labor', 'Helpers / Temporary Workers', 'Labor Staff'];
        }

        // 5. Marketing
        if (str_contains($c, 'ad') || str_contains($c, 'facebook') || str_contains($c, 'instagram') || str_contains($c, 'google') || str_contains($c, 'tiktok') || str_contains($c, 'publicidad')) {
            return ['Marketing / Advertising', 'Facebook Ads', 'Meta / Google'];
        }

        // 6. Taxes & Fees
        if (str_contains($c, 'stripe') || str_contains($c, 'paypal') || str_contains($c, 'fee') || str_contains($c, 'comision')) {
            return ['Taxes & Fees', 'Stripe Fees', 'Payment Processor'];
        }

        // 7. Personal / Owner
        if (str_contains($c, 'carlos')) {
            return ['Personal / Owner', 'Carlos Weekly Payment', 'Carlos'];
        }

        // Por defecto: Otros
        return ['Other Expenses', 'Miscellaneous', !empty($concept) ? ucfirst($concept) : 'General Expense'];
    }
}