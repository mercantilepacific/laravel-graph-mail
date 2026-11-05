<?php

namespace MercantilePacific\GraphMail;

use Carbon\CarbonInterface;
use Closure;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Reads and sends mail for one Microsoft 365 mailbox through Microsoft Graph
 * with an app-only (client-credentials) token. Reading never moves, flags or
 * deletes mail — the caller de-duplicates by message id.
 */
class GraphMailClient
{
    private const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    private const PAGE_SIZE = 50;

    /** Safety stop: never follow more pages than this in one read (50 × 200 = 10,000 emails). */
    private const MAX_PAGES = 200;

    /**
     * @param  array<string, mixed>  $config  tenant_id, client_id, client_secret, mailbox, mail_folder
     * @param  array<string, mixed>  $options  signature_picture_max_bytes, gif_signature_max_bytes, keep_picture_name_pattern
     * @param  list<Closure(array<string, mixed>): bool>  $attachmentRejectors
     */
    public function __construct(
        private readonly array $config,
        private readonly array $options = [],
        private readonly array $attachmentRejectors = [],
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->config['tenant_id'] ?? null)
            && filled($this->config['client_id'] ?? null)
            && filled($this->config['client_secret'] ?? null)
            && filled($this->config['mailbox'] ?? null);
    }

    /**
     * Messages (with attachments, unless `$withAttachmentsOnly` is false)
     * received at or after `$since`, oldest first, following Graph's paging.
     *
     * @return Generator<int, array{id: string, internet_message_id: ?string, subject: ?string, sender: ?string, received_at: string, body_preview: ?string, has_attachments: bool}>
     */
    public function messagesSince(CarbonInterface $since, bool $withAttachmentsOnly = true): Generator
    {
        $url = $this->mailboxUrl('/mailFolders/'.rawurlencode((string) ($this->config['mail_folder'] ?? 'inbox')).'/messages');
        $query = [
            '$filter' => 'receivedDateTime ge '.$since->copy()->utc()->format('Y-m-d\TH:i:s\Z').($withAttachmentsOnly ? ' and hasAttachments eq true' : ''),
            '$orderby' => 'receivedDateTime asc',
            '$select' => 'id,internetMessageId,subject,from,receivedDateTime,bodyPreview,hasAttachments',
            '$top' => self::PAGE_SIZE,
        ];

        $pages = 0;

        while ($url !== null) {
            // Graph's next-page link carries its own query ($skiptoken) — it is
            // followed as is: passing any query again would replace it and
            // re-read the first page forever.
            $payload = ($query === null ? $this->request()->get($url) : $this->request()->get($url, $query))->throw()->json();
            $query = null;

            if (++$pages > self::MAX_PAGES) {
                Log::warning('Mailbox read stopped at the page limit', ['pages' => self::MAX_PAGES, 'mailbox' => $this->config['mailbox'] ?? null]);

                return;
            }

            foreach ($payload['value'] ?? [] as $message) {
                yield [
                    'id' => (string) $message['id'],
                    'internet_message_id' => $message['internetMessageId'] ?? null,
                    'subject' => $message['subject'] ?? null,
                    'sender' => $message['from']['emailAddress']['address'] ?? null,
                    // Graph gives UTC; the app (and its database) run in the app timezone.
                    'received_at' => Carbon::parse((string) $message['receivedDateTime'])->setTimezone((string) config('app.timezone'))->toIso8601String(),
                    'body_preview' => $message['bodyPreview'] ?? null,
                    'has_attachments' => (bool) ($message['hasAttachments'] ?? false),
                ];
            }

            $url = $payload['@odata.nextLink'] ?? null;
        }
    }

    /**
     * File attachments on a message (metadata only) — not the pictures inside
     * the email itself (a logo in the signature), nor signature pictures sent
     * as plain attachments, nor any the registered rejectors skip.
     *
     * @return list<array{id: string, name: string, content_type: ?string, size: int}>
     */
    public function attachments(string $messageId): array
    {
        $payload = $this->request()
            ->get($this->mailboxUrl('/messages/'.rawurlencode($messageId).'/attachments'), [
                '$select' => 'id,name,contentType,size,isInline',
            ])
            ->throw()
            ->json();

        return collect($payload['value'] ?? [])
            ->filter(fn (array $attachment): bool => ($attachment['@odata.type'] ?? '#microsoft.graph.fileAttachment') === '#microsoft.graph.fileAttachment')
            ->reject(fn (array $attachment): bool => ($attachment['isInline'] ?? false) === true)
            ->reject(fn (array $attachment): bool => $this->isSignaturePicture($attachment))
            ->reject(function (array $attachment): bool {
                foreach ($this->attachmentRejectors as $rejector) {
                    if ($rejector($attachment)) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn (array $attachment): array => [
                'id' => (string) $attachment['id'],
                'name' => (string) ($attachment['name'] ?? 'attachment'),
                'content_type' => $attachment['contentType'] ?? null,
                'size' => (int) ($attachment['size'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * A signature or logo picture, not a document: a picture smaller than a
     * scanned page (or a GIF under the larger GIF limit) whose name doesn't
     * match `keep_picture_name_pattern`. A picture whose size Graph doesn't
     * give is kept.
     *
     * @param  array<string, mixed>  $attachment
     */
    private function isSignaturePicture(array $attachment): bool
    {
        $name = (string) ($attachment['name'] ?? '');
        $type = strtolower((string) ($attachment['contentType'] ?? ''));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = (int) ($attachment['size'] ?? 0);

        $isPicture = str_starts_with($type, 'image/') || in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'bmp'], true);
        $keepPattern = $this->options['keep_picture_name_pattern'] ?? null;
        $namesDocument = is_string($keepPattern) && $keepPattern !== '' && preg_match($keepPattern, $name) === 1;

        if (! $isPicture || $namesDocument || $size <= 0) {
            return false;
        }

        $isGif = $type === 'image/gif' || $extension === 'gif';

        return $size < ($isGif
            ? (int) ($this->options['gif_signature_max_bytes'] ?? 200_000)
            : (int) ($this->options['signature_picture_max_bytes'] ?? 30_000));
    }

    /**
     * Sends an email from the mailbox (needs the `Mail.Send` application
     * permission). Kept in Sent Items so the thread stays whole. Never retried
     * on a timeout, a lost connection or a 5xx / 429 — Graph may already have
     * sent it, and the recipient must not get it twice; only a 401 (refused
     * before sending) is retried with a fresh token.
     *
     * @param  list<string>  $to
     * @param  list<array{name: string, content_type: string, contents: string}>  $attachments
     * @param  list<string>  $cc
     */
    public function sendMail(array $to, string $subject, string $html, array $attachments = [], array $cc = []): void
    {
        $this->request(retryTransient: false)
            ->post($this->mailboxUrl('/sendMail'), [
                'message' => [
                    'subject' => $subject,
                    'body' => ['contentType' => 'HTML', 'content' => $html],
                    'toRecipients' => array_map(
                        fn (string $address): array => ['emailAddress' => ['address' => $address]],
                        $to,
                    ),
                    'ccRecipients' => array_map(
                        fn (string $address): array => ['emailAddress' => ['address' => $address]],
                        $cc,
                    ),
                    'attachments' => array_map(fn (array $attachment): array => [
                        '@odata.type' => '#microsoft.graph.fileAttachment',
                        'name' => $attachment['name'],
                        'contentType' => $attachment['content_type'],
                        'contentBytes' => base64_encode($attachment['contents']),
                    ], $attachments),
                ],
                'saveToSentItems' => true,
            ])
            ->throw();
    }

    /**
     * Sends a ready-made MIME message (to, cc, bcc, attachments and inline
     * images all come from its headers and parts). Same no-retry rule as
     * `sendMail()`.
     */
    public function sendMime(string $mime): void
    {
        $this->request(retryTransient: false)
            ->withBody(base64_encode($mime), 'text/plain')
            ->post($this->mailboxUrl('/sendMail'))
            ->throw();
    }

    /**
     * The whole text of one email (Graph's preview is only ~255 characters).
     * Falls back to '' when it can't be read.
     */
    public function messageText(string $messageId): string
    {
        try {
            return (string) $this->request()
                ->withHeaders(['Prefer' => 'outlook.body-content-type="text"'])
                ->get($this->mailboxUrl('/messages/'.rawurlencode($messageId)), ['$select' => 'body'])
                ->throw()
                ->json('body.content', '');
        } catch (Throwable) {
            return '';
        }
    }

    /** The raw bytes of one attachment. */
    public function attachmentContent(string $messageId, string $attachmentId): string
    {
        return $this->request()
            ->get($this->mailboxUrl('/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($attachmentId).'/$value'))
            ->throw()
            ->body();
    }

    /**
     * Retries transient failures (timeouts, lost connections, 5xx, 429) when
     * `$retryTransient` — reads only; on a 401 (e.g. a token cached from
     * before a permission change) it drops the cached token and retries with
     * a new one.
     */
    private function request(bool $retryTransient = true): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->retry(3, function (int $attempt, Throwable $exception): int {
                // Throttled: wait as long as Microsoft asks (capped), else a short pause.
                if ($exception instanceof RequestException && $exception->response->status() === 429) {
                    return min(10, max(1, (int) $exception->response->header('Retry-After'))) * 1000;
                }

                return 500;
            }, function (Throwable $exception, PendingRequest $request) use ($retryTransient): bool {
                if ($exception instanceof RequestException && $exception->response->status() === 401) {
                    Cache::forget($this->tokenCacheKey());
                    $request->withToken($this->accessToken());

                    return true;
                }

                return $retryTransient && (! $exception instanceof RequestException
                    || $exception->response->serverError()
                    || $exception->response->status() === 429);
            }, throw: false);
    }

    /** App-only token, cached until shortly before it expires. */
    private function accessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Microsoft Graph mailbox is not configured — set the tenant_id, client_id, client_secret and mailbox of the graph-mail connection.');
        }

        $cached = Cache::get($this->tokenCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post("https://login.microsoftonline.com/{$this->config['tenant_id']}/oauth2/v2.0/token", [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
                'scope' => 'https://graph.microsoft.com/.default',
            ])
            ->throw()
            ->json();

        $token = (string) ($response['access_token'] ?? '');
        $expiresIn = (int) ($response['expires_in'] ?? 3600);

        if ($token === '') {
            throw new RuntimeException('Microsoft Graph did not return an access token.');
        }

        Cache::put($this->tokenCacheKey(), $token, max(60, $expiresIn - 300));

        return $token;
    }

    /** One token per app registration and mailbox, so connections never share one. */
    private function tokenCacheKey(): string
    {
        return 'graph-mail.token.'.md5(($this->config['tenant_id'] ?? '').'|'.($this->config['client_id'] ?? '').'|'.($this->config['mailbox'] ?? ''));
    }

    private function mailboxUrl(string $path): string
    {
        return self::GRAPH_URL.'/users/'.rawurlencode((string) $this->config['mailbox']).$path;
    }
}
