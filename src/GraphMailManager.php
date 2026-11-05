<?php

namespace MercantilePacific\GraphMail;

use Closure;
use InvalidArgumentException;

/**
 * Hands out one GraphMailClient per configured mailbox connection.
 */
class GraphMailManager
{
    /** @var array<string, GraphMailClient> */
    private array $connections = [];

    /** @var list<Closure(array<string, mixed>): bool> */
    private array $attachmentRejectors = [];

    public function connection(?string $name = null): GraphMailClient
    {
        $name ??= (string) config('graph-mail.default', 'default');

        return $this->connections[$name] ??= $this->make($name);
    }

    /**
     * Skip attachments the callback returns true for (it gets Graph's
     * attachment array), on top of the built-in inline / signature rules.
     * Applies to connections made after this call.
     *
     * @param  Closure(array<string, mixed>): bool  $rejector
     */
    public function rejectAttachmentsUsing(Closure $rejector): static
    {
        $this->attachmentRejectors[] = $rejector;
        $this->connections = [];

        return $this;
    }

    private function make(string $name): GraphMailClient
    {
        $config = config("graph-mail.connections.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Graph mail connection [{$name}] is not configured.");
        }

        return new GraphMailClient($config, [
            'signature_picture_max_bytes' => (int) config('graph-mail.signature_picture_max_bytes', 30_000),
            'gif_signature_max_bytes' => (int) config('graph-mail.gif_signature_max_bytes', 200_000),
            'keep_picture_name_pattern' => config('graph-mail.keep_picture_name_pattern'),
        ], $this->attachmentRejectors);
    }
}
