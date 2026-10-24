<?php

namespace CedricKassen\MinecraftDatapackModrinth;

use CedricKassen\MinecraftDatapackModrinth\Integration\DatapacksSection;
use CedricKassen\MinecraftDatapackModrinth\Livewire\DatapackBrowser;
use CedricKassen\MinecraftDatapackModrinth\ModrinthHub\ModrinthHub;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Livewire\Livewire;

class MinecraftDatapackModrinthPlugin implements Plugin
{
    public function getId(): string
    {
        return 'minecraft-datapack-modrinth';
    }

    public function register(Panel $panel): void
    {
        // Registered by name so Livewire finds the component again on updates, wherever it got embedded.
        Livewire::component('minecraft-datapack-modrinth.datapack-browser', DatapackBrowser::class);

        // The datapacks are a tab of the Modrinth page, hosted by this plugin unless another one already does.
        ModrinthHub::registerSection(DatapacksSection::class);
        ModrinthHub::registerPage($panel);
    }

    public function boot(Panel $panel): void
    {
        // Runs once all plugins registered their pages, so the mods page of minecraft-modrinth is known by now.
        ModrinthHub::boot($panel);
    }
}
