<?php

declare(strict_types=1);

namespace Qinii\WechatCore\Config;

use Qinii\WechatCore\Exception\InvalidConfigException;

final class ConfigResolver
{
    /**
     * @param array<string, array<string, mixed>> $defaultConfig
     */
    public function __construct(
        private ?ConfigProviderInterface $configProvider = null,
        private array $defaultConfig = [],
    ) {
    }

    /**
     * 获取完整账号组配置。
     *
     * 优先级：
     * Core 默认配置 < Provider 配置 < 运行时配置
     *
     * @param array<string, mixed> $runtimeConfig
     *
     * @return array<string, mixed>
     */
    public function resolve(
        string $name,
        array $runtimeConfig = []
    ): array {
        return array_replace_recursive(
            $this->getDefaultConfig($name),
            $this->getProviderConfig($name),
            $runtimeConfig,
        );
    }


    /**
     * 获取必须存在的完整账号组配置。
     *
     * @param array<string, mixed> $runtimeConfig
     *
     * @return array<string, mixed>
     */
    public function require(string $name, array $runtimeConfig = []): array
    {
        $config = $this->resolve($name, $runtimeConfig);

        if ($config === []) {
            throw new InvalidConfigException(
                "Wechat account config [{$name}] not found."
            );
        }

        return $config;
    }


    /**
     * 获取默认配置
     *
     * @return array<string, mixed>
     */
    private function getDefaultConfig(string $name): array
    {
        $config = $this->defaultConfig[$name] ?? [];

        if (! is_array($config)) {
            throw InvalidConfigException::expectedArray(
                'default config',
                $name,
                $config,
            );
        }

        return $config;
    }


    /**
     * 获取外部配置
     *
     * @return array<string, mixed>
     */
    private function getProviderConfig(string $name): array
    {
        if ($this->configProvider === null) {
            return [];
        }

        $config = $this->configProvider->get($name);

        if (! is_array($config)) {
            throw InvalidConfigException::expectedArray(
                'provider config',
                $name,
                $config,
            );
        }

        return $config;
    }
}
