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
namespace Fingent\Mastercard\Handlers;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Fingent\Mastercard\Handlers\MasterCardPaymentException;

abstract class ResponseHandler
{
    /**
     * @var ResponseProcessor
     */
    protected $processor;

    /**
     * @param Order $order
     * @param array $response
     *
     * @throws MasterCardPaymentException
     */
    abstract public function handle($order, $response);


    /**
     * @param ResponseProcessor $processor
     *
     * @return $this
     */
    public function setProcessor($processor)
    {
        $this->processor = $processor;

        return $this;
    }

    /**
     * @param $response
     *
     * @return bool
     */
    protected function isApproved($response)
    {
        $gatewayCode = $response['response']['gatewayCode'];

        if (!in_array($gatewayCode, array('APPROVED', 'APPROVED_AUTO'))) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    protected function hasExceptions()
    {
        return !empty($this->processor->exceptions);
    }

    /**
     * @param Order $order
     * @param float $amountPaid
     * @param string $paymentTransactionId
     * @param array $txn
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * // This is currently almost identical to Order->addOrderPayment()
     *
     */
    public function isAddOrderPayment($order, $amountPaid, $paymentTransactionId = null, $txn = array())
    {
        $orderPayment = new \OrderPayment();
        $orderPayment->order_reference = $order->reference;
        $orderPayment->id_currency = $order->id_currency;
        $orderPayment->conversion_rate = 1;
        $orderPayment->payment_method = $order->payment;
        $orderPayment->transaction_id = $paymentTransactionId;
        $orderPayment->amount = $amountPaid;
        $orderPayment->date_add = null;

        // --- Get customer name ---
        [$firstName, $lastName] = $this->extractCustomerName($txn);

        // --- Determine card brand ---
        $sourceOfFunds = $txn['sourceOfFunds'] ?? [];
        $cardBrand = $this->resolveCardBrand($sourceOfFunds);

        // --- Set card details ---
        $this->assignCardDetails($orderPayment, $txn, $cardBrand, $firstName, $lastName);

        // Add time to the date if needed
        if ($orderPayment->date_add != null && preg_match('/^\d+-\d+-\d+$/', $orderPayment->date_add)) {
            $orderPayment->date_add .= ' ' . date('H:i:s');
        }

        // Update total_paid_real value for backward compatibility reasons
        if ($orderPayment->id_currency == $order->id_currency) {
            $order->total_paid_real += $orderPayment->amount;
        } else {
            $order->total_paid_real += \Tools::ps_round(Tools::convertPrice($orderPayment->amount,
                $orderPayment->id_currency, false), 2);
        }

        // We put autodate parameter of add method to true if date_add field is null
        $result = $orderPayment->add(is_null($orderPayment->date_add)) && $order->update();

        if (!$result) {
            return false;
        }

        return $result;
    }

    /**
     * Extracts customer first and last name from transaction data.
     */
    private function extractCustomerName($txn)
    {
        if (!empty($txn['customer']['firstName']) || !empty($txn['customer']['lastName'])) {
            return [$txn['customer']['firstName'] ?? '', $txn['customer']['lastName'] ?? ''];
        }

        if (!empty($txn['sourceOfFunds']['provided']['paypal']['accountHolder'])) {
            $fullName = $txn['sourceOfFunds']['provided']['paypal']['accountHolder'];
            return array_pad(explode(' ', $fullName, 2), 2, '');
        }

        return ['', ''];
    }

    /**
     * Determines the card brand from sourceOfFunds.
     */
    private function resolveCardBrand($sourceOfFunds)
    {
        $browserPayment = $sourceOfFunds['browserPayment']['type'] ?? null;
        $type = strtoupper($sourceOfFunds['type'] ?? '');
        $browserPayments = ['KNET', 'QPAY', 'BENEFIT', 'OMAN NET'];

        if (in_array($browserPayment, $browserPayments, true)) {
            return $browserPayment;
        }

        if ($type === 'PAYPAL') {
            return 'PAYPAL';
        }

        return '';
    }

    /**
     * Assigns card details to the OrderPayment object.
     */
    private function assignCardDetails(&$orderPayment, $txn, $cardBrand, $firstName, $lastName)
    {
        $sourceOfFunds = $txn['sourceOfFunds'] ?? [];
        $provided = $sourceOfFunds['provided'] ?? [];

        if (isset($provided['card'])) {
            $card = $provided['card'];
            $orderPayment->card_number = $card['number'] ?? null;
            $orderPayment->card_expiration = ($card['expiry']['month'] ?? '') . '/' . ($card['expiry']['year'] ?? '');
            $orderPayment->card_brand = $card['brand'] ?? ($sourceOfFunds['type'] ?? null);
            $orderPayment->card_holder = $card['nameOnCard'] ?? null;
            return;
        }

        if (isset($sourceOfFunds['type'])) {
            $orderPayment->card_number = null;
            $orderPayment->card_expiration = null;
            $orderPayment->card_brand = $cardBrand ?? null;
            $orderPayment->card_holder = trim($firstName . ' ' . $lastName);
            return;
        }

        throw new MasterCardPaymentException('Unknown transaction type');
    }

}
