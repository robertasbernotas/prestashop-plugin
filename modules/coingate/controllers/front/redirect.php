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

class CoingateRedirectModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;

        if (!$this->module->checkCurrency($cart)) {
            Tools::redirect('index.php?controller=order');
        }

        $total = (float)number_format($cart->getOrderTotal(true, 3), 2, '.', '');
        $currency = Context::getContext()->currency;

        $token = $this->generateToken($cart->id);

        $description = array();
        foreach ($cart->getProducts() as $product) {
            $description[] = $product['cart_quantity'] . ' × ' . $product['name'];
        }

        $customer = new Customer($cart->id_customer);

        $link = new Link();
        $success_url = $link->getPageLink('order-confirmation', null, null, array(
          'id_cart'     => $cart->id,
          'id_module'   => $this->module->id,
          'key'         => $customer->secure_key
        ));

        $auth_token = Configuration::get('COINGATE_API_AUTH_TOKEN');
        $auth_token = empty($auth_token) ? Configuration::get('COINGATE_API_SECRET') : $auth_token;

        $cgConfig = array(
          'auth_token' => $auth_token,
          'environment' => (int)(Configuration::get('COINGATE_TEST')) == 1 ? 'sandbox' : 'live',
          'user_agent' => 'CoinGate - Prestashop v'._PS_VERSION_.' Extension v'.COINGATE_PRESTASHOP_EXTENSION_VERSION
        );

        \CoinGate\CoinGate::config($cgConfig);

        $order = \CoinGate\Merchant\Order::create(array(
            'order_id'         => $cart->id,
            'price_amount'     => $total,
            'price_currency'   => $currency->iso_code,
            'receive_currency' => Configuration::get('COINGATE_RECEIVE_CURRENCY'),
            'cancel_url'       => $this->context->link->getModuleLink('coingate', 'cancel'),
            'callback_url'     => $this->context->link->getModuleLink('coingate', 'callback'),
            'success_url'      => $success_url,
            'title'            => Configuration::get('PS_SHOP_NAME') . ' Order #' . $cart->id,
            'description'      => join(', ', $description),
            'token'            => $token
        ));

        if ($order) {
            if (!$order->payment_url) {
                Tools::redirect('index.php?controller=order&step=3');
            }

            $customer = new Customer($cart->id_customer);
            $this->module->validateOrder(
                $cart->id,
                Configuration::get('COINGATE_PENDING'),
                $total,
                $this->module->displayName,
                null,
                null,
                (int)$currency->id,
                false,
                $customer->secure_key
            );

            Tools::redirect($order->payment_url);
        } else {
            Tools::redirect('index.php?controller=order&step=3');
        }
    }

    private function generateToken($order_id)
    {
        return hash('sha256', $order_id . (empty(Configuration::get('COINGATE_API_AUTH_TOKEN')) ?
                Configuration::get('API_SECRET') :
                Configuration::get('COINGATE_API_AUTH_TOKEN')
        ));
    }
}
