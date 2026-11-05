<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MercantilePacific\GraphMail\GraphMailClient;
use MercantilePacific\GraphMail\GraphMailManager;

const TOKEN_URL = 'login.microsoftonline.com/*';

function fakeToken(string $token = 'tok'): array
{
    return [TOKEN_URL => Http::response(['access_token' => $token, 'expires_in' => 3600])];
}

it('follows next links without repeating the query', function () {
    Http::fake(fakeToken() + [
        'graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages?*' => Http::response([
            'value' => [['id' => 'a', 'receivedDateTime' => '2026-01-01T00:00:00Z']],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/next?$skiptoken=1',
        ]),
        'graph.microsoft.com/v1.0/next*' => Http::response([
            'value' => [['id' => 'b', 'receivedDateTime' => '2026-01-02T00:00:00Z']],
        ]),
    ]);

    $ids = collect(app(GraphMailClient::class)->messagesSince(Carbon::parse('2026-01-01')))->pluck('id')->all();

    expect($ids)->toBe(['a', 'b']);
});

it('drops inline files, signature pictures and rejected attachments', function () {
    Http::fake(fakeToken() + [
        'graph.microsoft.com/*' => Http::response(['value' => [
            ['id' => '1', 'name' => 'scan.pdf', 'contentType' => 'application/pdf', 'size' => 90000],
            ['id' => '2', 'name' => 'logo.png', 'contentType' => 'image/png', 'size' => 5000],
            ['id' => '3', 'name' => 'inline.pdf', 'contentType' => 'application/pdf', 'size' => 9000, 'isInline' => true],
            ['id' => '4', 'name' => 'sig.gif', 'contentType' => 'image/gif', 'size' => 100000],
            ['id' => '5', 'name' => 'secret.pdf', 'contentType' => 'application/pdf', 'size' => 9000],
        ]]),
    ]);

    $manager = app(GraphMailManager::class)->rejectAttachmentsUsing(fn (array $a): bool => $a['name'] === 'secret.pdf');

    expect(collect($manager->connection()->attachments('m'))->pluck('id')->all())->toBe(['1']);
});

it('keeps a small picture whose name matches the keep pattern', function () {
    config(['graph-mail.keep_picture_name_pattern' => '/invoice/i']);
    Http::fake(fakeToken() + [
        'graph.microsoft.com/*' => Http::response(['value' => [
            ['id' => '1', 'name' => 'invoice.png', 'contentType' => 'image/png', 'size' => 5000],
        ]]),
    ]);

    expect(app(GraphMailClient::class)->attachments('m'))->toHaveCount(1);
});

it('retries a 401 once with a fresh token', function () {
    Http::fake([
        TOKEN_URL => Http::sequence()
            ->push(['access_token' => 'old', 'expires_in' => 3600])
            ->push(['access_token' => 'new', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::sequence()
            ->push([], 401)
            ->push('bytes'),
    ]);

    expect(app(GraphMailClient::class)->attachmentContent('m', 'a'))->toBe('bytes');
});

it('does not retry sendMail on a server error', function () {
    Http::fake(fakeToken() + ['graph.microsoft.com/*' => Http::response([], 503)]);

    expect(fn () => app(GraphMailClient::class)->sendMail(['a@b.com'], 's', '<p>x</p>'))->toThrow(Exception::class);

    Http::assertSentCount(2); // token + exactly one send
});

it('sends recipients, cc and base64 attachments', function () {
    Http::fake(fakeToken() + ['graph.microsoft.com/*' => Http::response([], 202)]);

    app(GraphMailClient::class)->sendMail(['a@b.com'], 'Hi', '<p>x</p>', [['name' => 'f.txt', 'content_type' => 'text/plain', 'contents' => 'hello']], ['c@d.com']);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMail')
        && $r['message']['toRecipients'][0]['emailAddress']['address'] === 'a@b.com'
        && $r['message']['ccRecipients'][0]['emailAddress']['address'] === 'c@d.com'
        && $r['message']['attachments'][0]['contentBytes'] === base64_encode('hello'));
});

it('returns an empty string when the message text cannot be read', function () {
    Http::fake(fakeToken() + ['graph.microsoft.com/*' => Http::response([], 404)]);

    expect(app(GraphMailClient::class)->messageText('m'))->toBe('');
});

it('reports an unconfigured connection', function () {
    expect((new GraphMailClient([]))->isConfigured())->toBeFalse();
    expect(fn () => app(GraphMailManager::class)->connection('missing'))->toThrow(InvalidArgumentException::class);
});
