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

class ResponseProcessor
{
    /**
     * @var Module
     */
    public $module;

    /**
     * @var array
     */
    public $exceptions;

    /**
     * @var Logger
     */
    public $logger;

    /**
     * ResponseProcessor constructor.
     *
     * @param Module $module
     *
     * @throws Exception
     */
    public function __construct($module)
    {
        $this->logger = new Logger('mastercard_handler');
        $this->logger->pushHandler(new StreamHandler(
            _PS_ROOT_DIR_.'/var/logs/mastercard.log',
            \Configuration::get('mpgs_logging_level')
        ));
        $this->module = $module;
    }

    /**
     * @param Order $order
     * @param array $response
     * @param ResponseHandler[] $handlers
     *
     * @throws MasterCardPaymentException
     */
    public function handle($order, $response, $handlers = array())
    {
        $this->exceptions = array();
        foreach ($handlers as $handler) {
            try {
                $handler
                    ->setProcessor($this)
                    ->handle($order, $response);
            } catch (MasterCardPaymentException $e) {
                $this->logger->critical('Payment Handler Exception', array('exception' => $e));
                $this->exceptions[] = $e->getMessage();
            }
        }

        if (!empty($this->exceptions)) {
            throw new MasterCardPaymentException(implode("\n", $this->exceptions));
        }
    }
}

