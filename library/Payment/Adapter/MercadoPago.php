<?php
/**
 * Mercado Pago Checkout Pro para FOSSBilling
 * Versão com Validação de Webhook - Janeiro 2026
 */

class Payment_Adapter_MercadoPago extends Payment_AdapterAbstract implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        if (empty($this->config['access_token'])) {
            throw new Payment_Exception('Access Token não configurado');
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Mercado Pago Checkout Pro com webhooks automáticos',
            'logo' => [
                'logo' => 'mercadopago.png',
                'height' => '30px',
                'width' => '90px',
            ],
            'form' => [
                'access_token' => [
                    'text',
                    [
                        'label' => 'Access Token',
                        'description' => 'Cole aqui seu token do Mercado Pago',
                        'required' => true,
                    ],
                ],
                'secret_key' => [
                    'text',
                    [
                        'label' => 'Secret Key',
                        'description' => 'Para validar webhooks. Configure nas notificações do MP.',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        try {
            $invoice = $api_admin->invoice_get(['id' => $invoice_id]);
            $preference = $this->createPreference($invoice);

            if (!$preference) {
                return '<div class="alert alert-danger">Erro ao criar pagamento. Contate o suporte.</div>';
            }

            $paymentUrl = $preference['init_point'];
            $logoUrl = $this->di['tools']->url('data/assets/gateways/mercadopago.png');
            
            $btnContent = "<img src='{$logoUrl}' alt='Mercado Pago' style='max-height:24px; vertical-align:middle; margin-right:10px;'> Pagar com Mercado Pago";

            return "
            <div style='text-align:center; padding:30px;'>
                <a href='{$paymentUrl}' class='btn btn-primary btn-lg' style='background:#009EE3; padding:18px 50px; font-size:20px;'>
                    {$btnContent}
                </a>
                <p style='margin-top:15px; color:#666;'>
                    Redirecionando em <strong id='countdown'>3</strong> segundos...
                </p>
                <script>
                    let s = 3;
                    const redirect = () => window.location.href = '{$paymentUrl}';
                    setTimeout(redirect, 3000);
                    setInterval(() => {
                        const el = document.getElementById('countdown');
                        if (el && s > 0) el.textContent = --s;
                    }, 1000);
                </script>
            </div>";
        } catch (Exception $e) {
            error_log('[MercadoPago] Erro: ' . $e->getMessage());
            return '<div class="alert alert-danger">Erro interno. Tente novamente.</div>';
        }
    }

    private function createPreference($invoice): ?array
    {
        $invoiceId = $invoice['id'];
        $total = round((float)$invoice['total'], 2);

        if ($total < 0.50) {
            error_log('[MercadoPago] Valor muito baixo: ' . $total);
            return null;
        }

        $tools = $this->di['tools'];
        $baseUrl = $tools->url('');
        $webhookUrl = rtrim($baseUrl, '/') . '/ipn.php';

        $payload = [
            'items' => [[
                'title' => "Fatura #{$invoice['nr']}",
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => $total,
            ]],
            'payer' => [
                'email' => $this->getEmail($invoice),
            ],
            'back_urls' => [
                'success' => $this->di['url']->link('invoice', ['id' => $invoice['hash']]),
                'pending' => $this->di['url']->link('invoice', ['id' => $invoice['hash']]),
                'failure' => $this->di['url']->link('invoice', ['id' => $invoice['hash']]),
            ],
            'auto_return' => 'approved',
            'notification_url' => $webhookUrl,
            'external_reference' => "INV_{$invoiceId}",
        ];

        $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config['access_token'],
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 201) {
            error_log("[MercadoPago] ❌ Erro API ({$code}): {$result}");
            return null;
        }

        return json_decode($result, true);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        // ═══════════════════════════════════════════════════════════
        // 🔐 VALIDAÇÃO DE WEBHOOK (SE SECRET_KEY CONFIGURADA)
        // ═══════════════════════════════════════════════════════════
        if (!empty($this->config['secret_key'])) {
            $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
            $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
            
            if (empty($signature)) {
                error_log('[MercadoPago] ⚠️ Webhook sem assinatura (X-Signature ausente)');
                http_response_code(401);
                return;
            }

            // Reconstrói o payload original (FOSSBilling já parseou como $data['post'])
            $payload = file_get_contents('php://input');
            
            // Calcula HMAC esperado
            $expected = hash_hmac(
                'sha256',
                $requestId . $payload, // ⚠️ Formato: requestId + payload
                $this->config['secret_key']
            );

            // Comparação segura
            if (!hash_equals($expected, $signature)) {
                error_log(sprintf(
                    '[MercadoPago] ❌ Webhook INVÁLIDO - Request ID: %s | Signature: %s',
                    $requestId,
                    substr($signature, 0, 16) . '...'
                ));
                http_response_code(401);
                return;
            }

            error_log('[MercadoPago] ✅ Webhook validado com sucesso');
        }

        // ═══════════════════════════════════════════════════════════
        // 📦 PROCESSAMENTO DO WEBHOOK
        // ═══════════════════════════════════════════════════════════
        $webhook = $data['post'] ?? [];
        $type = $webhook['type'] ?? $webhook['action'] ?? 'DESCONHECIDO';

        // Filtra apenas eventos de pagamento
        if (strpos($type, 'payment') === false) {
            return;
        }

        $paymentId = $webhook['data']['id'] ?? null;
        if (!$paymentId) {
            error_log('[MercadoPago] ❌ Sem payment ID');
            return;
        }

        // Ignora webhooks de teste
        if (in_array($paymentId, ['123456', '12345678', 1234567890])) {
            return;
        }

        // Busca detalhes do pagamento na API do MP
        $payment = $this->getPayment($paymentId);
        if (!$payment) {
            error_log('[MercadoPago] ❌ Não foi possível buscar o pagamento');
            return;
        }

        // Extrai invoice_id da referência externa
        if (!preg_match('/^INV_(\d+)$/', $payment['external_reference'] ?? '', $m)) {
            error_log('[MercadoPago] ❌ Referência externa inválida: ' . ($payment['external_reference'] ?? 'VAZIO'));
            return;
        }

        $invoiceId = (int)$m[1];

        // Verifica se já foi processado
        try {
            $api_admin->invoice_transaction_get(['txn_id' => (string)$paymentId]);
            return; // Já existe
        } catch (Exception $e) {
            // Não existe, continua
        }

        // Se não for aprovado, registra como pendente (se for boleto/pix)
        if ($payment['status'] !== 'approved') {
            if (in_array($payment['status'], ['rejected', 'cancelled'])) {
                return; // Ignora rejeitados
            }

            try {
                $api_admin->invoice_transaction_create([
                    'invoice_id' => $invoiceId,
                    'gateway_id' => $gateway_id,
                    'txn_id' => (string)$paymentId,
                    'amount' => $payment['transaction_amount'],
                    'currency' => $payment['currency_id'],
                    'status' => 'pending',
                    'type' => 'payment',
                ]);
            } catch (Exception $e) {
                error_log('[MercadoPago] ⚠️ Erro ao registrar pendente: ' . $e->getMessage());
            }
            
            return;
        }

        // ═══════════════════════════════════════════════════════════
        // ✅ PROCESSAR PAGAMENTO APROVADO
        // ═══════════════════════════════════════════════════════════
        try {
            $invoice = $api_admin->invoice_get(['id' => $invoiceId]);
            
            if ($invoice['status'] === 'paid') {
                return; // Já está paga
            }

            // 1. Registra transação
            $api_admin->invoice_transaction_create([
                'invoice_id' => $invoiceId,
                'gateway_id' => $gateway_id,
                'txn_id' => (string)$paymentId,
                'amount' => $payment['transaction_amount'],
                'currency' => $payment['currency_id'],
                'status' => 'processed',
                'type' => 'payment',
            ]);

            // 2. Marca fatura como paga
            $api_admin->invoice_mark_as_paid([
                'id' => $invoiceId,
                'note' => "Mercado Pago Payment ID: {$paymentId}"
            ]);

            error_log("[MercadoPago] ✅ Fatura #{$invoiceId} paga com sucesso! Payment ID: {$paymentId}");

        } catch (Exception $e) {
            error_log('[MercadoPago] ==========================================');
            error_log('[MercadoPago] ❌❌❌ ERRO CRÍTICO ❌❌❌');
            error_log('[MercadoPago] ==========================================');
            error_log('[MercadoPago] Mensagem: ' . $e->getMessage());
            error_log('[MercadoPago] Arquivo: ' . $e->getFile() . ':' . $e->getLine());
            error_log('[MercadoPago] Stack trace:');
            error_log($e->getTraceAsString());
            error_log('[MercadoPago] ==========================================');
        }
    }

    private function getPayment($paymentId): ?array
    {
        $ch = curl_init("https://api.mercadopago.com/v1/payments/{$paymentId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['access_token'],
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            error_log("[MercadoPago] ❌ Erro ao buscar pagamento (HTTP {$code})");
            return null;
        }

        return json_decode($result, true);
    }

    private function getEmail($invoice): string
    {
        $email = $invoice['buyer']['email'] 
            ?? $invoice['client']['email'] 
            ?? null;
            
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        
        try {
            $sysEmail = $this->di['mod_service']('system')->getParamValue('company_email');
            if ($sysEmail && filter_var($sysEmail, FILTER_VALIDATE_EMAIL)) {
                return $sysEmail;
            }
        } catch (Exception $e) {}
        
        return 'noreply@localhost';
    }
}
