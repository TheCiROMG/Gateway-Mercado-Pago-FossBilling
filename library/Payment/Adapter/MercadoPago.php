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
        $webhook = $data['post'] ?? [];
        $type = $webhook['type'] ?? $webhook['action'] ?? 'DESCONHECIDO';

        // Filtra apenas pagamentos
        if (strpos($type, 'payment') === false) {
            error_log('[MercadoPago] ⏭️ Ignorando webhook tipo: ' . $type);
            return true;
        }

        $paymentId = $webhook['data']['id'] ?? null;
        if (!$paymentId) {
            error_log('[MercadoPago] ❌ Sem payment ID no webhook');
            return false;
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

            // Verifica se já foi processado
            try {
                $existing = $api_admin->invoice_transaction_get(['txn_id' => (string)$paymentId]);
                if ($existing) {
                    error_log('[MercadoPago] ✅ Transação já processada anteriormente');
                    return true;
                }
            } catch (Exception $e) {
                // Não existe, continuar
            }

            // Só processa se aprovado
            if ($payment['status'] !== 'approved') {
                // Se for rejeitado ou cancelado, não cria transação pendente (evita poluição visual)
                if (in_array($payment['status'], ['rejected', 'cancelled'])) {
                    return true;
                }

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
                    error_log('[MercadoPago] 📝 Transação pendente registrada');
                } catch (Exception $e) {
                    error_log('[MercadoPago] ⚠️ Erro ao registrar pendente: ' . $e->getMessage());
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

            // 1. Registra transação
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
