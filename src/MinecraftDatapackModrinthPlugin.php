<?php

namespace CedricKassen\MinecraftDatapackModrinth;

use Filament\Contracts\Plugin;
use Filament\Panel;

class MinecraftDatapackModrinthPlugin implements Plugin
{
    public function getId(): string
    {
        return 'minecraft-datapack-modrinth';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "CedricKassen\\MinecraftDatapackModrinth\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
