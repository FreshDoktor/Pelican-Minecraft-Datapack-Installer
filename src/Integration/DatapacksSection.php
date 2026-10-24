<?php

namespace CedricKassen\MinecraftDatapackModrinth\Integration;

use App\Models\Server;
use CedricKassen\MinecraftDatapackModrinth\Livewire\DatapackBrowser;
use CedricKassen\MinecraftDatapackModrinth\ModrinthHub\ModrinthHub;
use CedricKassen\MinecraftDatapackModrinth\Services\MinecraftDatapackModrinthService;
use Filament\Actions\Action;
use Filament\Schemas\Components\Component;

/**
 * The datapacks tab of the Modrinth page, see ModrinthHub.
 */
class DatapacksSection
{
    public const HUB_PROTOCOL = ModrinthHub::PROTOCOL;

    public static function getId(): string
    {
        return 'datapacks';
    }

    public static function getSort(): int
    {
        return 30;
    }

    public static function getLabel(): string
    {
        return trans('minecraft-datapack-modrinth::strings.hub.label');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-datapack-modrinth::strings.hub.navigation_label');
    }

    public static function isAvailable(Server $server): bool
    {
        return MinecraftDatapackModrinthService::isEnabledForServer($server);
    }

    public static function getInfoComponent(Server $server, bool $installedOnly): ?Component
    {
        return DatapackBrowser::makeInfoGrid($server);
    }

    public static function getContentComponent(Server $server, bool $installedOnly): Component
    {
        return DatapackBrowser::make($server, $installedOnly);
    }

    /** @return array<Action> */
    public static function getHeaderActions(Server $server): array
    {
        return DatapackBrowser::makeHeaderActions($server);
    }

    /** @return array<string> */
    public static function getRefreshEvents(): array
    {
        return [DatapackBrowser::DATAPACKS_CHANGED_EVENT];
    }
}
