<?php

return [
    'max_upload_mb' => (int) env('SYSTEM_UPDATE_MAX_MB', 256),
    'sources' => [
        'official' => [
            'update_source' => 'https://dev.azure.com/blessing-skin/51010f6d-9f99-40f1-a262-0a67f788df32/_apis/git/'.
                'repositories/a9ff8df7-6dc3-4ff8-bb22-4871d3a43936/Items?path=%2Fupdate.json',
            'plugins_registry' => 'https://bs-plugins.littleservice.cn/registry_{lang}.json',
        ],
        'patch' => [
            'update_source' => 'https://github.com/DreamCraft-Tech/blessing-skin-server-patch/releases/latest/download/update.json',
            'plugins_registry' => 'https://github.com/DreamCraft-Tech/blessing-skin-plugins-patch/releases/latest/download/registry_{lang}.json',
        ],
    ],
];
