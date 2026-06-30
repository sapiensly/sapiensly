<?php

use App\Services\Workflows\InboundEmailNormalizer;

beforeEach(function () {
    $this->normalizer = new InboundEmailNormalizer;
});

it('maps a Postmark payload to the canonical shape', function () {
    $email = $this->normalizer->normalize('postmark', [
        'From' => 'ana@acme.com',
        'FromName' => 'Ana',
        'To' => 'support@us.com',
        'Subject' => 'Help',
        'TextBody' => 'hello',
        'HtmlBody' => '<p>hello</p>',
        'MessageID' => 'pm-1',
    ]);

    expect($email)->toMatchArray([
        'from' => 'ana@acme.com',
        'from_name' => 'Ana',
        'to' => 'support@us.com',
        'subject' => 'Help',
        'text' => 'hello',
        'html' => '<p>hello</p>',
        'message_id' => 'pm-1',
    ]);
});

it('maps a Mailgun payload (sender/recipient/body-plain)', function () {
    $email = $this->normalizer->normalize('mailgun', [
        'sender' => 'ana@acme.com',
        'recipient' => 'support@us.com',
        'subject' => 'Help',
        'body-plain' => 'hello',
        'Message-Id' => 'mg-1',
    ]);

    expect($email['from'])->toBe('ana@acme.com')
        ->and($email['to'])->toBe('support@us.com')
        ->and($email['text'])->toBe('hello')
        ->and($email['message_id'])->toBe('mg-1')
        ->and($email['html'])->toBeNull();
});

it('falls back to generic field-name heuristics for unknown providers', function () {
    $email = $this->normalizer->normalize('whatever', [
        'from' => 'ana@acme.com',
        'subject' => 'Help',
        'text' => 'hello',
        'id' => 'g-1',
    ]);

    expect($email['from'])->toBe('ana@acme.com')
        ->and($email['subject'])->toBe('Help')
        ->and($email['text'])->toBe('hello')
        ->and($email['message_id'])->toBe('g-1')
        ->and($email['to'])->toBeNull();
});
