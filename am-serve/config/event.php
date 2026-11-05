<?php

return [
    'user.operateLog' => [
        [app\event\SystemUser::class, 'operateLog'],
    ],
    'user.loginLog' => [
        [app\event\SystemUser::class, 'loginLog'],
    ],
];
