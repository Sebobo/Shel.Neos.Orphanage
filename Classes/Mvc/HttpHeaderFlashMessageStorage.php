<?php

/*
 * This file is part of the Shel.Neos.Orphanage package.
 *
 * (c) 2024 Sebastian Helzle <sebastian@helzle.it>
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Shel\Neos\Orphanage\Mvc;

use Neos\Error\Messages\Message;
use Neos\Flow\Mvc\FlashMessage\FlashMessageContainer;
use Neos\Flow\Mvc\FlashMessage\FlashMessageStorageInterface;
use Psr\Http\Message\ResponseInterface as HttpResponseInterface;
use Psr\Http\Message\ServerRequestInterface as HttpRequestInterface;

final class HttpHeaderFlashMessageStorage implements FlashMessageStorageInterface
{
    private FlashMessageContainer|null $flashMessageContainer = null;

    public function load(HttpRequestInterface $request): FlashMessageContainer
    {
        if ($this->flashMessageContainer === null) {
            $this->flashMessageContainer = new FlashMessageContainer();
        }
        return $this->flashMessageContainer;
    }

    public function persist(HttpResponseInterface $response): HttpResponseInterface
    {
        $messages = array_map(static fn(Message $message) => [
            'title' => $message->getTitle(),
            'message' => $message->render(),
            'severity' => $message->getSeverity(),
            'code' => $message->getCode(),
        ], $this->flashMessageContainer?->getMessagesAndFlush() ?? []);
        if ($messages === []) {
            return $response;
        }
        return $response->withAddedHeader('X-Flow-FlashMessages', json_encode($messages, JSON_THROW_ON_ERROR));
    }
}
