<?php

namespace App\Services\Chat\Exceptions;

/** The client disconnected or the turn was aborted (HTTP 499 / client-closed). */
final class ChatCancelledException extends ChatException {}
