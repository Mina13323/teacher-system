<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers API errors in the language the app is showing.
 *
 * The SPA sends its language in Accept-Language. For Arabic the application
 * locale is switched before the request runs (so Laravel's own validation
 * messages come out in Arabic from lang/ar/validation.php), and the fixed
 * English messages of error responses (abort(), exceptions, handlers) are
 * translated afterwards through the JSON file lang/ar.json, keyed by the
 * English text. A message with no entry is left as it is.
 *
 * Only error responses are touched, so successful (hot-path) responses are
 * never decoded or rewritten.
 */
class LocalizeApiResponse
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $arabic = str_starts_with(strtolower(trim((string) $request->header('Accept-Language'))), 'ar');

        if (! $arabic) {
            return $next($request);
        }

        $previous = app()->getLocale();
        app()->setLocale('ar');

        try {
            $response = $next($request);
            $response = $this->localize($response);
        } finally {
            // Never leave Arabic behind for a later request in the same process.
            app()->setLocale($previous);
        }

        return $response;
    }

    private function localize(Response $response): Response
    {
        if (! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }

        $data = $response->getData(true);
        if (! is_array($data)) {
            return $response;
        }

        if (isset($data['message']) && is_string($data['message'])) {
            $data['message'] = $this->translate($data['message']);
        }

        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $field => $messages) {
                $data['errors'][$field] = is_array($messages)
                    ? array_map(fn ($m) => is_string($m) ? $this->translate($m) : $m, $messages)
                    : $messages;
            }
        }

        $response->setData($data);

        return $response;
    }

    private function translate(string $message): string
    {
        $translated = __($message);

        return is_string($translated) ? $translated : $message;
    }
}
