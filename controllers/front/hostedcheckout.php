<?php
/**
 * Copyright (c) 2019-2026 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package  Mastercard
 * @version  GIT: @1.4.5@
 * @link     https://github.com/fingent-corp/gateway-prestashop-mastercard-module
 */

use Fingent\Mastercard\Controllers\front\MastercardAbstractModuleFrontController;
use Fingent\Mastercard\Gateway\GatewayService;
use Fingent\Mastercard\Handlers\ResponseProcessor;
use Fingent\Mastercard\Handlers\RiskResponseHandler;
use Fingent\Mastercard\Handlers\OrderPaymentResponseHandler;
use Fingent\Mastercard\Handlers\OrderStatusResponseHandler;

class MastercardHostedCheckoutModuleFrontController extends MastercardAbstractModuleFrontController
{
    const URL ='index.php?controller=order&step=1';
    /**
     * @throws GatewayResponseException
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws \Http\Client\Exception
     * @throws Exception
     */
    protected function createSessionAndRedirect()
    {
        $orderId     = $this->module->getNewOrderRef();
        $deltaAmount = $this->getDeltaAmount();
        
        $order = array(
            'id'        => $orderId,
            'reference' => $orderId,
            'currency'  => Context::getContext()->currency->iso_code,
            'amount'    => GatewayService::numeric(
                Context::getContext()->cart->getOrderTotal()
            ),
            'item'       => $this->module->getOrderItems($deltaAmount),
            'itemAmount' => $this->module->getItemAmount($deltaAmount),
        );

        // get the shipping value
        $shippingAmount = (float) $this->module->getShippingHandlingAmount($deltaAmount);

        // include only if > 0
        if ($shippingAmount > 0) {
            $order['shippingAndHandlingAmount'] = GatewayService::numeric($shippingAmount);
        }

        $interaction = $this->getInteraction();

        /** @var ContextCore $context */
        $context = \Context::getContext();

        /** @var CartCore $cart */
        $cart = $context->cart;

        /** @var AddressCore $billingAddress */
        $billingAddress = new Address($cart->id_address_invoice);

        /** @var AddressCore $shippingAddress */
        $shippingAddress = new Address($cart->id_address_delivery);

        /** @var CustomerCore $customer */
        $customer = Context::getContext()->customer;

        $response = $this->client->createCheckoutSession(
            $order,
            $interaction,
            $this->getContactForGateway($customer),
            $this->getAddressForGateway($billingAddress),
            $this->getAddressForGateway($shippingAddress),
            $this->getContactForGateway($shippingAddress),
        );

        $responseData = array(
            'session_id' => $response['session']['id'],
            'session_version' => $response['session']['version'],
            'success_indicator' => $response['successIndicator'],
        );

        if (ControllerCore::isXmlHttpRequest()) {
            header('Content-Type: application/json');
            echo json_encode($responseData);
            return;
        }

        Tools::redirect(
            Context::getContext()->link->getModuleLink('mastercard', 'hostedcheckout', $responseData)
        );
    }

    public function getInteraction( $capture = true, $returnUrl = null ) { // phpcs:ignore
        $merchantInteraction = array();

       if (GatewayService::safe(Configuration::get('mpgs_mi_active')) === '1' && Configuration::get('mpgs_hc_payment_method') === 'REDIRECT'
            )
        {

            $merchantName   = GatewayService::safe(Configuration::get('mpgs_mi_merchant_name'));
            $sitename       = GatewayService::safe(Configuration::get('PS_SHOP_NAME'));
            $merchantName   = $merchantName ? preg_replace( "/['\"]/", '', $merchantName ) : $sitename;

            $merchant       = array(
                'name'    => $merchantName,
                'url'     => GatewayService::safe(Configuration::get('mpgs_api_url_custom')),
                'address' => array(
                    'line1'    => GatewayService::safe(Configuration::get('mpgs_mi_address_line1')),
                    'line2'    => GatewayService::safe(Configuration::get('mpgs_mi_address_line2')),
                    'line3'    => GatewayService::safe(Configuration::get('mpgs_mi_postalcode')),
                    'line4'    => GatewayService::safe(Configuration::get('mpgs_mi_country')),
                )
            );

            if( GatewayService::safe(Configuration::get('mpgs_mi_email') ) ) {
                $merchant['email'] = Configuration::get('mpgs_mi_email');
            }

            if(GatewayService::safe(Configuration::get('mpgs_mi_logo') ) ) {
                $merchant['logo'] = Configuration::get('mpgs_mi_logo');
            }

            if( GatewayService::safe(Configuration::get('mpgs_mi_phone') ) ) {
                $merchant['phone'] = Configuration::get('mpgs_mi_phone');
            }

            $merchantInteraction['merchant'] = $merchant;
        } else {
            $sitename = GatewayService::safe(Configuration::get('PS_SHOP_NAME'));
            $merchantInteraction['merchant']['name'] = $sitename;
            $merchantInteraction['merchant']['url']  = GatewayService::safe(Configuration::get('mpgs_api_url_custom'));
        }

        return array_merge(
            $merchantInteraction,
            array(
                'returnUrl'      => $returnUrl,
                'displayControl' => array(
                    'customerEmail'  => 'HIDE',
                    'billingAddress' => 'HIDE',
                    'paymentTerms'   => 'HIDE',
                    'shipping'       => 'HIDE',
                ),
                'operation'      => Configuration::get('mpgs_hc_payment_action'),
            )
        );

    }

