<?php

namespace CedricKassen\MinecraftDatapackModrinth\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use CedricKassen\MinecraftDatapackModrinth\Exceptions\DatapackException;
use Exception;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * @phpstan-type InstalledDatapack array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, updated_at?: string, author?: string, dependency_of?: string}
 * @phpstan-type InstallResult array{installed: array<int, string>, missing_dependencies: array<int, string>}
 */
class MinecraftDatapackModrinthService
{
    // Installed list, kept inside the datapacks folder so it goes away together with the world.
    public const METADATA_FILENAME = '.modrinth-datapacks.json';

    public const DEFAULT_LEVEL_NAME = 'world';

    // Modrinth lists datapacks as projects whose versions are made for this loader.
    public const LOADER = 'datapack';

    // Seconds to wait for the daemon while it downloads a file for us.
    protected const DOWNLOAD_TIMEOUT = 120;

    // Version list cache TTL (minutes); kept short so a transient empty Modrinth response can't hide updates for long.
    protected const VERSIONS_CACHE_MINUTES = 30;

    protected const EMPTY_VERSIONS_CACHE_MINUTES = 5;

    // Dependencies of dependencies are followed this deep at most.
    protected const MAX_DEPENDENCY_DEPTH = 3;

    // Seconds a single install or uninstall may hold the lock of its server.
    protected const OPERATION_LOCK_SECONDS = 600;

    protected const DEFAULT_DOWNLOAD_HOSTS = [
        'cdn.modrinth.com',
        'github.com',
        'raw.githubusercontent.com',
        'gitlab.com',
    ];

    /** @var array<int, string> level name by server id, server.properties is only read once per request */
    protected array $levelNames = [];

    public static function isEnabledForServer(Server $server): bool
    {
        $server->loadMissing('egg');

        $features = $server->egg->features ?? [];
        $tags = $server->egg->tags ?? [];

        if (in_array('modrinth_datapacks', $features)) {
            return true;
        }

        // Datapacks are part of the game itself, so every Java server loads them, whatever its loader.
        // Proxies have no worlds and Bedrock doesn't know datapacks.
        return in_array('minecraft', $tags) && !in_array('proxy', $tags) && !in_array('bedrock', $tags);
    }

    public function getMinecraftVersion(Server $server): ?string
    {
        $version = $server->variables()->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first()?->server_value;

        if (!$version || $version === 'latest') {
            return $this->getLatestMinecraftVersion();
        }

        return $version;
    }

