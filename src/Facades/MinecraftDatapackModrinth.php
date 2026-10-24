<?php

namespace CedricKassen\MinecraftDatapackModrinth\Facades;

use App\Models\Server;
use CedricKassen\MinecraftDatapackModrinth\Services\MinecraftDatapackModrinthService;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @phpstan-import-type InstalledDatapack from MinecraftDatapackModrinthService
 * @phpstan-import-type InstallResult from MinecraftDatapackModrinthService
 *
 * @method static ?string getMinecraftVersion(Server $server)
 * @method static ?string getLatestMinecraftVersion()
 * @method static string getLevelName(Server $server)
 * @method static string getDatapackFolder(Server $server)
 * @method static array{hits: array<int, array<string, mixed>>, total_hits: int} getProjects(Server $server, int $page = 1, ?string $search = null)
 * @method static array<string, array<string, mixed>> getProjectsBulk(array<int, string> $projectIds)
 * @method static array<int, array<string, mixed>> getProjectVersions(string $projectId, Server $server)
 * @method static array<string, array<int, mixed>> getProjectVersionsBulk(array<int, string> $projectIds, Server $server)
 * @method static array<string, mixed>|null getVersion(string $versionId)
 * @method static array{url: string, filename: string, size?: int, primary?: bool}|null getDatapackFile(array<int, mixed> $files)
 * @method static array<int, InstalledDatapack> getInstalledDatapacks(Server $server)
 * @method static array<string, array<string, mixed>> listDatapackFolder(Server $server)
 * @method static InstallResult install(Server $server, array{id: string, slug: string, title: string, author?: ?string} $project, string $versionId)
 * @method static InstalledDatapack uninstall(Server $server, string $projectId)
 * @method static void deleteUntracked(Server $server, string $filename)
 * @method static void reload(Server $server)
 * @method static string describeDaemonError(Throwable $exception)
 *
 * @see MinecraftDatapackModrinthService
 */
class MinecraftDatapackModrinth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MinecraftDatapackModrinthService::class;
    }
}
