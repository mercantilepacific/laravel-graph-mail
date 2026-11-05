<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config(['mail.mailers.graph' => ['transport' => 'graph', 'connection' => 'default']]);
    config(['mail.from' => ['address' => 'box@example.com', 'name' => 'Box']]);
});

it('sends a mailable as base64 MIME with bcc and attachment', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response([], 202),
    ]);

    Mail::mailer('graph')->html('<p>Hello</p>', function ($message) {
        $message->to('a@b.com')->cc('c@d.com')->bcc('hidden@e.com')->subject('Hi')
            ->attachData('file-body', 'f.txt', ['mime' => 'text/plain']);
    });

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/users/box%40example.com/sendMail')) {
            return false;
        }

        $mime = base64_decode($request->body());

        return $request->hasHeader('Content-Type', 'text/plain')
            && str_contains($mime, 'Bcc: hidden@e.com')
            && str_contains($mime, 'To: a@b.com')
            && str_contains($mime, 'Cc: c@d.com')
            && str_contains($mime, 'Subject: Hi')
            && str_contains($mime, 'f.txt');
    });
});

it('does not retry a send that fails with a server error', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response([], 503),
    ]);

    expect(fn () => Mail::mailer('graph')->raw('plain', fn ($m) => $m->to('a@b.com')->subject('x')))->toThrow(Exception::class);

    Http::assertSentCount(2);
});