    public function getLatestMinecraftVersion(): ?string
    {
        return cache()->remember('modrinth_datapack:latest_minecraft_version', now()->addHour(), function () {
            try {
                /** @var array<int, mixed> $versions */
                $versions = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/tag/game_version')
                    ->json();

                return collect($versions)->filter(fn ($version) => $version['version_type'] === 'release')->first()['version'] ?? null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    /**
     * Name of the world folder, as configured by `level-name` in server.properties.
     */
    public function getLevelName(Server $server): string
    {
        return $this->levelNames[$server->id] ??= $this->readLevelName($server);
    }

    protected function readLevelName(Server $server): string
    {
        try {
            $properties = app(DaemonFileRepository::class)->setServer($server)->getContent('server.properties');
        } catch (FileNotFoundException) {
            // Not started yet, the server will create the default world.
            return self::DEFAULT_LEVEL_NAME;
        } catch (Exception $exception) {
            report($exception);

            return self::DEFAULT_LEVEL_NAME;
        }

        foreach (preg_split('/\R/', $properties) ?: [] as $line) {
            if (!preg_match('/^\s*level-name\s*[=:]\s*(.*?)\s*$/', $line, $matches)) {
                continue;
            }

            // Java properties escape special characters with a backslash.
            $name = preg_replace('/\\\\(.)/', '$1', $matches[1]) ?? '';

            try {
                return $this->validateRelativePath($name);
            } catch (DatapackException) {
                return self::DEFAULT_LEVEL_NAME;
            }
        }

        return self::DEFAULT_LEVEL_NAME;
    }

    public function getDatapackFolder(Server $server): string
    {
        return $this->getLevelName($server).'/datapacks';
    }

    protected function getMetadataPath(Server $server): string
    {
        return $this->getDatapackFolder($server).'/'.self::METADATA_FILENAME;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function getProjects(Server $server, int $page = 1, ?string $search = null): array
    {
        $minecraftVersion = $this->getMinecraftVersion($server);

        $facets = [['project_type:datapack']];

        if ($minecraftVersion) {
            $facets[] = ["versions:$minecraftVersion"];
        }

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'facets' => json_encode($facets),
        ];

        $key = "modrinth_datapack:projects:$minecraftVersion:$page";

        if ($search) {
            $data['query'] = $search;

            $key .= ':'.md5($search);
        }

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::asJson()
                ->timeout(5)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/search', $data)
                ->json();
        } catch (Exception $exception) {
            report($exception);

            // not caching on purpose since caching a failure would keep the page empty for 30 minutes
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        cache()->put($key, $response, now()->addMinutes(30));

        return $response;
    }

    /**
     * Several projects at once. Projects Modrinth doesn't know (anymore), or that couldn't be
     * fetched, are missing from the result.
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array<string, mixed>> projects by id
     */
    public function getProjectsBulk(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds, 'is_string')));

        $projects = [];
        $missing = [];

        foreach ($projectIds as $projectId) {
            $cached = cache()->get("modrinth_datapack:project:$projectId");

            if (is_array($cached)) {
                $projects[$projectId] = $cached;
            } else {
                $missing[] = $projectId;
            }
        }

        foreach (array_chunk($missing, 100) as $chunk) {
            try {
                $response = Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/projects', ['ids' => json_encode($chunk, JSON_THROW_ON_ERROR)])
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                continue;
            }

            foreach (is_array($response) ? $response : [] as $project) {
                if (!is_array($project) || !isset($project['id']) || !in_array($project['id'], $chunk, true)) {
                    continue;
                }

                cache()->put("modrinth_datapack:project:{$project['id']}", $project, now()->addMinutes(30));

                $projects[$project['id']] = $project;
            }
        }

        return $projects;
    }

    protected function getVersionsCacheKey(string $projectId, ?string $minecraftVersion): string
    {
        return "modrinth_datapack:versions:$projectId:$minecraftVersion";
    }

    /** @return array<string, string> */
    protected function getVersionsQuery(?string $minecraftVersion): array
    {
        $query = ['loaders' => '["'.self::LOADER.'"]'];

        if ($minecraftVersion) {
            $query['game_versions'] = '["'.$minecraftVersion.'"]';
        }

        return $query;
    }

    /**
     * Keep the versions that actually ship a datapack, newest first.
     *
     * @param  mixed  $versions
     * @return array<int, array<string, mixed>>
     */
    protected function prepareVersions($versions): array
    {
        $versions = array_values(array_filter(
            is_array($versions) ? $versions : [],
            fn ($version) => is_array($version) && isset($version['id']) && $this->getDatapackFile(is_array($version['files'] ?? null) ? $version['files'] : []) !== null
        ));

        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

        return $versions;
    }

    /** @return array<int, array<string, mixed>> versions compatible with the server, newest first */
    public function getProjectVersions(string $projectId, Server $server): array
    {
        $minecraftVersion = $this->getMinecraftVersion($server);

        $key = $this->getVersionsCacheKey($projectId, $minecraftVersion);

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $versions = Http::asJson()
                ->timeout(5)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/project/'.rawurlencode($projectId).'/version', $this->getVersionsQuery($minecraftVersion))
                ->json();
        } catch (Exception $exception) {
            report($exception);

            // not cached on purpose since an empty list would hide the update action for 30 minutes.
            return [];
        }

        $versions = $this->prepareVersions($versions);

        cache()->put($key, $versions, now()->addMinutes(empty($versions) ? self::EMPTY_VERSIONS_CACHE_MINUTES : self::VERSIONS_CACHE_MINUTES));

        return $versions;
    }

