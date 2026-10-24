<?php

return [
    'plugin_name' => 'Modrinth Datapacks',

    'hub' => [
        'label' => 'Datapacks',
        'navigation_label' => 'Modrinth Datapacks',
    ],

    'page' => [
        'open_folder' => 'Datapack-Ordner öffnen',
        'minecraft_version' => 'Minecraft-Version',
        'world' => 'Welt',
        'installed_datapacks' => 'Installierte Datapacks',
        'unknown' => 'Unbekannt',
        'project_unavailable' => 'Dieses Datapack konnte nicht von Modrinth geladen werden',
        'uploaded_zip' => 'Manuell hochgeladen',
        'uploaded_folder' => 'Manuell hochgeladener Ordner',
        'empty_heading' => 'Keine Datapacks installiert',
        'empty_description' => 'Schalte „Nur installierte anzeigen“ aus, um Datapacks auf Modrinth zu suchen, oder lade sie in den Datapack-Ordner der Welt hoch.',
    ],

    'table' => [
        'columns' => [
            'title' => 'Titel',
            'version' => 'Version',
            'source' => 'Quelle',
            'author' => 'Autor',
            'downloads' => 'Downloads',
            'date_modified' => 'Geändert',
            'installed_at' => 'Installiert',
        ],
    ],

    'sources' => [
        'modrinth' => 'Modrinth',
        'uploaded' => 'Hochgeladen',
        'missing' => 'Datei fehlt',
        'dependency_hint' => 'Von Modrinth als Abhängigkeit von :name installiert.',
        'hints' => [
            'modrinth' => 'Von Modrinth installiert, Updates werden hier angeboten.',
            'uploaded' => 'Manuell in den Datapack-Ordner gelegt, kann nicht über Modrinth aktualisiert werden.',
            'missing' => 'Als installiert eingetragen, aber die Datei fehlt im Datapack-Ordner.',
        ],
    ],

    'version' => [
        'type' => 'Typ',
        'downloads' => 'Downloads',
        'published' => 'Veröffentlicht',
        'game_versions' => 'Minecraft-Versionen',
        'changelog' => 'Änderungen',
    ],

    'actions' => [
        'install_latest' => 'Neueste Version installieren',
        'install' => 'Installieren',
        'installed' => 'Installiert',
        'update' => 'Aktualisieren',
        'uninstall' => 'Deinstallieren',
        'delete' => 'Löschen',
        'versions' => 'Versionsauswahl',
        'reload' => 'Datapacks neu laden',
    ],

    'modals' => [
        'install_heading' => 'Datapack installieren',
        'install_description' => 'Lädt :name Version :version in den Datapack-Ordner der Welt „:world“ herunter.',
        'update_heading' => 'Datapack aktualisieren',
        'update_description' => 'Ersetzt Version :old_version durch Version :new_version.',
        'dependencies_hint' => 'Benötigte Datapacks werden mitinstalliert.',
        'uninstall_heading' => 'Datapack deinstallieren',
        'uninstall_description' => 'Löscht :file von :name endgültig aus dem Datapack-Ordner. Was das Datapack bereits in der Welt verändert hat, bleibt erhalten.',
        'delete_heading' => ':file löschen',
        'delete_description' => 'Löscht :file endgültig aus dem Datapack-Ordner.',
        'reload_heading' => 'Datapacks neu laden',
        'reload_description' => 'Sendet /reload an die Server-Konsole, damit der laufende Server seine Datapacks neu lädt. Datapacks, die die Weltgenerierung ändern oder Dimensionen hinzufügen, benötigen stattdessen einen Neustart.',
        'reload_hint' => 'Datapacks werden erst nach /reload oder einem Neustart des Servers aktiv. Die Weltgenerierung ändert sich nur in danach erzeugten Chunks.',
    ],

    'notifications' => [
        'install_success' => 'Installation abgeschlossen',
        'install_success_body' => ':name Version :version wurde erfolgreich installiert.',
        'install_failed' => 'Installation fehlgeschlagen',
        'update_success' => 'Update abgeschlossen',
        'update_success_body' => ':name wurde erfolgreich auf Version :version aktualisiert.',
        'update_failed' => 'Update fehlgeschlagen',
        'uninstall_success' => 'Deinstallation abgeschlossen',
        'uninstall_success_body' => ':name wurde erfolgreich deinstalliert.',
        'uninstall_failed' => 'Deinstallation fehlgeschlagen',
        'deleted' => 'Datapack gelöscht',
        'deleted_body' => ':file wurde gelöscht.',
        'delete_failed' => 'Löschen fehlgeschlagen',
        'dependencies_installed' => 'Mitinstallierte benötigte Datapacks: :names.',
        'dependencies_missing' => 'Benötigte Datapacks fehlen',
        'dependencies_missing_body' => 'Diese benötigten Datapacks konnten nicht installiert werden und müssen manuell hinzugefügt werden: :names',
        'reload_hint' => 'Führe /reload aus oder starte den Server neu, um die Änderung zu übernehmen.',
        'reload_sent' => 'Datapacks werden neu geladen',
        'reload_failed' => 'Neu laden fehlgeschlagen, läuft der Server?',
    ],

    'errors' => [
        'unexpected' => 'Ein unerwarteter Fehler ist aufgetreten. Bitte versuche es erneut oder kontaktiere den Support, falls das Problem bestehen bleibt.',
        'already_running' => 'Für diesen Server läuft bereits ein anderer Datapack-Vorgang.',
        'no_compatible_version' => 'Es wurde keine mit diesem Server kompatible Version gefunden.',
        'version_not_found' => 'Die ausgewählte Version wurde auf Modrinth nicht gefunden.',
        'no_datapack_file' => 'Die ausgewählte Version von :name enthält keine Datapack-Datei.',
        'too_large' => 'Die Datapack-Datei von :name überschreitet die konfigurierte Größenbeschränkung.',
        'invalid_download_url' => ':name verweist auf einen nicht erlaubten Download-Ort.',
        'invalid_path' => 'Ungültiger Pfad: :path',
        'filename_taken' => ':file wird bereits vom installierten Datapack :name verwendet.',
        'download_failed' => 'Herunterladen von :name fehlgeschlagen: :reason',
        'daemon_unreachable' => 'Die Node konnte nicht erreicht werden.',
        'size_mismatch' => 'Die heruntergeladene Datei von :name hat nicht die erwartete Größe.',
        'metadata_failed' => ':name wurde heruntergeladen, aber die installierten Datapacks konnten nicht gespeichert werden.',
        'old_file_not_removed' => 'Die neue Version wurde installiert, aber die alte Datei :file konnte nicht gelöscht werden. Lösche sie manuell, sonst werden beide Versionen geladen.',
        'not_installed' => 'Dieses Datapack ist nicht installiert.',
        'is_installed' => ':file gehört zu einem installierten Datapack, deinstalliere es stattdessen.',
        'delete_failed' => 'Löschen von :file fehlgeschlagen: :reason',
        'uninstall_partial' => 'Die Datei von :name wurde gelöscht, aber das Datapack konnte nicht aus den installierten Datapacks entfernt werden. Es wird eventuell weiterhin als installiert angezeigt.',
    ],
];
