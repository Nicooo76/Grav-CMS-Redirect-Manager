<?php

declare(strict_types=1);

namespace Grav\Plugin\Email {
    if (!class_exists(Message::class, false)) {
        /** Fluent mail message that records what the plugin sets. */
        class Message
        {
            public string $from = '';
            public string $to = '';
            public string $html = '';

            public function __construct(public string $subject, public string $body, public string $contentType)
            {
            }

            public function from(string $address): static
            {
                $this->from = $address;

                return $this;
            }

            public function to(string $address): static
            {
                $this->to = $address;

                return $this;
            }

            public function html(string $html): static
            {
                $this->html = $html;

                return $this;
            }
        }
    }

    if (!class_exists(Email::class, false)) {
        /** Fake of the email plugin's service: message() builds, send() records and returns the configured count. */
        class Email
        {
            /** @var list<Message> */
            public array $sent = [];

            public function __construct(public int $result = 1)
            {
            }

            public function message(string $subject, string $body, string $contentType): Message
            {
                return new Message($subject, $body, $contentType);
            }

            public function send(Message $message): int
            {
                $this->sent[] = $message;

                return $this->result;
            }
        }
    }
}
