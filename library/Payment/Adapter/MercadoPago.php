<?php
/**
 * Mercado Pago Checkout Pro para FOSSBilling
 * Versão Simplificada - Janeiro 2026
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
                        'label' => 'Secret Key (Opcional)',
                        'description' => 'Para validar webhooks. Recomendado em produção.',
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

            return "
            <div style='text-align:center; padding:30px;'>
                <a href='{$paymentUrl}' class='btn btn-primary btn-lg' style='background:#009EE3; padding:18px 50px; font-size:20px;'>
                    💳 Pagar com Mercado Pago
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

        // 🔥 CORRIGIDO: Pega URL base usando tools do FOSSBilling
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

        $data = json_decode($result, true);
        
        return $data;
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        // O webhook do MP vem no formato: {"type":"payment","data":{"id":"123456"}}
        $webhook = $data['post'] ?? [];
        
        $type = $webhook['type'] ?? $webhook['action'] ?? 'DESCONHECIDO';

        // Filtra apenas pagamentos
        if (strpos($type, 'payment') === false) {
            return;
        }

        $paymentId = $webhook['data']['id'] ?? null;
        if (!$paymentId) {
            error_log('[MercadoPago] ❌ Sem payment ID');
            error_log('[MercadoPago] Webhook completo: ' . json_encode($webhook, JSON_PRETTY_PRINT));
            return;
        }

        // Ignora webhooks de teste do MP
        if (in_array($paymentId, ['123456', '12345678', 1234567890])) {
            return;
        }

        // Busca detalhes do pagamento
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
            $existing = $api_admin->invoice_transaction_get(['txn_id' => (string)$paymentId]);
            return;
        } catch (Exception $e) {
            // Não existe, ok continuar
        }

        // Só processa se aprovado
        if ($payment['status'] !== 'approved') {
            
            // Registra como pendente
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

        // PROCESSAR PAGAMENTO APROVADO
        try {         
            $invoice = $api_admin->invoice_get(['id' => $invoiceId]);     
            if ($invoice['status'] === 'paid') {
                return;
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
            error_log("[MercadoPago] Resposta: {$result}");
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
        
        // Fallback para email do sistema
        try {
            $sysEmail = $this->di['mod_service']('system')->getParamValue('company_email');
            if ($sysEmail && filter_var($sysEmail, FILTER_VALIDATE_EMAIL)) {
                return $sysEmail;
            }
        } catch (Exception $e) {}
        
        return 'noreply@localhost';
    }
}
