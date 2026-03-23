<?php
/**
 * Mercado Pago Checkout Pro para FOSSBilling
 * Versão 24/01/2026
 * Créditos: 4TeamBR
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
                        'label' => 'Secret Key (Opcional)',
                        'description' => 'Para validar webhooks. Recomendado em produção.',
                        'required' => false,
                    ],
                ],
				'logo_url' => [
                    'text',
                    [
                        'label' => 'URL do Logo (Opcional)',
                        'description' => 'URL da imagem para exibir no botão (ex: https://site.com/logo.png)',
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
			
			            $logoUrl = !empty($this->config['logo_url']) ? $this->config['logo_url'] : null;
            
            if (!$logoUrl) {
                 // Use local asset by default
                 $logoUrl = $this->di['tools']->url('data/assets/gateways/mercadopago.png');
            }
            
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
        $currencyId = $this->resolveCurrencyId($invoice);

        $payload = [
            'items' => [[
                'title' => "Fatura #{$invoice['nr']}",
                'quantity' => 1,
                'currency_id' => $currencyId,
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
        $webhook = $data['post'] ?? [];
        $dataId = $this->extractWebhookDataId($data);

        if (!$this->isValidWebhookSignature($data, $dataId)) {
            error_log('[MercadoPago] ❌ Assinatura inválida (webhook ignorado)');
            return true;
        }

        $webhookType = $webhook['type'] ?? null;
        $action = (string)($webhook['action'] ?? '');
        $topic = $data['get']['topic'] ?? null;
        $typeParam = $data['get']['type'] ?? null;

        $isPayment = ($webhookType === 'payment') || (strpos($action, 'payment') !== false) || ($topic === 'payment') || ($typeParam === 'payment');
        $isMerchantOrder = ($webhookType === 'merchant_order')
            || ($webhookType === 'topic_merchant_order_wh')
            || (strpos($action, 'merchant_order') !== false)
            || (strpos($action, 'topic_merchant_order_wh') !== false)
            || ($topic === 'merchant_order')
            || ($topic === 'topic_merchant_order_wh')
            || ($typeParam === 'merchant_order')
            || ($typeParam === 'topic_merchant_order_wh');

        if (!$isPayment && !$isMerchantOrder) {
            $logType = $webhookType ?: ($typeParam ?: ($topic ?: ($action ?: 'DESCONHECIDO')));
            error_log('[MercadoPago] ⏭️ Ignorando webhook tipo: ' . $logType);
            return true;
        }

        if (!$dataId) {
            error_log('[MercadoPago] ❌ Sem ID no webhook');
            return false;
        }

        $paymentId = null;
        if ($isPayment) {
            $paymentId = $dataId;
        } else {
            $merchantOrderId = $dataId;
            $merchantOrder = $this->getMerchantOrder($merchantOrderId);
            if (!$merchantOrder) {
                throw new Exception('Não foi possível buscar dados da merchant order (API Error)');
            }

            $payments = $merchantOrder['payments'] ?? [];
            if (!is_array($payments) || empty($payments)) {
                error_log('[MercadoPago] ⏭️ Merchant order sem pagamentos: ' . $merchantOrderId);
                return true;
            }

            foreach ($payments as $p) {
                if (!is_array($p)) {
                    continue;
                }
                if (($p['status'] ?? null) === 'approved' && !empty($p['id'])) {
                    $paymentId = (string)$p['id'];
                    break;
                }
            }

            if ($paymentId === null) {
                $first = $payments[0] ?? null;
                if (is_array($first) && !empty($first['id'])) {
                    $paymentId = (string)$first['id'];
                }
            }

            if ($paymentId === null) {
                error_log('[MercadoPago] ⏭️ Merchant order sem payment id utilizável: ' . $merchantOrderId);
                return true;
            }
        }

        // Ignora webhooks de teste
        if (in_array($paymentId, ['123456', '12345678', 1234567890])) {
            return true;
        }

        error_log('[MercadoPago] 📨 Webhook recebido - Payment ID: ' . $paymentId);

        // 🔒 LOCK DE PROCESSAMENTO - Previne duplicação
        $lockKey = "mp_payment_{$paymentId}";
        if ($this->isLocked($lockKey)) {
            error_log('[MercadoPago] 🔒 Pagamento já está sendo processado, ignorando duplicata');
            return true;
        }
        $this->setLock($lockKey);

        try {
            // Busca detalhes do pagamento
            $payment = $this->getPayment($paymentId);
            if (!$payment) {
                throw new Exception('Não foi possível buscar dados do pagamento (API Error)');
            }

            error_log('[MercadoPago] 💰 Status do pagamento: ' . $payment['status']);

            // Extrai invoice_id
            if (!preg_match('/^INV_(\d+)$/', $payment['external_reference'] ?? '', $m)) {
                error_log('[MercadoPago] ❌ Referência inválida: ' . ($payment['external_reference'] ?? 'VAZIO'));
                return true; // Retorna true para não ficar tentando processar algo inválido
            }

            $invoiceId = (int)$m[1];
            error_log('[MercadoPago] 📋 Invoice ID extraído: ' . $invoiceId);

            $existingTransactionId = null;
            try {
                $existingList = $api_admin->invoice_transaction_get_list([
                    'txn_id' => (string)$paymentId,
                    'per_page' => 1,
                ]);
                if (is_array($existingList)
                    && isset($existingList['list'])
                    && is_array($existingList['list'])
                    && isset($existingList['list'][0])
                    && is_array($existingList['list'][0])
                    && !empty($existingList['list'][0]['id'])) {
                    $existingTransactionId = (int)$existingList['list'][0]['id'];
                    error_log('[MercadoPago] ✅ Transação já existe para este pagamento: #' . $existingTransactionId);
                }
            } catch (Exception $e) {
            }

            // Só processa se aprovado
            if ($payment['status'] !== 'approved') {
                // Se for rejeitado ou cancelado, não cria transação pendente (evita poluição visual)
                if (in_array($payment['status'], ['rejected', 'cancelled'])) {
                    return true;
                }

                // Registra como pendente
                if ($existingTransactionId === null) {
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
                        error_log('[MercadoPago] 📝 Transação pendente registrada');
                    } catch (Exception $e) {
                        error_log('[MercadoPago] ⚠️ Erro ao registrar pendente: ' . $e->getMessage());
                    }
                }
                
                return true;
            }

            // ✅ PAGAMENTO APROVADO - PROCESSAR
            error_log('[MercadoPago] ✅ Pagamento aprovado! Processando...');

            $invoice = $api_admin->invoice_get(['id' => $invoiceId]);
            
            if ($invoice['status'] === 'paid') {
                error_log('[MercadoPago] ℹ️ Fatura já está marcada como paga');
                return true;
            }

            if (empty($invoice['gateway_id'])) {
                error_log('[MercadoPago] ⚠️ Fatura sem gateway definido. Setando gateway antes de marcar como paga...');
                $api_admin->invoice_update([
                    'id' => $invoiceId,
                    'gateway_id' => $gateway_id,
                ]);
                $invoice = $api_admin->invoice_get(['id' => $invoiceId]);
            }

            if (empty($invoice['gateway_id'])) {
                throw new Exception('Fatura continua sem gateway definido após tentativa de update');
            }

            if ($existingTransactionId === null) {
                error_log('[MercadoPago] 📝 Criando registro de transação...');
                $txn = $api_admin->invoice_transaction_create([
                    'invoice_id' => $invoiceId,
                    'gateway_id' => $gateway_id,
                    'txn_id' => (string)$paymentId,
                    'amount' => $payment['transaction_amount'],
                    'currency' => $payment['currency_id'],
                    'status' => 'processed',
                    'type' => 'payment',
                ]);
                error_log('[MercadoPago] ✅ Transação criada: ID #' . $txn);
            } else {
                error_log('[MercadoPago] ℹ️ Pulando criação de transação duplicada (já existe #' . $existingTransactionId . ')');
            }

            // 2. Marca fatura como paga (ISSO ATIVA O SERVIÇO AUTOMATICAMENTE)
            error_log('[MercadoPago] 💵 Marcando fatura como paga...');
            $result = $api_admin->invoice_mark_as_paid([
                'id' => $invoiceId,
                'note' => "Pagamento aprovado - Mercado Pago ID: {$paymentId}",
                'execute' => true  // 🔥 FORÇA EXECUÇÃO DOS HOOKS
            ]);
            
            error_log('[MercadoPago] ✅✅✅ FATURA PAGA E SERVIÇO ATIVADO!');
            error_log('[MercadoPago] 📊 Resultado: ' . json_encode($result));
            
            return true;

        } catch (Exception $e) {
            error_log('[MercadoPago] ==========================================');
            error_log('[MercadoPago] ❌❌❌ ERRO CRÍTICO ❌❌❌');
            error_log('[MercadoPago] ==========================================');
            error_log('[MercadoPago] Invoice ID: ' . ($invoiceId ?? 'N/A'));
            error_log('[MercadoPago] Payment ID: ' . $paymentId);
            error_log('[MercadoPago] Mensagem: ' . $e->getMessage());
            error_log('[MercadoPago] Arquivo: ' . $e->getFile() . ':' . $e->getLine());
            error_log('[MercadoPago] Stack trace:');
            error_log($e->getTraceAsString());
            error_log('[MercadoPago] ==========================================');
            throw $e; // Re-lança para o IPN retornar 500 e forçar retry do Mercado Pago
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    /**
     * Sistema de Lock simples usando arquivos
     */
    private function isLocked(string $key): bool
    {
        $lockFile = sys_get_temp_dir() . '/' . md5($key) . '.lock';
        
        if (!file_exists($lockFile)) {
            return false;
        }
        
        // Se o lock tem mais de 60 segundos, considera expirado
        if (time() - filemtime($lockFile) > 60) {
            @unlink($lockFile);
            return false;
        }
        
        return true;
    }

    private function setLock(string $key): void
    {
        $lockFile = sys_get_temp_dir() . '/' . md5($key) . '.lock';
        file_put_contents($lockFile, time());
    }

    private function releaseLock(string $key): void
    {
        $lockFile = sys_get_temp_dir() . '/' . md5($key) . '.lock';
        @unlink($lockFile);
    }

    private function resolveCurrencyId(array $invoice): string
    {
        $currency = strtoupper((string)($invoice['currency'] ?? ''));
        $supported = ['ARS', 'BRL', 'CLP', 'COP', 'MXN', 'PEN', 'UYU'];
        if ($currency !== '' && in_array($currency, $supported, true)) {
            return $currency;
        }

        if ($currency !== '') {
            error_log('[MercadoPago] ⚠️ Moeda não suportada pelo Mercado Pago: ' . $currency . '. Usando BRL.');
        }

        return 'BRL';
    }

    private function extractWebhookDataId(array $data): ?string
    {
        $post = $data['post'] ?? [];
        $id = $post['data']['id'] ?? null;
        if ($id !== null && $id !== '') {
            return (string)$id;
        }

        $get = $data['get'] ?? [];
        if (isset($get['data.id']) && $get['data.id'] !== '') {
            return (string)$get['data.id'];
        }
        if (isset($get['id']) && $get['id'] !== '') {
            return (string)$get['id'];
        }
        if (isset($post['id']) && $post['id'] !== '') {
            return (string)$post['id'];
        }

        return null;
    }

    private function isValidWebhookSignature(array $data, ?string $dataId): bool
    {
        $secret = trim((string)($this->config['secret_key'] ?? ''));
        if ($secret === '') {
            return true;
        }

        $headers = $data['headers'] ?? [];
        if (!is_array($headers)) {
            $headers = [];
        }

        $xSignature = $headers['x-signature'] ?? $headers['x_signature'] ?? null;
        $xRequestId = $headers['x-request-id'] ?? $headers['x_request_id'] ?? null;

        if (!$dataId) {
            return true;
        }

        if (!$xSignature || !$xRequestId) {
            error_log('[MercadoPago] ⚠️ Secret Key configurada, mas headers de assinatura não vieram no webhook');
            return true;
        }

        $parts = explode(',', (string)$xSignature);
        $ts = null;
        $hash = null;
        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 'ts') {
                $ts = $kv[1];
            } elseif ($kv[0] === 'v1') {
                $hash = $kv[1];
            }
        }

        if (!$ts || !$hash) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $secret);
        return hash_equals(strtolower($expected), strtolower((string)$hash));
    }

    private function getMerchantOrder($merchantOrderId): ?array
    {
        $ch = curl_init("https://api.mercadopago.com/merchant_orders/{$merchantOrderId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->config['access_token'],
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            error_log("[MercadoPago] ❌ Erro de conexão (cURL {$errno}): {$error}");
            return null;
        }

        if ($code !== 200) {
            error_log("[MercadoPago] ❌ Erro ao buscar merchant order (HTTP {$code})");
            error_log("[MercadoPago] Resposta: {$result}");
            return null;
        }

        return json_decode($result, true);
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
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            error_log("[MercadoPago] ❌ Erro de conexão (cURL {$errno}): {$error}");
            return null;
        }

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
        
        try {
            $sysEmail = $this->di['mod_service']('system')->getParamValue('company_email');
            if ($sysEmail && filter_var($sysEmail, FILTER_VALIDATE_EMAIL)) {
                return $sysEmail;
            }
        } catch (Exception $e) {}
        
        return 'noreply@localhost';
    }
}
