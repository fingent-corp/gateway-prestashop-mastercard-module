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
use Configuration;
use OrderHistory;

class OrderStatusResponseHandler extends ResponseHandler
{
    /**
     * @param Order $order
     * @param array $response
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function handle($order, $response)
    {

        if ($order->getCurrentState() == \Configuration::get('MPGS_OS_FRAUD')) {
            return;
        }

        if ($order->getCurrentState() == \Configuration::get('MPGS_OS_REVIEW_REQUIRED')) {
            return;
        }

        if ($this->hasExceptions()) {
            $history = new \OrderHistory();
            $history->id_order = (int)$order->id;
            $history->changeIdOrderState(Configuration::get('PS_OS_ERROR'), $order, true);
            $history->addWithemail(true, array());

            return;
        }

        $newStatus = null;

        if ($response['status'] == "AUTHORIZED") {
            $newStatus = \Configuration::get('MPGS_OS_AUTHORIZED');
        }

        if ($response['status'] == "CAPTURED") {
            $newStatus = \Configuration::get('PS_OS_PAYMENT');
        }

        if ($response['status'] == 'VOID_AUTHORIZATION' || $response['status'] == 'CANCELLED') {
            $newStatus = \Configuration::get('PS_OS_CANCELED');
        }

        if ($response['status'] == 'REFUNDED') {
            $newStatus = \Configuration::get('PS_OS_REFUND');
        }

        if ($response['status'] == 'PARTIALLY_REFUNDED') {
                $newStatus = \Configuration::get('MPGS_OS_PARTIALLY_REFUNDED');
        }

        if (!$newStatus) {
            $newStatus = \Configuration::get('PS_OS_ERROR');
            $this->processor->logger->error(
                'Unexpected response status "'.$response['status'].'"',
                array(
                    'response' => $response,
                )
            );
        }

        $history = new \OrderHistory();
        $history->id_order = (int)$order->id;
        $history->changeIdOrderState($newStatus, $order, true);
        $history->addWithemail(true, array());
    }
}
