<?php

namespace App\Mail;

use App\Models\App;
use App\Support\Branding\OrganizationBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email a workflow's `notify.send` step produces, wearing the tenant's
 * brand rather than the platform's — an order confirmation from someone's shop
 * should look like it came from the shop.
 *
 * The body is plain text authored by the app builder and rendered as escaped
 * paragraphs: it is expression-resolved, so it can carry record values, and
 * anything a record holds is ultimately user input. Letting it through as HTML
 * would make every notification an injection surface.
 */
class AppNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly App $app,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        $brand = $this->app->organization?->brandbook();

        return new Content(
            view: 'mail.app-notification',
            with: [
                'title' => $this->title,
                'paragraphs' => $this->paragraphs(),
                'link' => $this->link,
                'accent' => $brand?->effectiveAccent() ?? OrganizationBrand::DEFAULT_ACCENT,
                'logo' => $brand?->logoUrl,
                'senderName' => $this->app->organization?->name ?? $this->app->name,
            ],
        );
    }

    /**
     * Split the authored body on blank lines. Keeps the template free of any
     * decision about markup, and keeps the author's paragraphing.
     *
     * @return list<string>
     */
    private function paragraphs(): array
    {
        $parts = preg_split('/\n\s*\n/', trim($this->body)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $p): bool => $p !== ''));
    }
}
