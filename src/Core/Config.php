<?php

declare(strict_types=1);

namespace App\Core;

final class Config
{
    private function __construct(private array $values)
    {
    }

    /** Where the config file lives (override with the ASSETS_CONFIG environment variable). */
    public static function path(): string
    {
        return getenv('ASSETS_CONFIG') ?: APP_ROOT . '/config/config.php';
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public static function load(): self
    {
        $path = self::path();
        if (!is_file($path)) {
            throw new \RuntimeException(
                'Configuration not found. Open setup.php in your browser to create it.'
            );
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException('Configuration file must return an array.');
        }
        return new self($values);
    }

    /** Dot-notation lookup: $config->get('db.host', 'localhost'). */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
