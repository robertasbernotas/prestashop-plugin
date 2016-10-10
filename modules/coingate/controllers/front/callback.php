<?php
/**
* NOTICE OF LICENSE
*
* The MIT License (MIT)
*
* Copyright (c) 2015-2016 CoinGate
*
* Permission is hereby granted, free of charge, to any person obtaining a copy of
* this software and associated documentation files (the "Software"), to deal in
* the Software without restriction, including without limitation the rights to use,
* copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software,
* and to permit persons to whom the Software is furnished to do so, subject
* to the following conditions:
*
* The above copyright notice and this permission notice shall be included in all
* copies or substantial portions of the Software.
*
* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
* IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
* FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
* AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
* WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR
* IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*
*  @author    CoinGate <info@coingate.com>
*  @copyright 2015-2016 CoinGate
*  @license   https://github.com/coingate/prestashop-plugin/blob/master/LICENSE  The MIT License (MIT)
*/

require_once(_PS_MODULE_DIR_ . '/coingate/vendor/coingate/init.php');
require_once(_PS_MODULE_DIR_ . '/coingate/vendor/version.php');

class CoingateCallbackModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $order_id = Order::getOrderByCartId(Tools::getValue('order_id'));
        $order = new Order($order_id);

        try {
            if (!$order) {
                throw new Exception('Order #' . Tools::getValue('order_id') . ' does not exists');
            }

            $token = $this->generateToken(Tools::getValue('order_id'));

            if ($token == '' || Tools::getValue('cg_token') != $token) {
                throw new Exception('Token: ' . Tools::getValue('cg_token') . ' do not match');
            }

            $cgConfig = array(
              'app_id' => Configuration::get('COINGATE_APP_ID'),
              'api_key' => Configuration::get('COINGATE_API_KEY'),
              'api_secret' => Configuration::get('COINGATE_API_SECRET'),
              'environment' => (int)(Configuration::get('COINGATE_TEST')) == 1 ? 'sandbox' : 'live',
              'user_agent' => 'CoinGate - Prestashop v'._PS_VERSION_
                .' Extension v'.COINGATE_PRESTASHOP_EXTENSION_VERSION
            );

            \CoinGate\CoinGate::config($cgConfig);
            $cgOrder = \CoinGate\Merchant\Order::find(Tools::getValue('id'));

            if (!$cgOrder) {
                throw new Exception('CoinGate Order #' . Tools::getValue('id') . ' does not exists');
            }

            if (((float) $order->getOrdersTotalPaid()) > ((float) $cgOrder->price)) {
                $order_status = 'COINGATE_INVALID';
            } else {
                switch ($cgOrder->status) {
                    case 'paid':
                        $order_status = 'PS_OS_PAYMENT';
                        break;
                    case 'expired':
                        $order_status = 'COINGATE_EXPIRED';
                        break;
                    case 'invalid':
                        $order_status = 'COINGATE_INVALID';
                        break;
                    case 'canceled':
                        $order_status = 'PS_OS_CANCELED';
                        break;
                    case 'refunded':
                        $order_status = 'PS_OS_REFUND';
                        break;
                    default:
                        $order_status = false;
                        break;
                }
            }

            if ($order_status !== false) {
                $history = new OrderHistory();
                $history->id_order = $order->id;
                $history->changeIdOrderState((int)Configuration::get($order_status), $order->id);
                $history->addWithemail(true, array(
                    'order_name' => Tools::getValue('order_id'),
                ));

                $this->context->smarty->assign(array(
                    'text' => 'OK'
                ));
            } else {
                $this->context->smarty->assign(array(
                    'text' => 'Order Status '.$cgOrder->status.' not implemented'
                ));
            }
        } catch (Exception $e) {
            $this->context->smarty->assign(array(
                'text' => get_class($e) . ': ' . $e->getMessage()
            ));
        }

        $this->setTemplate('payment_callback.tpl');

        die('OK');
    }

    private function generateToken($order_id)
    {
        return hash('sha256', $order_id + $this->module->api_secret);
    }
}
