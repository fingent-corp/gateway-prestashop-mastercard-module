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

class TransactionResponseHandler extends ResponseHandler
{
    /**
     * @inheritdoc
     */
    public function handle($order, $response)
    {
        $state = $order->getCurrentOrderState();

        if ($state->id == \Configuration::get('MPGS_OS_FRAUD')) {
            throw new MasterCardPaymentException(
                $this->processor->module->l('Payment is marked as fraud, action is blocked.')
            );
        }

        if ($state->id == \Configuration::get('MPGS_OS_REVIEW_REQUIRED')) {
            throw new MasterCardPaymentException(
                $this->processor->module->l('Risk decision needed, action is blocked.')
            );
        }

        if (!$this->isApproved($response)) {
            throw new MasterCardPaymentException(
                $this->processor->module->l('The operation was declined.').' ('.$response['response']['gatewayCode'].')'
            );
        }
    }
}
