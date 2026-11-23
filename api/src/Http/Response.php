<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    public function __construct(public readonly int $status, public readonly array $data, public readonly array $headers = []) {}

    public function json(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        if ($this->status !== 204) {
            echo $this->json();
        }
    }
}
