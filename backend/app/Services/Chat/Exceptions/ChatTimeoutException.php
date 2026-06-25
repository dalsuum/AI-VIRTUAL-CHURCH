<?php

namespace App\Services\Chat\Exceptions;

/** The orchestration envelope deadline was exceeded before completion (HTTP 504). */
final class ChatTimeoutException extends ChatException {}
