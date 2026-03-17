<?php
/**
 * Copyright (c) 2019-2026 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package  Mastercard
 * @version  GIT: @1.4.6@
 * @link     https://github.com/fingent-corp/gateway-prestashop-mastercard-module
 */

if (!defined('_PS_VERSION_')) {
    throw new MasterCardPaymentException('Direct access not allowed.');
}

abstract class MastercardAbstractModuleFrontController extends ModuleFrontController
{
    const PAYMENT_DECLINED_ERROR = 'Your payment was declined.';
    const PARAM_3DSECURE_ID = '3DSecureId';
    const PARAM_PROCESS_ACS = 'process_acs_result';
    const PARAM_CHECK_3DS  = 'check_3ds_enrollment';
    const KEY_3DSECURE = '3DSecure';

    /**
     * @var GatewayService
     */
    public $client;

    /**
     * @var Mastercard
     */
    public $module;

    /**
     * @var array
     */
    public $threeDSecureData;

    /**
     * @var string
     */
    public $threeDSecureId;

    /**
     * @throws PrestaShopException
     * @throws Exception
     */
    public function init()
    {
        parent::init();

        if (Context::getContext()->cart->id_customer == 0 ||
            Context::getContext()->cart->id_address_delivery == 0 ||
            Context::getContext()->cart->id_address_invoice == 0 ||
            !$this->module->active
        ) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer(Context::getContext()->cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $this->client = new GatewayService(
            $this->module->getApiEndpoint(),
            $this->module->getApiVersion(),
            $this->module->getConfigValue('mg_merchant_id'),
            $this->module->getConfigValue('mg_api_password'),
            $this->module->getWebhookUrl()
        );
    }

    /**
     * If this method returns false, then execution is allowed on the child class level
     * otherwise child classes must return and not process the request
     *
     * @return bool
     * @throws PrestaShopException
     * @throws \Http\Client\Exception
     * @throws Exception
     */
    public function isPostProcess()
    {
        parent::postProcess();

        return $this->handle3dsFlow();
    }

    private function handle3dsFlow()
    {
        $result = false;

        if ($this->isProcessingAcsResult()) {
            $result = $this->handleAcsResult();
        } elseif ($this->isUpdating3dsSession()) {
            $result = $this->handle3dsSessionUpdate();
        } elseif ($this->isChecking3dsEnrollment()) {
            $result = $this->handle3dsEnrollment();
        } else{
            $result = false;
        }

        return $result;
    }

    private function isProcessingAcsResult(): bool
    {
        return Tools::getValue(self::PARAM_PROCESS_ACS) === '1';
    }

    private function isUpdating3dsSession(): bool
    {
        return Tools::getValue(self::PARAM_CHECK_3DS) === '2'
            && Tools::getValue('action_type') === 'update_session';
    }

    private function isChecking3dsEnrollment(): bool
    {
        return Tools::getValue(self::PARAM_CHECK_3DS) === '1';
    }

    private function handleAcsResult(): bool
    {
        $paRes = Tools::getValue('PaRes');
        $threeDSecureId = Tools::getValue(self::PARAM_3DSECURE_ID);

        if (!$paRes || !$threeDSecureId) {
            $this->addPaymentError();
            return $this->redirectToOrder();
        }

        $response = $this->client->process3dsResult($threeDSecureId, $paRes);

        if ($response['response']['gatewayRecommendation'] !== 'PROCEED') {
            $this->addPaymentError(
                $this->module->l('Your payment was declined by 3D Secure.', 'abstract')
            );
            return $this->redirectToOrder();
        }

        $this->threeDSecureData = [
            'acsEci'              => $response[self::KEY_3DSECURE]['acsEci'],
            'authenticationToken' => $response[self::KEY_3DSECURE]['authenticationToken'],
            'paResStatus'         => $response[self::KEY_3DSECURE]['paResStatus'],
            'veResEnrolled'       => $response[self::KEY_3DSECURE]['veResEnrolled'],
            'xid'                 => $response[self::KEY_3DSECURE]['xid'],
        ];

        return false;
    }

    private function handle3dsSessionUpdate(): bool
    {
        $currency = $this->context->currency;

        $order = [
            'currency' => $currency->iso_code,
            'amount'   => $this->context->cart->getOrderTotal(),
        ];

        $orderId   = Tools::getValue('order_id');
        $sessionId = Tools::getValue('session_id');

        $responseUrl = $this->context->link->getModuleLink(
            'mastercard',
            'threedsresponse',
            [
                'session_id'           => $sessionId,
                'action_type'          => 'completed',
                'check_3ds_enrollment' => '2',
            ],
            true
        );

        $response = $this->client->updateSession(
            $orderId,
            $sessionId,
            $order,
            [
                'channel'             => 'PAYER_BROWSER',
                'redirectResponseUrl' => $responseUrl,
            ],
            ['id' => uniqid(sprintf('3DS-%s-', $orderId))]
        );

        header('Content-Type: application/json');
        echo json_encode([
            'session'     => $response['session'] ?? [],
            'order'       => $response['order'] ?? [],
            'transaction' => $response['transaction'] ?? [],
            'version'     => $response['version'] ?? [],
        ]);

        return true;
    }

    private function handle3dsEnrollment(): bool
    {
        $this->module->getNewOrderRef();

        $response = $this->client->check3dsEnrollment(
            [
                'authenticationRedirect' => [
                    'pageGenerationMode' => 'CUSTOMIZED',
                    'responseUrl' => $this->context->link->getModuleLink(
                        $this->module->name,
                        Tools::getValue('controller'),
                        [],
                        true
                    ),
                ],
            ],
            [
                'amount'   => $this->context->cart->getOrderTotal(),
                'currency' => $this->context->currency->iso_code,
            ],
            ['id' => Tools::getValue('session_id')]
        );

        if ($response['response']['gatewayRecommendation'] !== 'PROCEED') {
            $this->addPaymentError();
            return $this->redirectToOrder();
        }

        if (!isset($response[self::KEY_3DSECURE]['authenticationRedirect'])) {
            return false;
        }

        $this->context->smarty->assign([
            'authenticationRedirect' =>
                $response[self::KEY_3DSECURE]['authenticationRedirect']['customized'],
            'returnUrl' => $this->context->link->getModuleLink(
                $this->module->name,
                Tools::getValue('controller'),
                [
                    self::PARAM_3DSECURE_ID => $response[self::PARAM_3DSECURE_ID],
                    self::PARAM_PROCESS_ACS => '1',
                    'session_id' => Tools::getValue('session_id'),
                    'session_version' => Tools::getValue('session_version'),
                ],
                true
            ),
        ]);

        $this->setTemplate('module:mastercard/views/templates/front/threedsecure/form.tpl');
        return true;
    }

    private function redirectToOrder(): bool
    {
        $this->redirectWithNotifications(
            $this->context->link->getPageLink('order', true, null, ['action' => 'show'])
        );
        return true;
    }

    private function addPaymentError(string $message = null): void
    {
        $this->errors[] = $message
            ?: $this->module->l('Payment error occurred (3D Secure).', 'abstract');
    }


    /**
     * @param AddressCore $address
     *
     * @return array
     */
    public function getAddressForGateway($address)
    {
        /** @var CountryCore $country */
        $country = new Country($address->id_country);

        return array(
            'city'        => GatewayService::safe($address->city, 100),
            'country'     => $this->module->iso2ToIso3($country->iso_code),
            'postcodeZip' => GatewayService::safe($address->postcode, 10),
            'street'      => GatewayService::safe($address->address1, 100),
            'street2'     => GatewayService::safe($address->address2, 100),
            'company'     => GatewayService::safe($address->company, 100),
        );
    }

    /**
     * @param CustomerCore|AddressCore $customer
     *
     * @return array
     */
    public function getContactForGateway($customer)
    {
        return array(
            'firstName'   => GatewayService::safe($customer->firstname, 50),
            'lastName'    => GatewayService::safe($customer->lastname, 50),
            'email'       => GatewayService::safeProperty($customer, 'email'),
            'mobilePhone' => GatewayService::safeProperty($customer, 'phone_mobile'),
            'phone'       => GatewayService::safeProperty($customer, 'phone'),
        );
    }

    /**
     * @return float
     */
    protected function getDeltaAmount()
    {
        if (!Configuration::get('mg_lineitems_enabled')) {
            return 0.00;
        }

        $total = Context::getContext()->cart->getOrderTotal();

        $precision = $this->getCurrencyPrecision();
        $cents = pow(10, $precision);
        $delta = round(($this->module->getItemAmount() * $cents) - ($total * $cents));
        $deltaAmount = $delta / $cents;

        return max($deltaAmount, 0.00);
    }

    /**
     * Retrieves the value of the Current Currency Decimals (precision on the data level)
     *
     * @return int
     */
    protected function getCurrencyPrecision()
    {
        $defaultValue = 2;
        $currency = Context::getContext()->currency;
        if (!$currency) {
            return $defaultValue;
        }

        $precision = $currency->precision;
        if (!$precision || $precision <= 0) {
            return $defaultValue;
        }

        return (int)$precision;
    }
}
