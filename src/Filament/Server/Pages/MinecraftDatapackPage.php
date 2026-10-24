<?php

namespace CedricKassen\MinecraftDatapackModrinth\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use CedricKassen\MinecraftDatapackModrinth\Exceptions\DatapackException;
use CedricKassen\MinecraftDatapackModrinth\Facades\MinecraftDatapackModrinth;
use CedricKassen\MinecraftDatapackModrinth\Services\MinecraftDatapackModrinthService;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * Browse the datapacks on Modrinth and manage the ones in the datapacks folder of the server's world.
 *
 * @phpstan-import-type InstalledDatapack from MinecraftDatapackModrinthService
 */
class MinecraftDatapackPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs;
    use InteractsWithTable;

    public const TAB_ALL = 'all';

    public const TAB_INSTALLED = 'installed';

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'modrinth_datapacks';

    protected static ?int $navigationSort = 32;

    /** @var array<int, InstalledDatapack>|null */
    protected ?array $installedDatapacks = null;

    /** @var array<string, array<string, mixed>>|null datapacks folder by name, null if it couldn't be read */
    protected ?array $folderListing = null;

    protected bool $folderListingLoaded = false;

    /** @var array<string, array<int, mixed>> Cache for version data by project_id */
    protected array $versionsCache = [];

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && MinecraftDatapackModrinthService::isEnabledForServer($server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-datapack-modrinth::strings.minecraft_datapacks');
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            self::TAB_ALL => Tab::make(trans('minecraft-datapack-modrinth::strings.page.view_all')),
            self::TAB_INSTALLED => Tab::make(trans('minecraft-datapack-modrinth::strings.page.view_installed')),
        ];
    }

    protected function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    protected function isInstalledTab(): bool
    {
        return $this->activeTab === self::TAB_INSTALLED;
    }

    protected function userCan(string $permission): bool
    {
        return user()?->can($permission, $this->getServer()) ?? false;
    }

    /** @return array<int, InstalledDatapack> */
    protected function getInstalledDatapacks(): array
    {
        return $this->installedDatapacks ??= MinecraftDatapackModrinth::getInstalledDatapacks($this->getServer());
    }

    /** @return InstalledDatapack|null */
    protected function getInstalledEntry(?string $projectId): ?array
    {
        if ($projectId === null) {
            return null;
        }

        foreach ($this->getInstalledDatapacks() as $entry) {
            if ($entry['project_id'] === $projectId) {
                return $entry;
            }
        }

        return null;
    }

    protected function isInstalled(?string $projectId): bool
    {
        return $this->getInstalledEntry($projectId) !== null;
    }

    /** @return array<string, array<string, mixed>>|null */
    protected function getFolderListing(): ?array
    {
        if (!$this->folderListingLoaded) {
            $this->folderListingLoaded = true;

            try {
                $this->folderListing = MinecraftDatapackModrinth::listDatapackFolder($this->getServer());
            } catch (Exception $exception) {
                report($exception);

                $this->folderListing = null;
            }
        }

        return $this->folderListing;
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId): array
    {
        if (!array_key_exists($projectId, $this->versionsCache)) {
            // The installed tab asks for every installed datapack, fetch them all at once instead of one after another.
            $projectIds = $this->isInstalledTab() ? array_column($this->getInstalledDatapacks(), 'project_id') : [];
            $projectIds[] = $projectId;

            $this->versionsCache += MinecraftDatapackModrinth::getProjectVersionsBulk($projectIds, $this->getServer());
        }

        return $this->versionsCache[$projectId] ?? [];
    }

    /** @param  array<string, mixed>  $record */
    protected function hasUpdate(array $record): bool
    {
        $installed = $this->getInstalledEntry($record['project_id'] ?? null);

        if (!$installed) {
            return false;
        }

        $versions = $this->getCachedVersions($installed['project_id']);

        return !empty($versions) && $installed['version_id'] !== $versions[0]['id'];
    }

    /** @param  array<string, mixed>  $record */
    protected function isModrinthRecord(array $record): bool
    {
        return !empty($record['project_id']) && empty($record['unavailable']);
    }

    /**
     * Drop everything read from the server so the next render reflects what is actually on disk.
     */
    protected function forgetInstalledState(): void
    {
        $this->installedDatapacks = null;
        $this->folderListing = null;
        $this->folderListingLoaded = false;
        $this->versionsCache = [];
    }

    /**
     * Rows of the installed tab: every datapack from the installed list, plus the zip files and
     * folders in the datapacks folder that were put there by hand.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function getInstalledRows(): array
    {
        $installed = $this->getInstalledDatapacks();
        $listing = $this->getFolderListing();

        $projects = MinecraftDatapackModrinth::getProjectsBulk(array_column($installed, 'project_id'));
        $titles = array_column($installed, 'project_title', 'project_id');

        $rows = [];

        foreach ($installed as $entry) {
            $project = $projects[$entry['project_id']] ?? null;

            $rows[] = [
                'project_id' => $entry['project_id'],
                'slug' => $project['slug'] ?? $entry['project_slug'],
                'title' => $project['title'] ?? $entry['project_title'],
                'description' => $project['description'] ?? trans('minecraft-datapack-modrinth::strings.page.project_unavailable'),
                'icon_url' => $project['icon_url'] ?? null,
                'author' => $entry['author'] ?? null,
                'date_modified' => $entry['updated_at'] ?? $entry['installed_at'],
                'filename' => $entry['filename'],
                'version_number' => $entry['version_number'],
                // Without a listing there is no telling whether the file is gone.
                'source' => $listing === null || isset($listing[$entry['filename']]) ? 'modrinth' : 'missing',
                'dependency_of' => isset($entry['dependency_of']) ? ($titles[$entry['dependency_of']] ?? null) : null,
                'unavailable' => $project === null,
            ];
        }

        $tracked = array_column($installed, 'filename');

        foreach ($listing ?? [] as $name => $file) {
            $name = (string) $name;

            if (in_array($name, $tracked, true)) {
                continue;
            }

            $rows[] = [
                'project_id' => null,
                'slug' => null,
                'title' => $name,
                'description' => trans(!empty($file['directory'])
                    ? 'minecraft-datapack-modrinth::strings.page.uploaded_folder'
                    : 'minecraft-datapack-modrinth::strings.page.uploaded_zip'),
                'icon_url' => null,
                'author' => null,
                'date_modified' => is_string($file['modified'] ?? null) ? $file['modified'] : null,
                'filename' => $name,
                'version_number' => null,
                'source' => 'uploaded',
                'dependency_of' => null,
                'unavailable' => true,
            ];
        }

        usort($rows, fn (array $a, array $b) => strcasecmp($a['title'], $b['title']));

        return $rows;
    }

    /** @param  array<string, mixed>  $record */
    protected function getSourceHint(array $record): string
    {
        if (($record['source'] ?? null) === 'modrinth' && !empty($record['dependency_of'])) {
            return trans('minecraft-datapack-modrinth::strings.sources.dependency_hint', ['name' => $record['dependency_of']]);
        }

        return trans('minecraft-datapack-modrinth::strings.sources.hints.'.($record['source'] ?? 'uploaded'));
    }

    protected function withReloadHint(string $description): HtmlString
    {
        return new HtmlString(e($description).'<br><br><strong>'.e(trans('minecraft-datapack-modrinth::strings.modals.reload_hint')).'</strong>');
    }

    /**
     * Describe what installing $record will do.
     *
     * @param  array<string, mixed>  $record
     */
    protected function getInstallDescription(array $record, string $versionNumber): HtmlString
    {
        $installed = $this->getInstalledEntry($record['project_id']);

        $description = $installed
            ? trans('minecraft-datapack-modrinth::strings.modals.update_description', [
                'old_version' => $installed['version_number'],
                'new_version' => $versionNumber,
            ])
            : trans('minecraft-datapack-modrinth::strings.modals.install_description', [
                'name' => $record['title'],
                'version' => $versionNumber,
                'world' => MinecraftDatapackModrinth::getLevelName($this->getServer()),
            ]);

        return $this->withReloadHint($description.' '.trans('minecraft-datapack-modrinth::strings.modals.dependencies_hint'));
    }

    protected function notifyFailure(string $title, Throwable $exception): void
    {
        if (!$exception instanceof DatapackException) {
            report($exception);
        }

        Notification::make()
            ->title($title)
            ->body($exception instanceof DatapackException ? $exception->getMessage() : trans('minecraft-datapack-modrinth::strings.errors.unexpected'))
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     */
    protected function installVersion(array $record, array $versionData): void
    {
        $operation = $this->isInstalled($record['project_id']) ? 'update' : 'install';

        try {
            $result = MinecraftDatapackModrinth::install($this->getServer(), [
                'id' => $record['project_id'],
                'slug' => $record['slug'],
                'title' => $record['title'],
                'author' => $record['author'] ?? null,
            ], $versionData['id']);
        } catch (Throwable $exception) {
            $this->notifyFailure(trans("minecraft-datapack-modrinth::strings.notifications.{$operation}_failed"), $exception);

            return;
        } finally {
            $this->forgetInstalledState();
        }

        $body = trans("minecraft-datapack-modrinth::strings.notifications.{$operation}_success_body", [
            'name' => $record['title'],
            'version' => $versionData['version_number'] ?? '',
        ]);

        $dependencies = array_slice($result['installed'], 1);

        if (!empty($dependencies)) {
            $body .= ' '.trans('minecraft-datapack-modrinth::strings.notifications.dependencies_installed', ['names' => implode(', ', $dependencies)]);
        }

        Notification::make()
            ->title(trans("minecraft-datapack-modrinth::strings.notifications.{$operation}_success"))
            ->body($body.' '.trans('minecraft-datapack-modrinth::strings.notifications.reload_hint'))
            ->success()
            ->send();

        if (!empty($result['missing_dependencies'])) {
            Notification::make()
                ->title(trans('minecraft-datapack-modrinth::strings.notifications.dependencies_missing'))
                ->body(trans('minecraft-datapack-modrinth::strings.notifications.dependencies_missing_body', ['names' => implode(', ', $result['missing_dependencies'])]))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function installLatest(array $record, string $operation): void
    {
        $versions = $this->getCachedVersions($record['project_id']);

        if (empty($versions)) {
            Notification::make()
                ->title(trans("minecraft-datapack-modrinth::strings.notifications.{$operation}_failed"))
                ->body(trans('minecraft-datapack-modrinth::strings.errors.no_compatible_version'))
                ->danger()
                ->send();

            return;
        }

        $this->installVersion($record, $versions[0]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reload')
                ->label(trans('minecraft-datapack-modrinth::strings.actions.reload'))
                ->icon('tabler-reload')
                ->color('gray')
                ->visible(fn () => $this->userCan('control.console'))
                ->requiresConfirmation()
                ->modalHeading(trans('minecraft-datapack-modrinth::strings.modals.reload_heading'))
                ->modalDescription(trans('minecraft-datapack-modrinth::strings.modals.reload_description'))
                ->action(function () {
                    try {
                        MinecraftDatapackModrinth::reload($this->getServer());
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title(trans('minecraft-datapack-modrinth::strings.notifications.reload_failed'))
                            ->body(MinecraftDatapackModrinth::describeDaemonError($exception))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(trans('minecraft-datapack-modrinth::strings.notifications.reload_sent'))
                        ->success()
                        ->send();
                }),
            Action::make('open_folder')
                ->tooltip(trans('minecraft-datapack-modrinth::strings.page.open_folder'))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => MinecraftDatapackModrinth::getDatapackFolder($this->getServer())]), true),
        ];
    }

    /**
     * Minecraft version, world and number of installed datapacks.
     */
    protected function getInfoGrid(): Grid
    {
        $server = $this->getServer();

        return Grid::make(3)
            ->schema([
                TextEntry::make('minecraft_version')
                    ->label(trans('minecraft-datapack-modrinth::strings.page.minecraft_version'))
                    ->state(fn () => MinecraftDatapackModrinth::getMinecraftVersion($server) ?? trans('minecraft-datapack-modrinth::strings.page.unknown'))
                    ->badge(),
                TextEntry::make('world')
                    ->label(trans('minecraft-datapack-modrinth::strings.page.world'))
                    ->state(fn () => MinecraftDatapackModrinth::getLevelName($server))
                    ->icon('tabler-world')
                    ->badge(),
                TextEntry::make('installed_datapacks')
                    ->label(trans('minecraft-datapack-modrinth::strings.page.installed_datapacks'))
                    ->state(fn () => count($this->getInstalledDatapacks()))
                    ->badge(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getInfoGrid(),
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page) {
                if ($this->isInstalledTab()) {
                    $rows = $this->getInstalledRows();

                    if ($search) {
                        $needle = mb_strtolower($search);

                        $rows = array_values(array_filter($rows, fn (array $row) => str_contains(mb_strtolower($row['title']), $needle) || str_contains(mb_strtolower($row['filename']), $needle)));
                    }

                    return new LengthAwarePaginator(array_slice($rows, ($page - 1) * 20, 20), count($rows), 20, $page);
                }

                $response = MinecraftDatapackModrinth::getProjects($this->getServer(), $page, $search);

                return new LengthAwarePaginator($response['hits'], $response['total_hits'], 20, $page);
            })
            ->paginated([20])
            ->emptyStateHeading(fn () => $this->isInstalledTab() ? trans('minecraft-datapack-modrinth::strings.page.empty_heading') : null)
            ->emptyStateDescription(fn () => $this->isInstalledTab() ? trans('minecraft-datapack-modrinth::strings.page.empty_description') : null)
            ->columns([
                ImageColumn::make('icon_url')
                    ->label(''),
                TextColumn::make('title')
                    ->label(trans('minecraft-datapack-modrinth::strings.table.columns.title'))
                    ->searchable()
                    ->description(fn (array $record) => Str::limit($record['description'] ?? '', 120)),
                TextColumn::make('version_number')
                    ->label(trans('minecraft-datapack-modrinth::strings.table.columns.version'))
                    ->placeholder('-')
                    ->visible(fn () => $this->isInstalledTab()),
                TextColumn::make('source')
                    ->label(trans('minecraft-datapack-modrinth::strings.table.columns.source'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => trans("minecraft-datapack-modrinth::strings.sources.$state"))
                    ->color(fn ($state) => match ($state) {
                        'modrinth' => 'success',
                        'missing' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (array $record) => $this->getSourceHint($record))
                    ->visible(fn () => $this->isInstalledTab()),
                TextColumn::make('author')
                    ->label(trans('minecraft-datapack-modrinth::strings.table.columns.author'))
                    ->url(fn ($state) => $state ? "https://modrinth.com/user/$state" : null, true)
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->label(trans('minecraft-datapack-modrinth::strings.table.columns.downloads'))
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable()
                    ->visible(fn () => !$this->isInstalledTab()),
                TextColumn::make('date_modified')
                    ->label(fn () => trans($this->isInstalledTab()
                        ? 'minecraft-datapack-modrinth::strings.table.columns.installed_at'
                        : 'minecraft-datapack-modrinth::strings.table.columns.date_modified'))
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->toggleable(),
            ])
            ->recordUrl(function (array $record) {
                if (empty($record['slug']) || !empty($record['unavailable'])) {
                    return null;
                }

                return "https://modrinth.com/datapack/{$record['slug']}";
            }, true)
            ->recordActions([
                Action::make('versions')
                    ->iconButton()
                    ->icon('tabler-list')
                    ->color('info')
                    ->tooltip(trans('minecraft-datapack-modrinth::strings.actions.versions'))
                    ->visible(fn (array $record) => $this->isModrinthRecord($record))
                    ->modalSubmitAction(false)
                    ->schema(fn (array $record) => $this->getVersionSections($record)),
                Action::make('install_latest')
                    ->iconButton()
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(trans('minecraft-datapack-modrinth::strings.actions.install_latest'))
                    ->visible(fn (array $record) => $this->isModrinthRecord($record) && !$this->isInstalled($record['project_id']) && $this->userCan('file.create'))
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-datapack-modrinth::strings.modals.install_heading'))
                    ->modalDescription(fn (array $record) => $this->getInstallDescription($record, $this->getCachedVersions($record['project_id'])[0]['version_number'] ?? trans('minecraft-datapack-modrinth::strings.page.unknown')))
                    ->action(fn (array $record) => $this->installLatest($record, 'install')),
                Action::make('update')
                    ->iconButton()
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('minecraft-datapack-modrinth::strings.actions.update'))
                    ->visible(fn (array $record) => $this->hasUpdate($record) && $this->userCan('file.update'))
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-datapack-modrinth::strings.modals.update_heading'))
                    ->modalDescription(fn (array $record) => $this->getInstallDescription($record, $this->getCachedVersions($record['project_id'])[0]['version_number'] ?? trans('minecraft-datapack-modrinth::strings.page.unknown')))
                    ->action(fn (array $record) => $this->installLatest($record, 'update')),
                Action::make('installed')
                    ->iconButton()
                    ->icon('tabler-check')
                    ->color('success')
                    ->tooltip(trans('minecraft-datapack-modrinth::strings.actions.installed'))
                    ->disabled()
                    ->visible(fn (array $record) => $this->isInstalled($record['project_id'] ?? null) && !$this->hasUpdate($record)),
                Action::make('uninstall')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('minecraft-datapack-modrinth::strings.actions.uninstall'))
                    ->visible(fn (array $record) => $this->isInstalled($record['project_id'] ?? null) && $this->userCan('file.delete'))
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-datapack-modrinth::strings.modals.uninstall_heading'))
                    ->modalDescription(fn (array $record) => $this->withReloadHint(trans('minecraft-datapack-modrinth::strings.modals.uninstall_description', [
                        'name' => $record['title'],
                        'file' => $this->getInstalledEntry($record['project_id'])['filename'] ?? '',
                    ])))
                    ->action(function (array $record) {
                        try {
                            MinecraftDatapackModrinth::uninstall($this->getServer(), $record['project_id']);
                        } catch (Throwable $exception) {
                            $this->notifyFailure(trans('minecraft-datapack-modrinth::strings.notifications.uninstall_failed'), $exception);

                            return;
                        } finally {
                            $this->forgetInstalledState();
                        }

                        Notification::make()
                            ->title(trans('minecraft-datapack-modrinth::strings.notifications.uninstall_success'))
                            ->body(trans('minecraft-datapack-modrinth::strings.notifications.uninstall_success_body', ['name' => $record['title']]).' '.trans('minecraft-datapack-modrinth::strings.notifications.reload_hint'))
                            ->success()
                            ->send();
                    }),
                Action::make('delete')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('minecraft-datapack-modrinth::strings.actions.delete'))
                    ->visible(fn (array $record) => ($record['source'] ?? null) === 'uploaded' && $this->userCan('file.delete'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('minecraft-datapack-modrinth::strings.modals.delete_heading', ['file' => $record['filename']]))
                    ->modalDescription(fn (array $record) => $this->withReloadHint(trans('minecraft-datapack-modrinth::strings.modals.delete_description', ['file' => $record['filename']])))
                    ->action(function (array $record) {
                        try {
                            MinecraftDatapackModrinth::deleteUntracked($this->getServer(), $record['filename']);
                        } catch (Throwable $exception) {
                            $this->notifyFailure(trans('minecraft-datapack-modrinth::strings.notifications.delete_failed'), $exception);

                            return;
                        } finally {
                            $this->forgetInstalledState();
                        }

                        Notification::make()
                            ->title(trans('minecraft-datapack-modrinth::strings.notifications.deleted'))
                            ->body(trans('minecraft-datapack-modrinth::strings.notifications.deleted_body', ['file' => $record['filename']]))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, Section>
     */
    protected function getVersionSections(array $record): array
    {
        $versions = $this->getCachedVersions($record['project_id']);
        $installedVersionId = $this->getInstalledEntry($record['project_id'])['version_id'] ?? null;
        $canInstall = $this->userCan($installedVersionId ? 'file.update' : 'file.create');

        $sections = [];
        foreach ($versions as $versionIndex => $versionData) {
            $sectionComponents = [
                TextEntry::make('type_'.$versionIndex)
                    ->label(trans('minecraft-datapack-modrinth::strings.version.type'))
                    ->state($versionData['version_type'] ?? '')
                    ->badge()
                    ->color(match ($versionData['version_type'] ?? '') {
                        'release' => 'success',
                        'beta' => 'warning',
                        'alpha' => 'danger',
                        default => 'gray',
                    }),
                TextEntry::make('downloads_'.$versionIndex)
                    ->label(trans('minecraft-datapack-modrinth::strings.version.downloads'))
                    ->state($versionData['downloads'] ?? 0)
                    ->icon('tabler-download')
                    ->numeric(),
                TextEntry::make('published_'.$versionIndex)
                    ->label(trans('minecraft-datapack-modrinth::strings.version.published'))
                    ->state(fn () => isset($versionData['date_published']) ? Carbon::parse($versionData['date_published'], 'UTC')->diffForHumans() : ''),
                TextEntry::make('game_versions_'.$versionIndex)
                    ->label(trans('minecraft-datapack-modrinth::strings.version.game_versions'))
                    ->state(implode(', ', is_array($versionData['game_versions'] ?? null) ? $versionData['game_versions'] : [])),
            ];

            if (!empty($versionData['changelog'])) {
                $sectionComponents[] = TextEntry::make('changelog_'.$versionIndex)
                    ->label(trans('minecraft-datapack-modrinth::strings.version.changelog'))
                    ->state($versionData['changelog'])
                    ->markdown();
            }

            if (($versionData['id'] ?? null) === $installedVersionId) {
                $headerAction = Action::make('installed_'.$versionIndex)
                    ->label(trans('minecraft-datapack-modrinth::strings.actions.installed'))
                    ->icon('tabler-check')
                    ->color('success')
                    ->disabled();
                $sectionIcon = 'tabler-check';
            } else {
                $headerAction = Action::make('install_version_'.$versionIndex)
                    ->label(trans('minecraft-datapack-modrinth::strings.actions.install'))
                    ->icon('tabler-download')
                    ->visible($canInstall)
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-datapack-modrinth::strings.modals.install_heading'))
                    ->modalDescription(fn () => $this->getInstallDescription($record, $versionData['version_number'] ?? ''))
                    ->action(fn () => $this->installVersion($record, $versionData));
                $sectionIcon = null;
            }

            $section = Section::make($versionData['version_number'] ?? '')
                ->headerActions([$headerAction])
                ->schema($sectionComponents)
                ->collapsible()
                ->collapsed(!($versionData['featured'] ?? false));

            if ($sectionIcon !== null) {
                $section = $section->icon($sectionIcon)->iconColor('success');
            }

            $sections[] = $section;
        }

        return $sections;
    }
}
