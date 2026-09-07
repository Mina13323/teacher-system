<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a playback-session operation is invalid (expired, owned by
 * another student, belongs to another video, or the event type is not
 * client-reportable). Rendered as a 403/422 JSON response by the API handler.
 */
class InvalidVideoPlaybackSessionException extends RuntimeException
{
}
