<?php
/**
 * IPN HÍBRIDO - FOSSBilling + Mercado Pago
 * 
 * FUNCIONAMENTO:
 * - Detecta automaticamente se é Mercado Pago (JSON) ou outro gateway (POST/GET)
 * - Mercado Pago: busca gateway_id do banco, injeta JSON no $_POST
 * - Outros: funciona normalmente com invoice_id e gateway_id na URL
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'load.php';
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

$di = include Path::join(PATH_ROOT, 'di.php');
$di['translate']();
$filesystem = new Filesystem();

// ========================================
// ETAPA 1: CAPTURA DE HEADERS
// ========================================
// Necessário porque Mercado Pago envia X-Signature, X-Request-Id
$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    // Fallback para servidores sem getallheaders()
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$headerName] = $value;
        }
    }
}
$headers = array_change_key_case($headers, CASE_LOWER);

// ========================================
// ETAPA 2: LÊ O BODY RAW (JSON)
// ========================================
$rawInput = $filesystem->readFile('php://input');

// ========================================
// ETAPA 3: DETECTA MERCADO PAGO / ASTROPAY
// ========================================
$isMercadoPago = false;
$isAstroPay = false;
$isPayPal = false;
$mpPaymentId = null;

// FORMATO 1: POST com JSON Body
if (!empty($rawInput)) {
    $json = json_decode($rawInput, true);
    
    if (json_last_error() === JSON_ERROR_NONE) {
        // DETECÇÃO MERCADO PAGO
        if (isset($json['data']['id'])
            && (isset($json['type']) || isset($json['action']))
            && (($json['type'] ?? '') === 'payment' || strpos($json['action'] ?? '', 'payment') !== false)) {
            
            $isMercadoPago = true;
            $mpPaymentId = $json['data']['id'];
            
            // Injeta no $_POST para compatibilidade
            $_POST = array_merge($_POST, $json);
            $_REQUEST = array_merge($_REQUEST, $json);
        }
        // DETECÇÃO ASTROPAY
        elseif (isset($json['payment_external_id']) && isset($json['merchant_payment_id'])) {
            $isAstroPay = true;
            // Injeta no $_POST para compatibilidade
            $_POST = array_merge($_POST, $json);
            $_REQUEST = array_merge($_REQUEST, $json);
            error_log("[IPN] 🚀 Detectado webhook AstroPay");
        }
    }
}

// FORMATO 1.5: POST de PayPal (detecção por campos específicos)
if (!$isMercadoPago && !$isAstroPay && (isset($_POST['verify_sign']) || isset($_POST['txn_id'])) && isset($_POST['receiver_email'])) {
    $isPayPal = true;
    error_log("[IPN] 🚀 Detectado webhook PayPal");
}

// FORMATO 2: GET com Query Params
// Exemplo: ?id=143368148296&topic=payment
if (!$isMercadoPago && isset($_GET['id'], $_GET['topic'])) {
    $topic = $_GET['topic'];
    
    // Aceita "payment" ou "merchant_order" (ambos são do MP)
    if (in_array($topic, ['payment', 'merchant_order'], true)) {
        $isMercadoPago = true;
        $mpPaymentId = $_GET['id'];
        
        // Converte para formato JSON esperado pelo adapter
        $simulatedJson = [
            'type' => $topic,
            'action' => $topic . '.updated',
            'data' => ['id' => $mpPaymentId]
        ];
        
        // Injeta no $_POST
        $_POST = array_merge($_POST, $simulatedJson);
        $_REQUEST = array_merge($_REQUEST, $simulatedJson);
        
        error_log("[IPN] 🔄 Convertido webhook GET para formato JSON");
    }
}

// ========================================
// ETAPA 4: CAPTURA IDs (método padrão)
// ========================================
// Para gateways normais: invoice_id e gateway_id vêm na URL
// Exemplo: ipn.php?invoice_id=87&gateway_id=6
$invoiceID = $_POST['invoice_id'] ?? $_GET['invoice_id'] ?? $_POST['bb_invoice_id'] ?? $_GET['bb_invoice_id'] ?? null;
$gatewayID = $_POST['gateway_id'] ?? $_GET['gateway_id'] ?? $_POST['bb_gateway_id'] ?? $_GET['bb_gateway_id'] ?? null;

// ========================================
// ETAPA 5: MERCADO PAGO / ASTROPAY / PAYPAL - BUSCA GATEWAY_ID
// ========================================
// Como MP, AstroPay e PayPal (às vezes) não enviam gateway_id na URL, precisamos buscar no banco

if (($isMercadoPago || $isAstroPay || $isPayPal) && empty($gatewayID)) {
    $gatewayName = $isMercadoPago ? 'MercadoPago' : ($isAstroPay ? 'AstroPay' : 'PayPalEmail');
    try {
        $sql = 'SELECT id FROM pay_gateway WHERE gateway = :name AND enabled = 1 LIMIT 1';
        $gateway = $di['db']->getRow($sql, [':name' => $gatewayName]);
        
        if ($gateway && isset($gateway['id'])) {
            $gatewayID = (int)$gateway['id'];
        } else {
            // Fallback para PayPal (tentar apenas 'PayPal' se 'PayPalEmail' falhar)
            if ($isPayPal && $gatewayName === 'PayPalEmail') {
                 $gateway = $di['db']->getRow('SELECT id FROM pay_gateway WHERE gateway = "PayPal" AND enabled = 1 LIMIT 1');
                 if ($gateway && isset($gateway['id'])) {
                     $gatewayID = (int)$gateway['id'];
                 }
            }
        }

        if ($gatewayID) {
             // Encontrado
        } else {
            error_log("[IPN] ❌ Gateway $gatewayName não encontrado!");
            error_log('[IPN] 💡 Verifique se está cadastrado e ATIVO em: Sistema > Pagamentos');
            http_response_code(500);
            echo json_encode(['error' => "Gateway $gatewayName not found or disabled"]);
            exit;
        }
    } catch (Exception $e) {
        error_log('[IPN] ❌ Erro ao buscar gateway: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        exit;
    }
}

// Atualiza $_GET para compatibilidade com FOSSBilling
$_GET['bb_invoice_id'] = $invoiceID;
$_GET['bb_gateway_id'] = $gatewayID;

// ========================================
// ETAPA 6: LOG DE DEBUG
// ========================================
if ($isMercadoPago) {
    error_log("[IPN] 📋 Invoice ID: " . ($invoiceID ?? 'SERÁ BUSCADO DO PAGAMENTO'));
    error_log("[IPN] 🔧 Gateway ID: " . ($gatewayID ?? 'NULL'));
    error_log("[IPN] 🔐 Headers:");
    error_log("[IPN]    X-Signature: " . (isset($headers['x-signature']) ? '✓' : '✗'));
    error_log("[IPN]    X-Request-Id: " . (isset($headers['x-request-id']) ? '✓' : '✗'));
}

// ========================================
// ETAPA 7: VALIDA GATEWAY ID
// ========================================
if (empty($gatewayID)) {
    error_log("[IPN] ❌ Gateway ID não fornecido!");
    error_log("[IPN] POST: " . json_encode($_POST));
    error_log("[IPN] GET: " . json_encode($_GET));
    http_response_code(400);
    echo json_encode(['error' => 'Gateway ID is required']);
    exit;
}

// ========================================
// ETAPA 8: MONTA O IPN
// ========================================
$ipn = [
    'skip_validation' => true,
    'invoice_id' => $invoiceID,
    'gateway_id' => $gatewayID,
    'get' => $_GET,
    'post' => $_POST,
    'server' => $_SERVER,
    'headers' => $headers,
    'http_raw_post_data' => $rawInput,
];

// ========================================
// ETAPA 9: PROCESSA
// ========================================
try {
    $service = $di['mod_service']('invoice', 'transaction');
    $output = $service->createAndProcess($ipn);
    $res = ['result' => $output, 'error' => null];
    
    if ($isMercadoPago) {
    }
} catch (Exception $e) {
    error_log('[IPN] ❌ ERRO: ' . $e->getMessage());
    error_log('[IPN] Stack: ' . $e->getTraceAsString());
    $res = ['result' => null, 'error' => ['message' => $e->getMessage()]];
    $output = false;
}

// ========================================
// ETAPA 10: REDIRECIONAMENTO (se solicitado)
// ========================================
if (isset($_GET['redirect'], $_GET['invoice_hash']) || isset($_GET['bb_redirect'], $_GET['bb_invoice_hash'])) {
    $hash = $_GET['invoice_hash'] ?? $_GET['bb_invoice_hash'];
    $url = $di['url']->link('invoice/' . $hash);
    header("Location: $url");
    exit;
}

// ========================================
// ETAPA 11: RESPOSTA
// ========================================
http_response_code($output ? 200 : 500);
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-type: application/json; charset=utf-8');
echo json_encode($res);
exit;
