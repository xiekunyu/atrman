<?php

namespace app\model\system;

use app\basic\BaseModel;

class Migration extends BaseModel
{
    public const TYPE_PLUGIN = 1;
    public const TYPE_SYSTEM = 2;
    public const STATUS_SUCCESS = 1;
    public const STATUS_FAILED = -1;

    protected $name = 'system_migration';
}
