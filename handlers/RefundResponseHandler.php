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
use Fingent\Mastercard\Model\MpgsRefund;
use Fingent\Mastercard\Handlers\MasterCardPaymentException;

class RefundResponseHandler extends TransactionResponseHandler
{
    /**
     * @inheritdoc
     */
    public function handle($order, $response)
    {
        try {
            parent::handle($order, $response);
        } catch (MasterCardPaymentException $e) {
            $this->processor->logger->warning($e->getMessage());

            return;
        }

        $refund = new MpgsRefund();
        $refund->order_id       = $order->id;
        $refund->total          = $response['transaction']['amount'];
        $refund->transaction_id = $response['transaction']['id'];
        $refund->add();
    }
}