    /**
     * @throws PrestaShopException
     * @throws Exception
     */
    protected function showPaymentPage()
    {
        $this->context->smarty->assign(array(
            'mpgs_config' => array(
                'session_id'        => Tools::getValue('session_id'),
                'session_version'   => Tools::getValue('session_version'),
                'success_indicator' => Tools::getValue('success_indicator'),
                'merchant_id'       => $this->module->getConfigValue('mpgs_merchant_id'),
                'order_id'          => $this->module->getNewOrderRef(),
                'amount'            => Context::getContext()->cart->getOrderTotal(),
                'currency'          => Context::getContext()->currency->iso_code
            ),
            'hostedcheckout_component_url' => $this->module->getHostedCheckoutJsComponent(),
        ));
        $this->setTemplate('module:mastercard/views/templates/front/methods/hostedcheckout/js.tpl');
    }

    /**
     * @throws \Http\Client\Exception
     * @throws PrestaShopException
     * @throws Exception
     */
    protected function createOrderAndRedirect()
    {
        $oldOrderId = Tools::getValue('order_id');
        $cart       = Context::getContext()->cart;
        $currency   = Context::getContext()->currency;
        $orderId    = $this->module->getNewOrderRef();

        if ($oldOrderId !== $orderId) {
            $this->errors[] = $this->module->l('Invalid data (order)', 'hostedcheckout');
            $this->redirectWithNotifications(self::URL);
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->errors[] = $this->module->l('Invalid data (customer)', 'hostedcheckout');
            $this->redirectWithNotifications(self::URL);
        }

        $response = $this->client->retrieveOrder($orderId);

        $this->module->validateOrder(
            (int)$cart->id,
            Configuration::get('MPGS_OS_PAYMENT_WAITING'),
            $response['amount'],
            MasterCard::PAYMENT_CODE,
            null,
            array(),
            $currency->id,
            false,
            $customer->secure_key
        );

        $order     = new Order((int)$this->module->currentOrder);
        $processor = new ResponseProcessor($this->module);
        
        try {
            $processor->handle($order, $response, array(
                new RiskResponseHandler(),
                new OrderPaymentResponseHandler(),
                new OrderStatusResponseHandler(),
            ));
        } catch (Exception $e) {
            $this->errors[] = $this->module->l('Payment Error', 'hostedcheckout');
            $this->errors[] = $e->getMessage();
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }

        Tools::redirect(
            'index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key
        );
    }

    /**
     * @throws GatewayResponseException
     * @throws \Http\Client\Exception
     * @throws Exception
     */
    public function postProcess()
    {
        if (parent::postProcess()) {
            return;
        }

        if (Tools::getValue('cancel')) {
            $this->warning[] = $this->module->l('Payment was cancelled.', 'hostedcheckout');
            $this->redirectWithNotifications(Context::getContext()->link->getPageLink('cart', null, null, array(
                'action' => 'show'
            )));
            return;
        }

        if (!Tools::getValue('order_id')) {
            if (!Tools::getValue('success_indicator')) {
                $this->createSessionAndRedirect();
            } else {
                $this->showPaymentPage();
            }
        } else {
            $this->createOrderAndRedirect();
        }
    }
}
