<?php

if (!defined('_TB_VERSION_') && !defined('_PS_VERSION_')) {
    exit;
}

class GothemSwishStatus extends Module
{
    const CFG_CALLBACK_KEY = 'GOTHEM_SWISH_STATUS_KEY';
    const CFG_PAID_STATE = 'GOTHEM_SWISH_STATUS_PAID_STATE';
    const CFG_DELIVERED_STATE = 'GOTHEM_SWISH_STATUS_DELIVERED_STATE';

    public function __construct()
    {
        $this->name = 'gothemswishstatus';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.4';
        $this->author = 'Gothem Innovations';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->controllers = array('callback');

        parent::__construct();

        $this->displayName = $this->l('Gothem Swish Status');
        $this->description = $this->l('Applies matched Swish payments to thirty bees orders.');
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue(self::CFG_CALLBACK_KEY, '')
            && Configuration::updateValue(self::CFG_PAID_STATE, (int)Configuration::get('PS_OS_PAYMENT') ?: 1)
            && Configuration::updateValue(self::CFG_DELIVERED_STATE, (int)Configuration::get('PS_OS_DELIVERED') ?: 4);
    }

    public function uninstall()
    {
        Configuration::deleteByName(self::CFG_CALLBACK_KEY);
        Configuration::deleteByName(self::CFG_PAID_STATE);
        Configuration::deleteByName(self::CFG_DELIVERED_STATE);

        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitGothemSwishStatus')) {
            Configuration::updateValue(self::CFG_CALLBACK_KEY, trim((string)Tools::getValue(self::CFG_CALLBACK_KEY)));
            Configuration::updateValue(self::CFG_PAID_STATE, (int)Tools::getValue(self::CFG_PAID_STATE));
            Configuration::updateValue(self::CFG_DELIVERED_STATE, (int)Tools::getValue(self::CFG_DELIVERED_STATE));
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        $callbackUrl = $this->context->link->getModuleLink($this->name, 'callback', array(), true);
        $output .= '<div class="alert alert-info">'.$this->l('Callback URL:').' <code>'.htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8').'</code></div>';

        return $output.$this->renderForm();
    }

    public function applySwishPayment($idOrder, $reference, $payerName)
    {
        $result = array(
            'ok' => false,
            'module_version' => $this->version,
        );

        $order = new Order((int)$idOrder);
        if (!Validate::isLoadedObject($order)) {
            return array('ok' => false, 'error' => 'order_not_found');
        }
        if ((string)$order->reference !== (string)$reference) {
            return array('ok' => false, 'error' => 'reference_mismatch');
        }

        $payerResult = array('ok' => true, 'step' => 'empty');
        $payerName = trim((string)$payerName);
        if ($payerName !== '') {
            try {
                $payerResult = $this->storeSwishPayerName((int)$order->id, $payerName);
            } catch (Exception $e) {
                return array_merge($result, array(
                    'error' => 'payer_exception',
                    'message' => $e->getMessage(),
                ));
            }
        }

        $paidStateId = (int)Configuration::get(self::CFG_PAID_STATE);
        $deliveredStateId = (int)Configuration::get(self::CFG_DELIVERED_STATE);
        if ($paidStateId <= 0 || $deliveredStateId <= 0) {
            return array('ok' => false, 'error' => 'missing_status_configuration');
        }

        try {
            $payment = $this->changeOrderState($order, $paidStateId, false);
        } catch (Exception $e) {
            return array_merge($result, array(
                'error' => 'payment_exception',
                'message' => $e->getMessage(),
                'payer' => $payerResult,
            ));
        }

        $order = new Order((int)$idOrder);
        try {
            $delivered = $this->changeOrderState($order, $deliveredStateId, true);
        } catch (Exception $e) {
            return array_merge($result, array(
                'error' => 'delivered_exception',
                'message' => $e->getMessage(),
                'payer' => $payerResult,
                'payment' => $payment,
            ));
        }

        return array(
            'ok' => !empty($payment['ok']) && !empty($delivered['ok']),
            'module_version' => $this->version,
            'payer' => $payerResult,
            'payment' => $payment,
            'delivered' => $delivered,
        );
    }

