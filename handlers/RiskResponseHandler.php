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
use Fingent\Mastercard\Handlers\ResponseHandler;
use Configuration;
use OrderHistory;

class RiskResponseHandler extends ResponseHandler
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
        $newStatus = $this->determineNewOrderStatus($response);

        if ($newStatus && $newStatus != $order->getCurrentOrderState()->id) {
            $this->updateOrderStatus($order, $newStatus);
        }
    }

    protected function determineNewOrderStatus(array $response): ?int
    {
        $status = null;

        if (isset($response['risk']['response'])) {
            $risk        = $response['risk']['response'];
            $gatewayCode = $risk['gatewayCode'] ?? null;
            $decision    = $risk['review']['decision'] ?? null;

            if ($gatewayCode === 'REVIEW_REQUIRED') {
                $status = $this->getStatusForReviewDecision($decision);
            } elseif ($gatewayCode === 'REJECTED') {
                $status = Configuration::get('MPGS_OS_FRAUD');
            } else {
                $status = null;
            }
        }

        return $status;
    }

    protected function getStatusForReviewDecision(?string $decision): ?int
    {
        return match ($decision) {
            'PENDING'  => Configuration::get('MPGS_OS_REVIEW_REQUIRED'),
            'ACCEPTED' => Configuration::get('MPGS_OS_PAYMENT_WAITING'),
            'REJECTED' => Configuration::get('MPGS_OS_FRAUD'),
            default    => null,
        };
    }

    protected function updateOrderStatus($order, int $newStatus): void
    {
        $history = new OrderHistory();
        $history->id_order = (int)$order->id;
        $history->changeIdOrderState($newStatus, $order, true);
        $history->addWithemail(true, []);
    }
}