    /**
     * The compatible versions of several projects at once, fetched in parallel. Shares its cache
     * with getProjectVersions().
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array<int, mixed>> versions by project id
     */
    public function getProjectVersionsBulk(array $projectIds, Server $server): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds, 'is_string')));

        if (empty($projectIds)) {
            return [];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);

        $results = [];
        $missing = [];

        foreach ($projectIds as $projectId) {
            $cached = cache()->get($this->getVersionsCacheKey($projectId, $minecraftVersion));

            if (is_array($cached)) {
                $results[$projectId] = $cached;
            } else {
                $missing[] = $projectId;
            }
        }

        if (empty($missing)) {
            return $results;
        }

        $query = $this->getVersionsQuery($minecraftVersion);

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $projectId) => $pool->as($projectId)
                    ->asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->get('https://api.modrinth.com/v2/project/'.rawurlencode($projectId).'/version', $query),
                $missing
            ));
        } catch (Exception $exception) {
            report($exception);

            $responses = [];
        }

        foreach ($missing as $projectId) {
            $response = $responses[$projectId] ?? null;

            // Not cached on purpose, an empty list would hide the update action for a while.
            if (!$response instanceof Response || !$response->successful()) {
                $results[$projectId] = [];

                continue;
            }

            $versions = $this->prepareVersions($response->json());

            cache()->put($this->getVersionsCacheKey($projectId, $minecraftVersion), $versions, now()->addMinutes(empty($versions) ? self::EMPTY_VERSIONS_CACHE_MINUTES : self::VERSIONS_CACHE_MINUTES));

            $results[$projectId] = $versions;
        }

        return $results;
    }

    /**
     * Fetch a single version, uncached, so an install always works with what Modrinth has right now.
     *
     * @return array<string, mixed>|null
     */
    public function getVersion(string $versionId): ?array
    {
        try {
            $version = Http::asJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/version/'.rawurlencode($versionId))
                ->json();
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        return is_array($version) ? $version : null;
    }

    /**
     * The datapack file of a version. Projects that are a mod and a datapack at once may ship a jar
     * next to it, which has no business in the datapacks folder.
     *
     * @param  array<int, mixed>  $files
     * @return array{url: string, filename: string, size?: int, primary?: bool}|null
     */
    public function getDatapackFile(array $files): ?array
    {
        $zips = array_values(array_filter($files, fn ($file) => is_array($file) && is_string($file['url'] ?? null) && is_string($file['filename'] ?? null) && str_ends_with(strtolower($file['filename']), '.zip')));

        foreach ($zips as $file) {
            if (!empty($file['primary'])) {
                return $file;
            }
        }

        return $zips[0] ?? null;
    }

    public function getMaxDatapackBytes(): int
    {
        return (int) config('minecraft-datapack-modrinth.max_datapack_size_mb', 100) * 1024 * 1024;
    }

    public function isAllowedDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $hosts = config('minecraft-datapack-modrinth.allowed_download_hosts', self::DEFAULT_DOWNLOAD_HOSTS);

        return in_array(strtolower($parts['host']), array_map('strtolower', is_array($hosts) ? $hosts : self::DEFAULT_DOWNLOAD_HOSTS), true);
    }

    /**
     * Normalise a path and make sure it stays inside the server's root.
     *
     * @throws DatapackException
     */
    public function validateRelativePath(string $path): string
    {
        $invalid = new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.invalid_path', ['path' => $path]));

        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            throw $invalid;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw $invalid;
            }

            $segments[] = $segment;
        }

        if (empty($segments)) {
            throw $invalid;
        }

        return implode('/', $segments);
    }

    /**
     * Make sure a name only refers to an entry right inside the datapacks folder.
     *
     * @throws DatapackException
     */
    public function validateFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || $filename === '..'
            || str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, "\0")
            // The installed list must not be overwritten or deleted as if it was a datapack.
            || strcasecmp($filename, self::METADATA_FILENAME) === 0) {
            throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.invalid_path', ['path' => $filename]));
        }

        return $filename;
    }

    /** @return array<int, InstalledDatapack> */
    public function getInstalledDatapacks(Server $server): array
    {
        try {
            $content = app(DaemonFileRepository::class)->setServer($server)->getContent($this->getMetadataPath($server));
            $metadata = json_decode($content, true);
        } catch (FileNotFoundException) {
            return [];
        } catch (Exception $exception) {
            report($exception);

            return [];
        }

        $installed = [];

        foreach (is_array($metadata['installed_datapacks'] ?? null) ? $metadata['installed_datapacks'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach (['project_id', 'project_slug', 'project_title', 'version_id', 'version_number', 'filename', 'installed_at'] as $key) {
                if (!is_string($entry[$key] ?? null)) {
                    continue 2;
                }
            }

            // Never trust the names on disk blindly, uninstalling deletes them.
            try {
                $this->validateFilename($entry['filename']);
            } catch (DatapackException) {
                continue;
            }

            $installed[] = $entry;
        }

        return $installed;
    }

    /**
     * Change the installed list under a lock. $change gets the raw entries and returns the
     * entries to write.
     *
     * @param  callable(array<int, mixed>): array<int, mixed>  $change
     *
     * @throws Exception
     */
    protected function updateInstalledDatapacks(Server $server, callable $change): void
    {
        Cache::lock("modrinth_datapack_metadata:{$server->id}", 10)->block(5, function () use ($server, $change) {
            $fileRepository = app(DaemonFileRepository::class)->setServer($server);
            $path = $this->getMetadataPath($server);

            // Anything but a missing file must abort, rewriting the file from scratch would lose the list.
            try {
                $content = $fileRepository->getContent($path);
            } catch (FileNotFoundException) {
                $content = null;
            }

            $metadata = $content === null ? [] : json_decode($content, true);

            if (!is_array($metadata)) {
                throw new Exception("$path is not valid JSON, leaving it alone");
            }

            $installed = $change(is_array($metadata['installed_datapacks'] ?? null) ? array_values($metadata['installed_datapacks']) : []);

            $fileRepository->putContent(
                $path,
                json_encode(['installed_datapacks' => array_values($installed)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            )->throw();

            return true;
        });
    }

    /**
     * Everything Minecraft would load from the datapacks folder: zip files and folders.
     *
     * @return array<string, array<string, mixed>> daemon directory entries by name
     *
     * @throws Exception
     */
    public function listDatapackFolder(Server $server): array
    {
        $datapacks = [];

        foreach ($this->listFolder($server, $this->getDatapackFolder($server)) as $file) {
            if (!is_array($file) || !is_string($file['name'] ?? null) || strcasecmp($file['name'], self::METADATA_FILENAME) === 0) {
                continue;
            }

            if (!empty($file['directory']) || str_ends_with(strtolower($file['name']), '.zip')) {
                $datapacks[$file['name']] = $file;
            }
        }

        return $datapacks;
    }

    /**
     * Install a version of a datapack together with the datapacks it requires. A dependency that
     * can't be installed doesn't fail the install, it is reported back instead.
     *
     * @param  array{id: string, slug: string, title: string, author?: ?string}  $project
     * @return InstallResult
     *
     * @throws Throwable
     */
    public function install(Server $server, array $project, string $versionId): array
    {
        return $this->withOperationLock($server, function () use ($server, $project, $versionId) {
            $version = $this->getVersion($versionId);

            if (!$version || ($version['project_id'] ?? null) !== $project['id'] || !in_array(self::LOADER, is_array($version['loaders'] ?? null) ? $version['loaders'] : [], true)) {
                throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.version_not_found'));
            }

            $this->installVersion($server, $project, $version, null);

            $result = ['installed' => [$project['title']], 'missing_dependencies' => []];
            $visited = [$project['id']];

            $this->installDependencies($server, $version, $project['id'], $visited, $result, 1);

            return $result;
        });
    }

    /**
     * @param  array<string, mixed>  $version
     * @param  array<int, string>  $visited  project ids that were already looked at
     * @param  InstallResult  $result
     */
    protected function installDependencies(Server $server, array $version, string $requiredBy, array &$visited, array &$result, int $depth): void
    {
        if ($depth > self::MAX_DEPENDENCY_DEPTH) {
            return;
        }

        foreach (is_array($version['dependencies'] ?? null) ? $version['dependencies'] : [] as $dependency) {
            if (!is_array($dependency) || ($dependency['dependency_type'] ?? null) !== 'required') {
                continue;
            }

            $dependencyVersion = $this->resolveDependencyVersion($server, $dependency);
            $projectId = $dependencyVersion['project_id'] ?? $dependency['project_id'] ?? null;

            if (!is_string($projectId)) {
                if (is_string($dependency['file_name'] ?? null)) {
                    $result['missing_dependencies'][] = $dependency['file_name'];
                }

                continue;
            }

            if (in_array($projectId, $visited, true)) {
                continue;
            }

            $visited[] = $projectId;

            // Whatever is installed already stays as it is, an update is up to the user.
            if (in_array($projectId, array_column($this->getInstalledDatapacks($server), 'project_id'), true)) {
                continue;
            }

            $project = $this->getProjectsBulk([$projectId])[$projectId] ?? null;
            $title = is_string($project['title'] ?? null) ? $project['title'] : $projectId;

            if (!$dependencyVersion || !is_string($project['slug'] ?? null)) {
                $result['missing_dependencies'][] = $title;

                continue;
            }

            try {
                $this->installVersion($server, ['id' => $projectId, 'slug' => $project['slug'], 'title' => $title], $dependencyVersion, $requiredBy);
            } catch (Throwable $exception) {
                if (!$exception instanceof DatapackException) {
                    report($exception);
                }

                $result['missing_dependencies'][] = $title;

                continue;
            }

            $result['installed'][] = $title;

            $this->installDependencies($server, $dependencyVersion, $requiredBy, $visited, $result, $depth + 1);
        }
    }

    /**
     * The version of a dependency to install: the pinned one if it fits the server, otherwise the
     * newest compatible one.
     *
     * @param  array<string, mixed>  $dependency
     * @return array<string, mixed>|null
     */
    protected function resolveDependencyVersion(Server $server, array $dependency): ?array
    {
        $projectId = is_string($dependency['project_id'] ?? null) ? $dependency['project_id'] : null;

        if (is_string($dependency['version_id'] ?? null)) {
            $version = $this->getVersion($dependency['version_id']);
            $minecraftVersion = $this->getMinecraftVersion($server);

            if ($version
                && in_array(self::LOADER, is_array($version['loaders'] ?? null) ? $version['loaders'] : [], true)
                && (!$minecraftVersion || in_array($minecraftVersion, is_array($version['game_versions'] ?? null) ? $version['game_versions'] : [], true))
                && $this->getDatapackFile(is_array($version['files'] ?? null) ? $version['files'] : []) !== null) {
                return $version;
            }

            $projectId ??= is_string($version['project_id'] ?? null) ? $version['project_id'] : null;
        }

        return $projectId ? ($this->getProjectVersions($projectId, $server)[0] ?? null) : null;
    }

    /**
     * Download a version into the datapacks folder and list it as installed. An older version of
     * the same project is replaced.
     *
     * @param  array{id: string, slug: string, title: string, author?: ?string}  $project
     * @param  array<string, mixed>  $version
     *
     * @throws DatapackException
     */
    protected function installVersion(Server $server, array $project, array $version, ?string $dependencyOf): void
    {
        $file = $this->getDatapackFile(is_array($version['files'] ?? null) ? $version['files'] : []);

        if (!$file) {
            throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.no_datapack_file', ['name' => $project['title']]));
        }

        $filename = $this->validateFilename($file['filename']);

        if (!$this->isAllowedDownloadUrl($file['url'])) {
            throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.invalid_download_url', ['name' => $project['title']]));
        }

        if (($file['size'] ?? 0) > $this->getMaxDatapackBytes()) {
            throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.too_large', ['name' => $project['title']]));
        }

        $folder = $this->getDatapackFolder($server);
        $installed = $this->getInstalledDatapacks($server);

        $previous = null;
        foreach ($installed as $entry) {
            if ($entry['project_id'] === $project['id']) {
                $previous = $entry;
            } elseif (strcasecmp($entry['filename'], $filename) === 0) {
                // Downloading would overwrite the file of another datapack.
                throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.filename_taken', ['file' => $filename, 'name' => $entry['project_title']]));
            }
        }

        try {
            $downloaded = $this->downloadFile($server, $file['url'], $folder, $filename);
        } catch (Throwable $exception) {
            report($exception);

            throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.download_failed', [
                'name' => $project['title'],
                'reason' => $this->describeDaemonError($exception),
            ]));
        }

        if (isset($file['size'], $downloaded['size']) && (int) $downloaded['size'] !== (int) $file['size']) {
            // Don't leave a broken pack behind for the server to choke on.
            try {
                $this->deleteFiles($server, ["$folder/$filename"]);
            } catch (Exception $exception) {
                report($exception);
            }

            throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.size_mismatch', ['name' => $project['title']]));
        }

        $now = now()->toIso8601String();

        $entry = [
            'project_id' => $project['id'],
            'project_slug' => $project['slug'],
            'project_title' => $project['title'],
            'version_id' => (string) $version['id'],
            'version_number' => (string) ($version['version_number'] ?? ''),
            'filename' => $filename,
            'installed_at' => $previous['installed_at'] ?? $now,
        ];

        if ($previous) {
            $entry['updated_at'] = $now;
        }

        $author = $project['author'] ?? $previous['author'] ?? null;
        if (is_string($author) && $author !== '') {
            $entry['author'] = $author;
        }

        $dependencyOf ??= $previous['dependency_of'] ?? null;
        if (is_string($dependencyOf)) {
            $entry['dependency_of'] = $dependencyOf;
        }

        try {
            $this->updateInstalledDatapacks($server, function (array $entries) use ($entry) {
                $entries = array_filter($entries, fn ($existing) => !is_array($existing) || ($existing['project_id'] ?? null) !== $entry['project_id']);
                $entries[] = $entry;

                return array_values($entries);
            });
        } catch (Throwable $exception) {
            report($exception);

            throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.metadata_failed', ['name' => $project['title']]));
        }

        // Under another name the old version would otherwise be loaded next to the new one.
        if ($previous && $previous['filename'] !== $filename) {
            try {
                $this->deleteFiles($server, ["$folder/{$previous['filename']}"]);
            } catch (Exception $exception) {
                report($exception);

                throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.old_file_not_removed', ['file' => $previous['filename']]));
            }
        }
    }

    /**
     * Delete an installed datapack and remove it from the installed list.
     *
     * @return InstalledDatapack the removed entry
     *
     * @throws Throwable
     */
    public function uninstall(Server $server, string $projectId): array
    {
        return $this->withOperationLock($server, function () use ($server, $projectId) {
            $entry = collect($this->getInstalledDatapacks($server))->firstWhere('project_id', $projectId);

            if (!$entry) {
                throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.not_installed'));
            }

            try {
                $this->deleteFiles($server, [$this->getDatapackFolder($server).'/'.$entry['filename']]);
            } catch (Throwable $exception) {
                report($exception);

                throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.delete_failed', [
                    'file' => $entry['filename'],
                    'reason' => $this->describeDaemonError($exception),
                ]));
            }

            try {
                $this->updateInstalledDatapacks($server, fn (array $entries) => array_values(array_filter(
                    $entries,
                    fn ($existing) => !is_array($existing) || ($existing['project_id'] ?? null) !== $projectId
                )));
            } catch (Throwable $exception) {
                report($exception);

                // The file is gone but the datapack still shows up as installed.
                throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.uninstall_partial', ['name' => $entry['project_title']]));
            }

            return $entry;
        });
    }

    /**
     * Delete a zip file or folder from the datapacks folder that isn't listed as installed.
     *
     * @throws Throwable
     */
    public function deleteUntracked(Server $server, string $filename): void
    {
        $filename = $this->validateFilename($filename);

        $this->withOperationLock($server, function () use ($server, $filename) {
            // Installed datapacks are uninstalled instead, which keeps the installed list right.
            if (in_array($filename, array_column($this->getInstalledDatapacks($server), 'filename'), true)) {
                throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.is_installed', ['file' => $filename]));
            }

            try {
                $this->deleteFiles($server, [$this->getDatapackFolder($server).'/'.$filename]);
            } catch (Throwable $exception) {
                report($exception);

                throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.delete_failed', [
                    'file' => $filename,
                    'reason' => $this->describeDaemonError($exception),
                ]));
            }

            return true;
        });
    }

    /**
     * Have the running server load its datapacks again, so changes apply without a restart.
     *
     * @throws Exception
     */
    public function reload(Server $server): void
    {
        app(DaemonFileRepository::class)
            ->setServer($server)
            ->getHttpClient()
            ->post("/api/servers/{$server->uuid}/commands", ['commands' => ['reload']])
            ->throw();
    }

    /**
     * Run $callback while no other datapack operation of the server runs.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws DatapackException
     */
    protected function withOperationLock(Server $server, callable $callback): mixed
    {
        try {
            return Cache::lock("modrinth_datapack_operation:{$server->id}", self::OPERATION_LOCK_SECONDS)->block(5, $callback);
        } catch (LockTimeoutException) {
            throw new DatapackException(trans('minecraft-datapack-modrinth::strings.errors.already_running'));
        }
    }

    /**
     * Ask the daemon to download a file and wait until it is actually on disk.
     *
     * Without `foreground` the daemon downloads in the background, so the panel gets a success
     * response before the file exists and never hears about a failed download. The explicit file
     * name matters too: otherwise the daemon derives it from the URL.
     *
     * @return array<string, mixed> the daemon's directory entry of the downloaded file
     *
     * @throws Exception
     */
    public function downloadFile(Server $server, string $url, string $folder, string $filename): array
    {
        // If the name is already taken, finding it there afterwards says nothing about whether our download replaced it.
        $nameWasTaken = $this->findFile($server, $folder, $filename) !== null;

        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->getHttpClient()
                // The daemon holds the request open for the whole download, which easily outlives
                // the timeout used for the small file operations everything else here does.
                ->timeout(max((int) config('panel.guzzle.timeout'), self::DOWNLOAD_TIMEOUT))
                ->post("/api/servers/{$server->uuid}/files/pull", [
                    'url' => $url,
                    'root' => $folder === '' ? '/' : $folder,
                    'file_name' => $filename,
                    'foreground' => true,
                ])
                ->throw();
        } catch (Exception $exception) {
            // A slow download can outlive our request while still finishing on the node, but a
            // file appearing under a name that was free before is the only proof of that we get.
            if ($nameWasTaken) {
                throw $exception;
            }

            try {
                $landed = $this->findFile($server, $folder, $filename);
            } catch (Exception) {
                throw $exception;
            }

            return $landed ?? throw $exception;
        }

        return $this->findFile($server, $folder, $filename)
            ?? throw new Exception("Daemon reported success but $folder/$filename is missing after downloading $url");
    }

    /**
     * Turn a failed daemon call into something a user can act on, e.g. the daemon's own
     * "not enough disk space" instead of a bare exception.
     */
    public function describeDaemonError(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            $error = $exception->response->json('error') ?? $exception->response->json('errors.0.detail');

            return trim("HTTP {$exception->response->status()}: ".(is_string($error) ? $error : $exception->response->reason()));
        }

        if ($exception instanceof ConnectionException) {
            return trans('minecraft-datapack-modrinth::strings.errors.daemon_unreachable');
        }

        return $exception->getMessage();
    }

    /**
     * @param  array<int, string>  $paths  paths relative to the server root
     *
     * @throws Exception
     */
    public function deleteFiles(Server $server, array $paths): void
    {
        foreach (array_chunk(array_values($paths), 100) as $chunk) {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->deleteFiles('/', $chunk)
                ->throw();
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws Exception
     */
    public function findFile(Server $server, string $folder, string $filename): ?array
    {
        foreach ($this->listFolder($server, $folder) as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $filename) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws Exception
     */
    public function listFolder(Server $server, string $folder): array
    {
        try {
            $files = app(DaemonFileRepository::class)->setServer($server)->getDirectory($folder === '' ? '/' : $folder);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                // The folder simply doesn't exist yet.
                return [];
            }

            throw $exception;
        }

        if (isset($files['error'])) {
            throw new Exception("Daemon returned an error while listing $folder: ".(is_string($files['error']) ? $files['error'] : 'unknown error'));
        }

        return $files;
    }
}
