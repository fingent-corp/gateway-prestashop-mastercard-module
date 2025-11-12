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

class ApiErrorPlugin implements Plugin
{
    /**
     * @inheritdoc
     */
    public function handleRequest(\Psr\Http\Message\RequestInterface $request, callable $next, callable $first): Promise
    {
        $promise = $next($request);

        return $promise->then(function (ResponseInterface $response) use ($request) {
            return $this->transformResponseToException($request, $response);
        });
    }

    /**
     * @param RequestInterface $request
     * @param ResponseInterface $response
     *
     * @return ResponseInterface
     */
    protected function transformResponseToException(RequestInterface $request, ResponseInterface $response)
    {
        if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
            $responseData = json_decode($response->getBody(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ServerErrorException("Response not valid JSON", $request, $response);
            }

            $msg = '';
            if (isset($responseData['error']['cause'])) {
                $msg .= $responseData['error']['cause'].': ';
            }
            if (isset($responseData['error']['explanation'])) {
                $msg .= $responseData['error']['explanation'];
            }
            throw new ClientErrorException($msg, $request, $response);
        }

        if ($response->getStatusCode() >= 500 && $response->getStatusCode() < 600) {
            throw new ServerErrorException($response->getReasonPhrase(), $request, $response);
        }

        return $response;
    }
}
