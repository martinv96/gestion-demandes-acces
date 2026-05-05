<?php

namespace App\Message;

final class WorkflowNotificationMessage
{
    public function __construct(
        public readonly int $requestId,
        public readonly string $comment,
    ) {}
}