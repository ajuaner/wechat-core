<?php

declare(strict_types=1);

namespace Qinii\WechatCore\Enum;

/**
 * 微信账号组中内置应用配置节点的标准名称。
 *
 * 这些常量用于统一配置键，不限制业务包引用自定义应用节点。
 */
final class ApplicationType
{
    public const OFFICIAL_ACCOUNT = 'official_account';
    public const MINI_PROGRAM = 'mini_program';
    public const APP = 'app';

    private function __construct()
    {
    }
}
