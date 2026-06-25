<?php

namespace App\Services\Chat\Exceptions;

/** No capability is registered for the requested session_type (HTTP 422). */
final class UnknownCapabilityException extends ChatException {}
