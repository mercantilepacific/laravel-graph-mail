<?php

namespace MercantilePacific\GraphMail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Laravel mail transport that sends through Microsoft Graph as the
 * connection's mailbox (`MAIL_MAILER=graph`).
 */
class GraphTransport extends AbstractTransport
{
    public function __construct(private readonly GraphMailClient $client)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $mime = $message->toString();
        $original = $message->getOriginalMessage();

        // Symfony strips Bcc from the rendered message; Graph reads its recipients from the headers.
        if ($original instanceof Email && $original->getBcc() !== []) {
            $mime = 'Bcc: '.implode(', ', array_map(fn (Address $address): string => $address->toString(), $original->getBcc()))."\r\n".$mime;
        }

        $this->client->sendMime($mime);
    }

    public function __toString(): string
    {
        return 'graph';
    }
}
