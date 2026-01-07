<?php

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function now_utc(): string {
    return gmdate('Y-m-d H:i:s');
}

function valid_pin(string $pin, int $length): bool {
    return preg_match('/^\d{' . $length . '}$/', $pin);
}
