<?php

declare(strict_types=1);

namespace Qinii\WechatCore\Config;

/**
 * 配置来源接口。
 *
 * name 表示账号组名称，例如 default、secondary。
 * 返回该账号组的完整配置。
 */
interface ConfigProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function get(string $name): array;
}
