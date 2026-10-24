<?php

return [
    'plugin_name' => 'Modrinth Datapacks',

    'hub' => [
        'label' => 'Datapacks',
        'navigation_label' => 'Modrinth Datapacks',
    ],

    'page' => [
        'open_folder' => 'Open datapacks folder',
        'minecraft_version' => 'Minecraft Version',
        'world' => 'World',
        'installed_datapacks' => 'Installed Datapacks',
        'unknown' => 'Unknown',
        'project_unavailable' => 'This datapack could not be loaded from Modrinth',
        'uploaded_zip' => 'Uploaded by hand',
        'uploaded_folder' => 'Folder uploaded by hand',
        'empty_heading' => 'No datapacks installed',
        'empty_description' => 'Turn off "Only show installed" to search Modrinth for datapacks, or upload them to the datapacks folder of the world.',
    ],

    'table' => [
        'columns' => [
            'title' => 'Title',
            'version' => 'Version',
            'source' => 'Source',
            'author' => 'Author',
            'downloads' => 'Downloads',
            'date_modified' => 'Modified',
            'installed_at' => 'Installed',
        ],
    ],

    'sources' => [
        'modrinth' => 'Modrinth',
        'uploaded' => 'Uploaded',
        'missing' => 'File missing',
        'dependency_hint' => 'Installed from Modrinth as a dependency of :name.',
        'hints' => [
            'modrinth' => 'Installed from Modrinth, updates are offered here.',
            'uploaded' => 'Put into the datapacks folder by hand, it can\'t be updated through Modrinth.',
            'missing' => 'Listed as installed, but the file is gone from the datapacks folder.',
        ],
    ],

    'version' => [
        'type' => 'Type',
        'downloads' => 'Downloads',
        'published' => 'Published',
        'game_versions' => 'Minecraft Versions',
        'changelog' => 'Changelog',
    ],

    'actions' => [
        'install_latest' => 'Install latest version',
        'install' => 'Install',
        'installed' => 'Installed',
        'update' => 'Update',
        'uninstall' => 'Uninstall',
        'delete' => 'Delete',
        'versions' => 'Version Selection',
        'reload' => 'Reload datapacks',
    ],

    'modals' => [
        'install_heading' => 'Install Datapack',
        'install_description' => 'This will download :name version :version into the datapacks folder of the world ":world".',
        'update_heading' => 'Update Datapack',
        'update_description' => 'This will replace version :old_version with version :new_version.',
        'dependencies_hint' => 'Datapacks it requires are installed along with it.',
        'uninstall_heading' => 'Uninstall Datapack',
        'uninstall_description' => 'This permanently deletes :file of :name from the datapacks folder. What the datapack already changed in the world stays.',
        'delete_heading' => 'Delete :file',
        'delete_description' => 'This permanently deletes :file from the datapacks folder.',
        'reload_heading' => 'Reload Datapacks',
        'reload_description' => 'Sends /reload to the server console, so the running server loads its datapacks again. Datapacks that change world generation or add dimensions need a restart instead.',
        'reload_hint' => 'Datapacks take effect after /reload or a restart of the server. World generation only changes in chunks generated afterwards.',
    ],

    'notifications' => [
        'install_success' => 'Installation completed',
        'install_success_body' => 'Successfully installed :name version :version.',
        'install_failed' => 'Installation failed',
        'update_success' => 'Update completed',
        'update_success_body' => 'Successfully updated :name to version :version.',
        'update_failed' => 'Update failed',
        'uninstall_success' => 'Uninstall completed',
        'uninstall_success_body' => 'Successfully uninstalled :name.',
        'uninstall_failed' => 'Uninstall failed',
        'deleted' => 'Datapack deleted',
        'deleted_body' => ':file was deleted.',
        'delete_failed' => 'Deleting failed',
        'dependencies_installed' => 'Required datapacks installed along with it: :names.',
        'dependencies_missing' => 'Required datapacks missing',
        'dependencies_missing_body' => 'These required datapacks could not be installed and have to be added by hand: :names',
        'reload_hint' => 'Run /reload or restart the server to apply the change.',
        'reload_sent' => 'Datapacks are being reloaded',
        'reload_failed' => 'Reloading failed, is the server running?',
    ],

    'errors' => [
        'unexpected' => 'An unexpected error occurred. Please try again or contact support if the issue persists.',
        'already_running' => 'Another datapack operation is already running for this server.',
        'no_compatible_version' => 'No version compatible with this server was found.',
        'version_not_found' => 'The selected version could not be found on Modrinth.',
        'no_datapack_file' => 'The selected version of :name has no datapack file.',
        'too_large' => 'The datapack file of :name exceeds the configured size limit.',
        'invalid_download_url' => ':name points to a download location that is not allowed.',
        'invalid_path' => 'Invalid path: :path',
        'filename_taken' => ':file is already used by the installed datapack :name.',
        'download_failed' => 'Downloading :name failed: :reason',
        'daemon_unreachable' => 'The node could not be reached.',
        'size_mismatch' => 'The downloaded file of :name does not have the expected size.',
        'metadata_failed' => ':name was downloaded, but the installed datapacks could not be saved.',
        'old_file_not_removed' => 'The new version was installed, but the old file :file could not be deleted. Delete it by hand, otherwise both versions are loaded.',
        'not_installed' => 'This datapack is not installed.',
        'is_installed' => ':file belongs to an installed datapack, uninstall it instead.',
        'delete_failed' => 'Deleting :file failed: :reason',
        'uninstall_partial' => 'The file of :name was deleted, but it could not be removed from the installed datapacks. It may still appear as installed.',
    ],
];
