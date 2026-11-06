<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TransactionalMail extends Mailable
{
    public function __construct(public string $subjectLine, public array $payload, public string $appName, public string $fromName, public ?string $replyAddress = null, public ?string $color = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->fromName),
            replyTo: $this->replyAddress ? [new Address($this->replyAddress)] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.transactional', with: ['c' => $this->payload, 'appName' => $this->appName, 'color' => $this->color ?: '#1f5eff']);
    }
}
