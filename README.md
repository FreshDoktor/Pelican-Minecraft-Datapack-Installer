# Minecraft Datapack Modrinth

A [Pelican Panel](https://pelican.dev) plugin to install, update and manage Minecraft datapacks from [Modrinth](https://modrinth.com) directly within the server panel.

The sibling of [Minecraft Modpack Modrinth](https://github.com/FreshDoktor/Pelican-Modrinth-Modpack-Installer), built the same way.

## Features

- **Browse and Search**: Search Modrinth's datapacks compatible with your server's Minecraft version
- **Right world, right folder**: Installs into `<level-name>/datapacks`, the world is read from `server.properties`
- **Dependencies**: Datapacks a datapack requires are installed along with it
- **Updates**: Installed datapacks show when a newer compatible version is out, the old file is replaced
- **Everything in the folder**: The installed list also shows the zip files and folders that were uploaded by hand, they can be deleted from there
- **Reload**: Send `/reload` to the running server right from the page
- **Safe by Default**: Only downloads from allowed hosts and only ever touches entries right inside the datapacks folder
- **One Modrinth page**: Shares one navigation entry with the mods (Minecraft Modrinth) and modpacks (Minecraft Modpack Modrinth), as far as these plugins are enabled
- **Multilingual**: Supports English and German translations

## Requirements

- Pelican Panel with plugin support
- A Minecraft (Java) egg
- Optional: the [Minecraft Modrinth](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth) and [Minecraft Modpack Modrinth](https://github.com/FreshDoktor/Pelican-Modrinth-Modpack-Installer) plugins, and [Minecraft Mods Addon](https://github.com/FreshDoktor/Pelican-Modrinth-Mods-Addon)

Datapacks are part of the game itself, so the loader doesn't matter: Vanilla, Paper, Fabric, Forge and so on all load them.

## Installation

Clone the repository into the `plugins` folder of your panel. The folder has to be named like the plugin id:

```bash
cd /var/www/pelican/plugins
git clone https://github.com/FreshDoktor/Pelican-Minecraft-Datapack-Installer.git minecraft-datapack-modrinth
```

Then install and enable the plugin in the admin area of the panel under _Plugins_.

## Setup

The datapacks show up for every server whose egg has the `minecraft` _tag_, unless it is tagged `proxy` or `bedrock` as well. Eggs without the tag can enable it with the `modrinth_datapacks` _feature_.

Datapacks are listed for the server's `MINECRAFT_VERSION` (or `MC_VERSION`), the latest release if it is missing or set to `latest`.

### The Modrinth page

Datapacks are shown on a page that combines everything from Modrinth in one navigation entry, with a tab each for _Mods_, _Modpacks_ and _Datapacks_ and an _Only show installed_ toggle below the tabs:

| Plugins enabled | Tabs | Navigation entry |
| --- | --- | --- |
| Only this one | Datapacks | Modrinth Datapacks |
| With [Minecraft Modrinth](https://github.com/pelican-dev/plugins/tree/main/minecraft-modrinth) | Mods, Datapacks | Modrinth |
| With [Minecraft Modpack Modrinth](https://github.com/FreshDoktor/Pelican-Modrinth-Modpack-Installer) | Modpacks, Datapacks | Modrinth |
| All three | Mods, Modpacks, Datapacks | Modrinth |

Only the tabs that fit the server are shown, a server with just one of them names the entry after it. If Minecraft Modrinth is enabled, its "Minecraft Mods" page leaves the navigation, its mods are the _Mods_ tab now. Minecraft Modrinth itself is not modified and can be updated as usual.

With [Minecraft Mods Addon](https://github.com/FreshDoktor/Pelican-Modrinth-Mods-Addon) enabled too, the _Mods_ tab with _Only show installed_ turned on lists every mod on the server, including the ones uploaded by hand.

None of the plugins depends on another. This plugin, Minecraft Modpack Modrinth and Minecraft Mods Addon each ship the page (`src/ModrinthHub`), the first one enabled hosts it and the others add their tabs. Keep the copies in sync when changing it.

### Installing

Unlike modpacks, a datapack is a single small file, so installing runs right away, no queue worker is needed.

Datapacks take effect after `/reload` or a restart. Datapacks that change world generation (e.g. Terralith) only affect chunks generated afterwards, install them before the world is created to get the full effect.

## Permissions

| Action | Permission |
| --- | --- |
| Install | `file.create` |
| Update | `file.update` |
| Uninstall, delete | `file.delete` |
| Reload datapacks | `control.console` |

## Configuration

| Env | Default | Description |
| --- | --- | --- |
| `MODRINTH_DATAPACK_MAX_SIZE_MB` | `100` | Largest datapack that will be installed |

Installed datapacks are kept in `.modrinth-datapacks.json` inside the datapacks folder of the world, so they go away together with the world. Minecraft ignores the file.

## License

Licensed under the [GNU General Public License v3.0](LICENSE).
