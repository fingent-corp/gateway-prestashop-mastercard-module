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

use Fingent\Mastercard\Gateway\GatewayService;
use Fingent\Mastercard\Handlers\ResponseProcessor;
use Fingent\Mastercard\Handlers\CaptureResponseHandler;
use Fingent\Mastercard\Handlers\TransactionStatusResponseHandler;
use Fingent\Mastercard\Handlers\TransactionResponseHandler;
use Fingent\Mastercard\Handlers\VoidResponseHandler;
use Fingent\Mastercard\Handlers\RefundResponseHandler;
use Fingent\Mastercard\Handlers\MasterCardPaymentException;
use Fingent\Mastercard\Service\MpgsRefundService;

class AdminMpgsController extends \ModuleAdminController
{
    const AUTHORIZE_NOT_FOUND = 'Authorization transaction not found.';

    /**
     * @var GatewayService
     */
    protected $client;

    /**
     * @var Mastercard
     */
    public $module;

    /**
     * @return bool|ObjectModel|void
     * @throws Exception
     */
    public function postProcess()
    {
        $action     = Tools::getValue('action');
        $actionName = $action.'Action';

        $this->client = new GatewayService(
            $this->module->getApiEndpoint(),
            $this->module->getApiVersion(),
            $this->module->getConfigValue('mpgs_merchant_id'),
            $this->module->getConfigValue('mpgs_api_password'),
            $this->module->getWebhookUrl()
        );

        $orderId = Tools::getValue('id_order');
        $order   = new Order($orderId);

        try {
            $this->{$actionName}($order);
            $redirectLink = $this->getSuccessOrderLink((int)$order->id);
            Tools::redirectAdmin($redirectLink);
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage() . ' (' . $e->getCode() . ')';
        }

        return parent::postProcess();
    }

    /**
     * @param Order $order
     *
     * @throws MasterCardPaymentException
     * @throws \Http\Client\Exception
     * @throws Exception
     */
    protected function voidAction($order)
    {
        $txnData = $this->client->getAuthorizationTransaction($this->module->getOrderRef($order));
        $txn     = $this->module->getTransactionById($order, $txnData['transaction']['id']);
        if (!$txn) {
            throw new MasterCardPaymentException(self::AUTHORIZE_NOT_FOUND);
        }

        $response  = $this->client->voidTxn($this->module->getOrderRef($order), $txn->transaction_id);

        $processor = new ResponseProcessor($this->module);
        $processor->handle($order, $response, array(
            new TransactionStatusResponseHandler(),
            new VoidResponseHandler(),
        ));
    }

    /**
     * @param Order $order
     *
     * @throws MasterCardPaymentException
     * @throws PrestaShopException
     * @throws \Http\Client\Exception
     * @throws Exception
     */
    protected function captureAction($order)
    {
        $txnData = $this->client->getAuthorizationTransaction($this->module->getOrderRef($order));
        $txn     = $this->module->getTransactionById($order, $txnData['transaction']['id']);

        if (!$txn) {
            throw new MasterCardPaymentException(self::AUTHORIZE_NOT_FOUND);
        }

        $currency = Currency::getCurrency($txn->id_currency);

        $response = $this->client->captureTxn(
            $this->module->getOrderRef($order),
            $txn->amount,
            $currency['iso_code']
        );

        $processor = new ResponseProcessor($this->module);
        $processor->handle($order, $response, array(
            new CaptureResponseHandler(),
            new TransactionStatusResponseHandler(),
        ));

        $txn->delete();
    }

    /**
     * @param Order $order
     *
     * @throws MasterCardPaymentException
     * @throws \Http\Client\Exception
     * @throws Exception
     */
    protected function refundAction($order)
    {
        $refundService = new MpgsRefundService($this->module);
        $refundService->execute($order, array(
            new TransactionResponseHandler(),
            new TransactionStatusResponseHandler(),
            new RefundResponseHandler(),
        ));
    }

    /**
     * @param int $orderId
     *
     * @return string
     */
    private function getSuccessOrderLink($orderId)
    {
        $link = $this->context->link;

        return sprintf(
            "%s&conf=4&id_order=%d&vieworder",
            $link->getAdminLink('AdminOrders', true, ['id_order' => $orderId, 'vieworder' => 1], []),
            $orderId
        );
    }
}
