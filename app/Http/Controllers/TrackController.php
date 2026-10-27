<?php

namespace App\Http\Controllers;

use App\Models\TrackEvent;
use App\Models\TrackedEmail;
use App\Services\EventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public endpoints hit by the recipient's mail client: the open pixel and tracked links.
 */
class TrackController extends Controller
{
    /** 1x1 transparent GIF. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(private EventRecorder $recorder) {}

    public function pixel(Request $request, string $token): Response
    {
        $email = TrackedEmail::where('token', $token)->first();

        if ($email !== null) {
            $this->recorder->record($email, TrackEvent::OPEN, $request);
        }

        // Always answer with the image, so an unknown token never shows a broken picture.
        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function click(Request $request, string $token, int $position): RedirectResponse|View
    {
        $email = TrackedEmail::where('token', $token)->first();
        $link = $email?->links()->where('position', $position)->first();

        $destination = $link?->url;
        $fallback = $this->safeUrl($request->query('u'));

        if ($destination === null && $email === null) {
            // Unknown email: never redirect blindly (that would be an open redirect for phishing).
            abort_if($fallback === null, 404);

            return view('track.continue', ['url' => $fallback]);
        }

        $destination ??= $fallback;
        abort_if($destination === null, 404);

        $this->recorder->record($email, TrackEvent::CLICK, $request, $link);

        return redirect()->away($destination);
    }

    private function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
    }
}