    public function isValidCallbackKey($key)
    {
        $keys = array(
            (string)Configuration::get(self::CFG_CALLBACK_KEY),
            (string)Configuration::get('SMS_QUEUE_KEY'),
        );

        foreach ($keys as $configuredKey) {
            $configuredKey = trim($configuredKey);
            if ($configuredKey === '') {
                continue;
            }
            if (function_exists('hash_equals') && hash_equals($configuredKey, (string)$key)) {
                return true;
            }
            if (!function_exists('hash_equals') && $configuredKey === (string)$key) {
                return true;
            }
        }

        return false;
    }

    private function changeOrderState(Order $order, $stateId, $valid)
    {
        if (!Validate::isLoadedObject($order)) {
            return array('ok' => false, 'error' => 'order_not_loaded', 'status_id' => (int)$stateId);
        }
        if ((int)$order->current_state === (int)$stateId) {
            return array('ok' => true, 'step' => 'skip_current_state', 'status_id' => (int)$stateId);
        }

        $history = new OrderHistory();
        $history->id_order = (int)$order->id;
        $history->changeIdOrderState((int)$stateId, $order, true);
        $ok = (bool)$history->addWithemail(true);

        if ($valid) {
            Db::getInstance()->execute(
                'UPDATE `'._DB_PREFIX_.'orders`
                SET `valid` = 1,
                    `total_paid_real` = `total_paid_tax_incl`
                WHERE `id_order` = '.(int)$order->id
            );
        }

        return array('ok' => $ok, 'step' => 'order_history', 'status_id' => (int)$stateId);
    }

    private function storeSwishPayerName($idOrder, $payerName)
    {
        $payerLine = 'Swishnamn: '.$payerName;
        $messages = Db::getInstance()->executeS('SELECT `id_message`, `message` FROM `'._DB_PREFIX_.'message` WHERE `id_order` = '.(int)$idOrder.' ORDER BY `id_message` DESC');

        foreach ((array)$messages as $row) {
            $message = (string)$row['message'];
            if (preg_match('/^Swishnamn:\s*[^\r\n]*/mi', $message)) {
                $message = preg_replace('/^Swishnamn:\s*[^\r\n]*/mi', $payerLine, $message, 1);
                $ok = Db::getInstance()->execute(
                    'UPDATE `'._DB_PREFIX_.'message`
                    SET `message` = "'.pSQL($message, true).'"
                    WHERE `id_message` = '.(int)$row['id_message']
                );
                return array('ok' => (bool)$ok, 'step' => 'update_message');
            }
        }

        $message = new Message();
        $message->id_order = (int)$idOrder;
        $message->private = 1;
        $message->message = $payerLine;
        return array('ok' => (bool)$message->add(), 'step' => 'add_message');
    }

    private function renderForm()
    {
        $fieldsForm = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array('type' => 'text', 'label' => $this->l('Callback key'), 'name' => self::CFG_CALLBACK_KEY, 'desc' => $this->l('Shared secret from smsqueue.php. If empty, SMS_QUEUE_KEY is accepted.')),
                    array('type' => 'text', 'label' => $this->l('Payment accepted status id'), 'name' => self::CFG_PAID_STATE),
                    array('type' => 'text', 'label' => $this->l('Delivered status id'), 'name' => self::CFG_DELIVERED_STATE),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitGothemSwishStatus';
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->fields_value[self::CFG_CALLBACK_KEY] = Tools::getValue(self::CFG_CALLBACK_KEY, Configuration::get(self::CFG_CALLBACK_KEY));
        $helper->fields_value[self::CFG_PAID_STATE] = Tools::getValue(self::CFG_PAID_STATE, Configuration::get(self::CFG_PAID_STATE));
        $helper->fields_value[self::CFG_DELIVERED_STATE] = Tools::getValue(self::CFG_DELIVERED_STATE, Configuration::get(self::CFG_DELIVERED_STATE));

        return $helper->generateForm(array($fieldsForm));
    }
}
