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

namespace Fingent\Mastercard\Api;

use Http\Discovery\HttpClientDiscovery;
use Nyholm\Psr7\Factory\Psr17Factory;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Message\Authentication\BasicAuth;
use Http\Client\Common\PluginClient;
use Http\Message\RequestMatcher\RequestMatcher;
use Http\Client\Common\HttpClientRouter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Http\Client\Common\Plugin\ContentLengthPlugin;
use Http\Client\Common\Plugin\HeaderSetPlugin;
use Http\Client\Common\Plugin;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Http\Message\Formatter;
use Http\Message\Formatter\SimpleFormatter;
use Http\Client\Exception;
use Http\Client\Common\Exception\ClientErrorException;
use Http\Client\Common\Exception\ServerErrorException;
use Http\Promise\promise;

class ApiLoggerPlugin implements Plugin
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Formatter
     */
    private $formatter;

    /**
     * @inheritdoc
     */
    public function __construct(LoggerInterface $logger, Formatter $formatter = null)
    {
        $this->logger    = $logger;
        $this->formatter = $formatter ?: new SimpleFormatter();
    }

    /**
     * @inheritdoc
     */
    public function handleRequest(\Psr\Http\Message\RequestInterface $request, callable $next, callable $first): Promise
    {
        $reqBody = json_decode($request->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $reqBody = $request->getBody();
        }

        $this->logger->info(sprintf('Emit request: "%s"', $this->formatter->formatRequest($request)),
            ['request' => $reqBody]);

        return $next($request)->then(function (ResponseInterface $response) use ($request) {
            $body = json_decode($response->getBody(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $body = $response->getBody();
            }
            $this->logger->info(
                sprintf('Receive response: "%s" for request: "%s"', $this->formatter->formatResponse($response),
                    $this->formatter->formatRequest($request)),
                [
                    'response' => $body,
                ]
            );

            return $response;
        }, function (\Exception $exception) use ($request) {
            if ($exception instanceof Exception\HttpException) {
                $this->logger->error(
                    sprintf('Error: "%s" with response: "%s" when emitting request: "%s"', $exception->getMessage(),
                        $this->formatter->formatResponse($exception->getResponse()),
                        $this->formatter->formatRequest($request)),
                    [
                        'request'   => $request,
                        'response'  => $exception->getResponse(),
                        'exception' => $exception,
                    ]
                );
            } else {
                $this->logger->error(
                    sprintf('Error: "%s" when emitting request: "%s"', $exception->getMessage(),
                        $this->formatter->formatRequest($request)),
                    [
                        'request'   => $request,
                        'exception' => $exception,
                    ]
                );
            }

            throw $exception;
        });
    }
}
