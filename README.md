# Laravel Graph Mail

Send and read Microsoft 365 mail from Laravel through Microsoft Graph, using an app-only (client-credentials) token. It provides:

- a `graph` mail driver, so Mailables and Notifications go through Graph with no code changes;
- a `GraphMailClient` to read a mailbox, download attachments and send mail directly.

Requires PHP 8.1+ and Laravel 9, 10, 11, 12 or 13.

## Installation

```bash
composer require mercantilepacific/laravel-graph-mail
```

If the package is not on Packagist yet, add the repository first:

```bash
composer config repositories.graph-mail vcs https://github.com/mercantilepacific/laravel-graph-mail.git
```

The service provider is auto-discovered. Publish the config if you need to change it:

```bash
php artisan vendor:publish --tag=graph-mail-config
```

## Microsoft Entra setup

1. In Microsoft Entra ID, create an app registration (or reuse one).
2. Copy the **Directory (tenant) ID** and **Application (client) ID**.
3. Under **Certificates & secrets**, create a client secret and copy its value.
4. Under **API permissions**, add Microsoft Graph **Application** permissions and grant admin consent:
   - `Mail.Send` to send;
   - `Mail.Read` to read.
5. Restrict the app to the mailbox it needs with an [Application Access Policy](https://learn.microsoft.com/en-us/graph/auth-limit-mailbox-access). Without one, the app can reach every mailbox in the tenant.

## Configuration

Add to `.env`:

```
MICROSOFT_GRAPH_TENANT_ID=
MICROSOFT_GRAPH_CLIENT_ID=
MICROSOFT_GRAPH_CLIENT_SECRET=
MICROSOFT_GRAPH_MAILBOX=
MICROSOFT_GRAPH_MAIL_FOLDER=inbox
```

`config/graph-mail.php` holds one entry per mailbox under `connections`, so you can add more than one:

```php
'connections' => [
    'default' => [/* ... */],
    'support' => [
        'tenant_id' => env('SUPPORT_GRAPH_TENANT_ID'),
        'client_id' => env('SUPPORT_GRAPH_CLIENT_ID'),
        'client_secret' => env('SUPPORT_GRAPH_CLIENT_SECRET'),
        'mailbox' => env('SUPPORT_GRAPH_MAILBOX'),
        'mail_folder' => 'inbox',
    ],
],
```

## Sending with the mail driver

Add a mailer to `config/mail.php`:

```php
'mailers' => [
    'graph' => [
        'transport' => 'graph',
        'connection' => 'default', // optional, defaults to graph-mail.default
    ],
],
```

Then set it as the default:

```
MAIL_MAILER=graph
MAIL_FROM_ADDRESS=the-same-mailbox@example.com
```

Or use it for one message:

```php
Mail::mailer('graph')->to($user)->send(new InvoiceMail());
```

The message is sent as raw MIME, so to, cc, bcc, reply-to, plain and HTML bodies, attachments and inline images all work. Messages are kept in the mailbox's Sent Items.

Graph can only send as the connection's mailbox (or one it has send-as rights on), so set `MAIL_FROM_ADDRESS` to it.

## Using the client

Inject `GraphMailClient` for the default connection, or ask the manager for a named one:

```php
use MercantilePacific\GraphMail\GraphMailClient;
use MercantilePacific\GraphMail\GraphMailManager;

$client = app(GraphMailClient::class);
$client = app(GraphMailManager::class)->connection('support');
```

### Read mail

Reading never moves, flags or deletes mail, so de-duplicate by the message `id` in your app.

```php
foreach ($client->messagesSince(now()->subDay()) as $message) {
    // id, internet_message_id, subject, sender, received_at, body_preview, has_attachments
}

$client->messagesSince($since, withAttachmentsOnly: false); // include mail without attachments
$client->messageText($messageId);                          // the full body as text
$client->attachments($messageId);                          // file attachments (metadata)
$client->attachmentContent($messageId, $attachmentId);     // raw bytes
```

`messagesSince()` returns a generator and follows Graph's paging, up to 10,000 messages per call.

### Send mail

```php
$client->sendMail(
    to: ['a@example.com'],
    subject: 'Hello',
    html: '<p>Hi</p>',
    attachments: [['name' => 'f.pdf', 'content_type' => 'application/pdf', 'contents' => $bytes]],
    cc: ['b@example.com'],
);

$client->sendMime($rawMimeMessage);
```

## Filtering attachments

`attachments()` already skips inline files and small pictures that look like signatures or logos: a picture under 30 KB, or a GIF under 200 KB. Change the limits in the config.

To keep a small picture that is really a document, match its name with a regex:

```php
'keep_picture_name_pattern' => '/awb|invoice|packing/i',
```

For any other rule, register a callback that returns `true` for attachments to skip. Call it before the first use of a connection, for example in a service provider's `boot()`:

```php
app(GraphMailManager::class)->rejectAttachmentsUsing(
    fn (array $attachment): bool => str_ends_with($attachment['name'], '.ics'),
);
```

## Retries and limits

- Reads retry on timeouts, lost connections, 5xx and 429 (honouring `Retry-After`).
- On Laravel 10+ the pause after a 429 follows `Retry-After`; Laravel 9 only supports a fixed delay, so it waits 500 ms.
- A 401 drops the cached token and retries once with a new one.
- **Sending is never retried** on a timeout, lost connection, 5xx or 429. Graph may already have sent the message, and a retry would deliver it twice. A failed send throws, so handle it in your code. For queued mail, keep `$tries` low on the job.
- The access token is cached per tenant, app and mailbox. Use a shared cache such as Redis so queue workers on different servers share it.
- Microsoft limits a mailbox to about 30 messages a minute and 10,000 a day. Throttle bulk sends through a queue.

## Testing

Use `Http::fake()`. The token endpoint is `login.microsoftonline.com/*` and the API is `graph.microsoft.com/*`:

```php
Http::fake([
    'login.microsoftonline.com/*' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
    'graph.microsoft.com/*' => Http::response([], 202),
]);
```

Run the package's own tests with:

```bash
vendor/bin/pest
```

## License

MIT
