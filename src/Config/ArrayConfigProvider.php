<?php

declare(strict_types=1);

namespace Qinii\WechatCore\Config;

use Qinii\WechatCore\Exception\InvalidConfigException;

/**
 * 从普通数组中读取账号组配置。
 */
final class ArrayConfigProvider implements ConfigProviderInterface
{
    /**
     * @param array<string, array<string, mixed>> $configs
     */
    public function __construct(
        private array $configs = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $name): array
    {
        $config = $this->configs[$name] ?? [];

        if (! is_array($config)) {
            throw InvalidConfigException::expectedArray(
                'account config',
                $name,
                $config,
            );
        }

        return $config;
    }
}
