<?php

class GothemSwishStatusCallbackModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;
    public $display_column_left = false;
    public $display_column_right = false;

    public function initContent()
    {
        try {
            if (!$this->module->isValidCallbackKey((string)Tools::getValue('key'))) {
                $this->sendJson(array('ok' => false, 'error' => 'forbidden'), 403);
            }

            $result = $this->module->applySwishPayment(
                (int)Tools::getValue('id_order'),
                (string)Tools::getValue('reference'),
                (string)Tools::getValue('payer_name')
            );

            $this->sendJson($result, !empty($result['ok']) ? 200 : 500);
        } catch (Exception $e) {
            $this->sendJson(array(
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ), 500);
        } catch (Throwable $e) {
            $this->sendJson(array(
                'ok' => false,
                'error' => 'throwable',
                'message' => $e->getMessage(),
            ), 500);
        }
    }

    private function sendJson(array $payload, $statusCode = 200)
    {
        http_response_code((int)$statusCode);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode($payload, defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0));
    }
}
